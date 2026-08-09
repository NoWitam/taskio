<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Enums\KnowledgeIndexStatus;
use App\Modules\Knowledge\Jobs\IndexKnowledgeEntryJob;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Variables\Models\AiUsageEvent;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * Differential indexing end to end (B2a): chunk, embed once, re-embed only what changed, and record
 * the honest state when the budget or the provider gets in the way.
 *
 * The assertions are about SPEND above all else. `$embedder->calls` is the only externally observable
 * measure of what an indexing run cost, and every one of the design's claims — "a whole entry costs
 * one call", "an unchanged save costs nothing", "an edit costs one call, not twenty-five" — is a claim
 * about that counter. A test suite that only checked that chunks appeared would pass against an
 * implementation that re-embedded the entire base on every keystroke.
 *
 * No test here can reach a provider: the base TestCase binds {@see FakeKnowledgeEmbedder} for every
 * test in the suite, and these re-bind their own instance so they can read its counters.
 */
class KnowledgeIndexingTest extends TestCase
{
    use CreatesKnowledgeFixtures, RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private KnowledgeBase $base;

    private FakeKnowledgeEmbedder $embedder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);

        $this->embedder = new FakeKnowledgeEmbedder;
        $this->app->instance(KnowledgeEmbedder::class, $this->embedder);

        $this->base = KnowledgeBase::factory()->create(['workspace_id' => $this->workspace->id]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers -------------------------------------------------------------------

    /** Prose long enough to be split, and stable enough that an edit can target one paragraph. */
    private function paragraph(int $index, string $flavour = 'wersja pierwsza'): string
    {
        $sentences = [];

        for ($i = 1; $i <= 12; $i++) {
            $sentences[] = "Akapit {$index} zdanie {$i} {$flavour} o tresci wypelniajacej ten fragment dokumentu.";
        }

        return implode(' ', $sentences);
    }

    private function document(int $paragraphs, string $flavour = 'wersja pierwsza'): string
    {
        $parts = [];

        for ($i = 1; $i <= $paragraphs; $i++) {
            $parts[] = $this->paragraph($i, $flavour);
        }

        return implode("\n\n", $parts);
    }

    private function createEntry(string $title, string $content): KnowledgeEntry
    {
        return $this->makeEntry(
            base: $this->base,
            title: $title,
            content: $content,
        );
    }

    /**
     * An entry's body changing — which is now an ACCEPTED AMENDMENT rather than somebody typing, and
     * lands in the same place either way: {@see KnowledgeEntryService::update()} restamps the digest,
     * sends the entry back to `pending` and lets the observer queue the re-index. That is the whole
     * subject of this file, so the indexing behaviour below is unaffected by where the text came from.
     */
    private function editContent(KnowledgeEntry $entry, string $content, ?string $title = null): KnowledgeEntry
    {
        $this->updateEntry($entry, title: $title, content: $content);

        return $entry->fresh();
    }

    /** @return array<int, KnowledgeEntryChunk> */
    private function chunksOf(KnowledgeEntry $entry): array
    {
        return KnowledgeEntryChunk::query()
            ->withoutEmbedding()
            ->where('knowledge_entry_id', $entry->getKey())
            ->orderBy('ordinal')
            ->get()
            ->all();
    }

    private function embeddedCount(KnowledgeEntry $entry): int
    {
        return KnowledgeEntryChunk::query()
            ->where('knowledge_entry_id', $entry->getKey())
            ->whereRaw('embedding is not null')
            ->count();
    }

    // ---- A-1: one entry, one call --------------------------------------------------

    /**
     * A maximum-size entry splits into no more than the fan-out cap and is bought in ONE provider
     * round-trip. Batching is not an optimization here — it is what keeps a long document from costing
     * fifty round-trips (and fifty chances to fail halfway).
     */
    public function test_a_very_large_entry_is_indexed_in_a_single_embedding_call(): void
    {
        $content = mb_substr($this->document(60), 0, 40000);

        $entry = $this->createEntry('Duzy dokument', $content);

        $this->assertSame(1, $this->embedder->calls, 'a whole entry must cost exactly one provider call');

        $chunks = $this->chunksOf($entry);
        $this->assertGreaterThan(5, count($chunks), 'a 40k entry must genuinely split');
        $this->assertLessThanOrEqual((int) config('knowledge.chunking.max_chunks_per_entry'), count($chunks));

        $entry->refresh();
        $this->assertSame(KnowledgeIndexStatus::INDEXED, $entry->index_status);
        $this->assertSame($entry->index_digest, $entry->indexed_digest);
        $this->assertSame(count($chunks), $entry->chunks_count);
        $this->assertSame(count($chunks), $this->embeddedCount($entry));
        $this->assertNull($entry->index_started_at, 'a settled run releases the reaper claim');
    }

    /**
     * With a smaller batch size the same entry is bought over several INDEPENDENTLY GATED calls. The
     * risk this pins is the alignment: vectors come back positionally, so an off-by-one in the batch
     * bookkeeping would file each passage under a neighbour's vector — a corruption that produces
     * plausible-looking results and that no downstream check could ever detect.
     */
    public function test_a_smaller_batch_size_splits_the_spend_without_misaligning_vectors(): void
    {
        config()->set('knowledge.index.embed_batch', 2);

        $entry = $this->createEntry('Dokument', $this->document(12));

        $chunks = $this->chunksOf($entry);
        $this->assertGreaterThan(2, count($chunks));
        $this->assertSame((int) ceil(count($chunks) / 2), $this->embedder->calls);
        $this->assertSame(count($chunks), $this->embeddedCount($entry));

        // Each row must carry the vector of ITS OWN embedded text, not a neighbour's.
        $dimensions = (int) config('knowledge.embedding.dimensions');

        foreach ($chunks as $chunk) {
            $expected = FakeKnowledgeEmbedder::vectorFor(
                "Dokument\n" . $chunk->heading_path . "\n" . $chunk->content,
                $dimensions,
            );
            $stored = ChunkVector::read((string) $chunk->id);

            $this->assertNotNull($stored);
            // pgvector stores float4, so compare with single-precision tolerance.
            $this->assertEqualsWithDelta($expected[0], $stored[0], 1e-6);
            $this->assertEqualsWithDelta($expected[1], $stored[1], 1e-6);
            $this->assertEqualsWithDelta(end($expected), end($stored), 1e-6);
        }
    }

    // ---- A-2: differential re-indexing ---------------------------------------------

    /** Re-saving identical text costs NOTHING: the run returns on the digest comparison. */
    public function test_saving_an_entry_again_without_changes_costs_no_provider_call(): void
    {
        $content = $this->document(6);
        $entry = $this->createEntry('Dokument', $content);

        $this->assertSame(1, $this->embedder->calls);
        $this->embedder->reset();

        $this->editContent($entry, $content);

        $this->assertSame(0, $this->embedder->calls, 'an unchanged save must not reach the provider');
    }

    /**
     * THE headline claim: editing one paragraph of a multi-chunk entry re-embeds only what moved.
     *
     * The bound is stated in CHUNKS, not calls, because the batching would hide a regression that
     * re-embedded everything in one call. One edited paragraph can disturb at most the passage it sits
     * in and (through re-packing) its neighbour.
     */
    public function test_editing_one_paragraph_re_embeds_only_the_affected_chunks(): void
    {
        $before = $this->document(25);
        $entry = $this->createEntry('Dokument', $before);

        $originalChunks = $this->chunksOf($entry);
        $this->assertGreaterThan(8, count($originalChunks), 'the fixture must produce many chunks');

        // Keep a vector from an untouched region so we can prove it was REUSED, not re-bought.
        $untouched = $originalChunks[0];
        $untouchedVector = ChunkVector::read((string) $untouched->id);
        $this->assertNotNull($untouchedVector);

        $this->embedder->reset();

        $after = str_replace(
            $this->paragraph(20),
            $this->paragraph(20, 'wersja poprawiona i zmieniona'),
            $before,
        );
        $this->assertNotSame($before, $after, 'the edit must actually change the text');

        $entry = $this->editContent($entry, $after);

        $this->assertSame(1, $this->embedder->calls, 'one edit is one batch');
        $this->assertLessThanOrEqual(
            2,
            count($this->embedder->batches[0]),
            'editing one paragraph must re-embed 1-2 chunks, not the whole entry',
        );

        $this->assertSame(KnowledgeIndexStatus::INDEXED, $entry->index_status);

        // The untouched passage kept its identity AND its vector.
        $stillThere = KnowledgeEntryChunk::query()->find($untouched->id);
        $this->assertNotNull($stillThere, 'an unchanged passage keeps its row');
        $this->assertEquals($untouchedVector, ChunkVector::read((string) $untouched->id));
    }

    /** Chunks that no longer correspond to any passage are removed, not orphaned. */
    public function test_shrinking_an_entry_deletes_the_chunks_that_no_longer_exist(): void
    {
        $entry = $this->createEntry('Dokument', $this->document(20));
        $before = count($this->chunksOf($entry));

        $entry = $this->editContent($entry, $this->document(3));
        $after = count($this->chunksOf($entry));

        $this->assertLessThan($before, $after);
        $this->assertSame($after, $entry->chunks_count);
        $this->assertSame($after, $this->embeddedCount($entry));
    }

    /**
     * Inserting text at the TOP shifts every following passage's ordinal. The re-numbering must not
     * trip the `(entry, ordinal)` unique index — which it would, done naively, on any entry whose
     * chunks move by one.
     */
    public function test_prepending_text_renumbers_chunks_without_colliding(): void
    {
        $body = $this->document(10);
        $entry = $this->createEntry('Dokument', $body);

        $entry = $this->editContent($entry, $this->paragraph(99, 'nowy wstep dokumentu') . "\n\n" . $body);

        $chunks = $this->chunksOf($entry);
        $ordinals = array_map(static fn (KnowledgeEntryChunk $chunk): int => (int) $chunk->ordinal, $chunks);

        $this->assertSame(range(0, count($chunks) - 1), $ordinals, 'ordinals stay gapless and 0-based');
        $this->assertSame(KnowledgeIndexStatus::INDEXED, $entry->index_status);
    }

    // ---- A-3: config changes restale everything ------------------------------------

    /**
     * Bumping the chunker version marks every existing entry stale WITHOUT any write to it — the only
     * mechanism that keeps a splitter improvement from applying to new documents only.
     */
    public function test_bumping_the_chunker_version_makes_existing_entries_need_indexing(): void
    {
        $entry = $this->createEntry('Dokument', $this->document(4));

        $this->assertFalse($entry->fresh()->needsIndexing());
        $this->embedder->reset();

        config()->set('knowledge.chunking.version', (int) config('knowledge.chunking.version') + 1);

        // Nothing has been written to the entry, yet it is out of date the instant the pipeline moves.
        $this->assertTrue(
            $entry->fresh()->needsIndexing(),
            'a chunker version bump must restale every entry',
        );
        $this->assertTrue(
            KnowledgeEntry::query()->needsIndexing()->whereKey($entry->id)->exists(),
            'the SQL scope must agree with needsIndexing(), or the sweep will never find it',
        );

        // And the catch-up sweep actually settles it — the promise is a real backfill, not a flag.
        $this->artisan('knowledge:sweep-index')->assertSuccessful();

        $entry->refresh();
        $this->assertSame(KnowledgeIndexStatus::INDEXED, $entry->index_status);
        $this->assertFalse($entry->needsIndexing());

        // Nothing was re-BOUGHT, and that is the design working, not a gap: the bump forced a
        // re-chunk, the passages came out identical, and every digest matched a stored vector. A
        // splitter change that really moves the boundaries is what costs money — the version bump is
        // only what makes the base look again.
        $this->assertSame(0, $this->embedder->calls);
    }

    /**
     * A MODEL swap is the opposite case and must re-buy everything. Vectors from two embedding models
     * share no coordinate system, so reusing an old one because its text happened not to change would
     * poison the index with scores that mean nothing and that nothing downstream can detect.
     */
    public function test_swapping_the_embedding_model_re_embeds_every_chunk(): void
    {
        $entry = $this->createEntry('Dokument', $this->document(10));
        $chunkCount = count($this->chunksOf($entry));
        $this->assertGreaterThan(2, $chunkCount);

        $this->embedder->reset();
        config()->set('knowledge.embedding.model', 'text-embedding-3-large');

        $this->artisan('knowledge:sweep-index')->assertSuccessful();

        $this->assertSame(1, $this->embedder->calls);
        $this->assertSame(
            $chunkCount,
            count($this->embedder->batches[0]),
            'every passage must be re-embedded under the new model, not just the changed ones',
        );

        $entry->refresh();
        $this->assertSame(KnowledgeIndexStatus::INDEXED, $entry->index_status);
        $this->assertSame(
            $chunkCount,
            KnowledgeEntryChunk::query()
                ->where('knowledge_entry_id', $entry->id)
                ->where('embedding_model', 'text-embedding-3-large')
                ->count(),
            'every row must record the model that actually produced its vector',
        );
    }

    /** Swapping the embedding model has the same effect, for the same reason. */
    public function test_changing_the_embedding_model_makes_existing_entries_need_indexing(): void
    {
        $entry = $this->createEntry('Dokument', $this->document(4));
        $this->assertFalse($entry->fresh()->needsIndexing());

        config()->set('knowledge.embedding.model', 'text-embedding-3-large');

        $this->assertTrue($entry->fresh()->needsIndexing());
        $this->assertTrue(KnowledgeEntry::query()->needsIndexing()->whereKey($entry->id)->exists());
    }

    /**
     * The counterpart that keeps the above honest: with the pipeline UNCHANGED, an indexed entry must
     * not be a sweep candidate. Without this, a scope that over-matched would re-embed the entire base
     * on every scheduled pass and nothing would fail.
     */
    public function test_an_up_to_date_entry_is_not_a_sweep_candidate(): void
    {
        $entry = $this->createEntry('Dokument', $this->document(4));

        $this->assertFalse(KnowledgeEntry::query()->needsIndexing()->whereKey($entry->id)->exists());

        $this->embedder->reset();
        $this->artisan('knowledge:sweep-index')->assertSuccessful();

        $this->assertSame(0, $this->embedder->calls, 'a scheduled sweep over a current base costs nothing');
    }

    /**
     * A pipeline change must NOT rewrite history. The digest folds the chunker version in, and the
     * entry service uses that same digest to decide what counts as a new revision — so without care an
     * operator bumping the chunker would make the next unrelated edit of every entry append an empty
     * version.
     */
    public function test_a_pipeline_bump_does_not_manufacture_an_empty_revision(): void
    {
        $content = $this->document(4);
        $entry = $this->createEntry('Dokument', $content);

        $revisions = $entry->revisions()->count();

        config()->set('knowledge.chunking.version', (int) config('knowledge.chunking.version') + 1);

        // Save the entry with IDENTICAL authored content.
        $this->editContent($entry, $content);

        $this->assertSame($revisions, $entry->revisions()->count());
    }

    // ---- kill switch ---------------------------------------------------------------

    /** The operator's one switch: nothing is chunked, nothing is embedded, nothing is queued. */
    public function test_the_kill_switch_stops_every_provider_call(): void
    {
        config()->set('knowledge.index.enabled', false);

        $entry = $this->createEntry('Dokument', $this->document(6));

        $this->assertSame(0, $this->embedder->calls);
        $this->assertSame([], $this->chunksOf($entry));
        $this->assertSame(KnowledgeIndexStatus::PENDING, $entry->fresh()->index_status);
    }

    /** Even a job already sitting in the queue is refused once the switch is off. */
    public function test_the_kill_switch_is_honoured_inside_an_already_queued_job(): void
    {
        Queue::fake();
        $entry = $this->createEntry('Dokument', $this->document(6));
        Queue::assertPushed(IndexKnowledgeEntryJob::class);

        config()->set('knowledge.index.enabled', false);

        (new IndexKnowledgeEntryJob((string) $entry->id, (string) $this->workspace->id))
            ->handle(app(\App\Modules\Knowledge\Services\KnowledgeIndexService::class));

        $this->assertSame(0, $this->embedder->calls);
    }

    // ---- A-7: budget --------------------------------------------------------------

    /**
     * An exhausted budget is not a failure. Work already paid for stays persisted, the entry says so
     * (`partial`), and the sweep finishes the job once the cap moves — WITHOUT re-buying the vectors
     * the first run obtained.
     */
    public function test_an_exhausted_budget_leaves_the_entry_partial_and_the_sweep_finishes_it(): void
    {
        $entry = $this->createEntry('Dokument', $this->document(12));

        $indexedChunks = count($this->chunksOf($entry));
        $this->assertGreaterThan(2, $indexedChunks);
        $this->assertSame($indexedChunks, $this->embeddedCount($entry));

        // Exhaust the workspace's month: cap $1, ledger already at $2.
        $this->workspace->update(['ai_monthly_cost_cap' => 1.00]);
        app(TenantContext::class)->set($this->workspace->fresh());
        AiUsageEvent::create([
            'channel' => 'ai_text',
            'prompt_tokens' => 1000,
            'completion_tokens' => 0,
            'total_tokens' => 1000,
            'estimated_cost' => 2.00,
        ]);

        $this->embedder->reset();

        // Append fresh material: existing passages still match by digest, the new ones need vectors.
        $entry = $this->editContent($entry, $this->document(12) . "\n\n" . $this->paragraph(90, 'zupelnie nowy material'));

        $this->assertSame(0, $this->embedder->calls, 'an over-cap workspace must never reach the provider');
        $this->assertSame(KnowledgeIndexStatus::PARTIAL, $entry->index_status);
        $this->assertNotSame($entry->index_digest, $entry->indexed_digest, 'a partial run stays stale');

        // The paid-for vectors survived; only the new passage lacks one.
        $this->assertSame($indexedChunks, $this->embeddedCount($entry));
        $this->assertGreaterThan($indexedChunks, count($this->chunksOf($entry)));

        // Raise the cap and let the catch-up sweep finish it.
        $this->workspace->update(['ai_monthly_cost_cap' => 1000.00]);
        app(TenantContext::class)->set($this->workspace->fresh());

        $this->artisan('knowledge:sweep-index')->assertSuccessful();

        $entry->refresh();
        $this->assertSame(KnowledgeIndexStatus::INDEXED, $entry->index_status);
        $this->assertSame($entry->index_digest, $entry->indexed_digest);
        $this->assertSame(count($this->chunksOf($entry)), $this->embeddedCount($entry));
        $this->assertSame(
            1,
            $this->embedder->calls,
            'the catch-up must buy only the missing passage, not the whole entry again',
        );
    }

    /**
     * When NOTHING could be embedded the state is `pending_budget`, not `failed`: nothing is broken and
     * no retry helps — raising the cap does. Conflating the two sends an operator hunting a bug.
     */
    public function test_a_brand_new_entry_refused_by_the_budget_is_pending_budget_not_failed(): void
    {
        $this->workspace->update(['ai_monthly_cost_cap' => 1.00]);
        app(TenantContext::class)->set($this->workspace->fresh());
        AiUsageEvent::create([
            'channel' => 'ai_text',
            'prompt_tokens' => 1000,
            'completion_tokens' => 0,
            'total_tokens' => 1000,
            'estimated_cost' => 5.00,
        ]);

        $entry = $this->createEntry('Dokument', $this->document(6));

        $this->assertSame(0, $this->embedder->calls);
        $this->assertSame(KnowledgeIndexStatus::PENDING_BUDGET, $entry->fresh()->index_status);
        $this->assertSame(0, $this->embeddedCount($entry));
        // The write itself was never blocked — that is the invariant that matters most.
        $this->assertDatabaseHas('knowledge_entries', ['id' => $entry->id]);
    }

    // ---- provider failure ----------------------------------------------------------

    public function test_a_provider_error_marks_the_entry_failed_and_keeps_it_stale(): void
    {
        $this->embedder->failure = new \RuntimeException('provider exploded');

        $entry = $this->createEntry('Dokument', $this->document(6));

        $entry->refresh();
        $this->assertSame(KnowledgeIndexStatus::FAILED, $entry->index_status);
        $this->assertNotSame($entry->index_digest, $entry->indexed_digest);
        $this->assertNull($entry->index_started_at);

        // The sweep picks a failed entry back up, and it succeeds once the provider recovers.
        $this->embedder->reset();
        $this->artisan('knowledge:sweep-index')->assertSuccessful();

        $this->assertSame(KnowledgeIndexStatus::INDEXED, $entry->fresh()->index_status);
    }

    // ---- metering ------------------------------------------------------------------

    /** Embedding spend lands in the ledger on its own channel, with the fake's REAL token count. */
    public function test_an_indexing_run_records_real_tokens_on_the_embedding_channel(): void
    {
        $this->createEntry('Dokument', $this->document(6));

        $event = AiUsageEvent::query()->where('channel', 'ai_embedding')->firstOrFail();

        $this->assertGreaterThan(0, $event->total_tokens);
        $this->assertSame((int) $event->total_tokens, (int) $event->prompt_tokens);
        $this->assertSame(0, (int) $event->completion_tokens);
        // NOT the flat image-style unit stand-in.
        $this->assertNotSame(4000, (int) $event->total_tokens);
    }

    /** The embedded text carries the title and the heading trail, so a passage is never context-free. */
    public function test_the_embedded_text_carries_the_title_and_heading_path(): void
    {
        $this->createEntry('Polityka handlowa', "# Cennik\n\n" . $this->document(3));

        $texts = $this->embedder->embeddedTexts();
        $this->assertNotEmpty($texts);

        foreach ($texts as $text) {
            $this->assertStringStartsWith("Polityka handlowa\n", $text);
            $this->assertStringContainsString('Polityka handlowa > Cennik', $text);
        }
    }

    // ---- the chunk cap, now met INSIDE the run -------------------------------------

    /**
     * An entry that would blow the fan-out cap is NOT indexed, spends nothing, and says so in its own
     * index state.
     *
     * THIS TEST CHANGED MEANING WITH THE WITHDRAWAL, and the change is worth reading rather than
     * skimming. The cap used to be enforced AT THE DOOR: `StoreKnowledgeEntryRequest` asked
     * {@see KnowledgeChunker::countFor()} and answered 422, so an over-cap entry was never written. That
     * request is gone with the rest of hand-authoring, and nothing on the composer's path asks the
     * chunker how many passages a proposal would produce — so an over-cap entry is now WRITTEN and the
     * cap is met later, by {@see KnowledgeIndexService::index()} catching the chunker's overflow.
     *
     * What survives is the property that actually protects the provider bill and the reader: the run
     * refuses to fan out, buys nothing, and settles `failed` instead of leaving a claim behind — which
     * is also the state `retry-index` exists to act on. What is NOT covered any more is the writer being
     * told before the fact; there is no live path on which anybody could be told. Reported as a
     * deliberate loss rather than deleted quietly.
     */
    public function test_an_entry_that_would_exceed_the_chunk_cap_is_not_indexed_and_buys_nothing(): void
    {
        config()->set('knowledge.chunking.max_chunks_per_entry', 4);

        $content = '';

        for ($i = 1; $i <= 15; $i++) {
            $content .= "# Sekcja {$i}\n\n" . $this->paragraph($i) . "\n\n";
        }

        $entry = $this->createEntry('Za duzo fragmentow', $content);

        $this->assertSame(0, $this->embedder->calls, 'an over-cap entry must not reach the provider');

        $entry->refresh();

        $this->assertSame(KnowledgeIndexStatus::FAILED, $entry->index_status);
        $this->assertCount(0, $this->chunksOf($entry), 'and no passage was stored');
        $this->assertNull($entry->indexed_digest, 'nothing was indexed, so nothing may claim to be');
        $this->assertNull($entry->index_started_at, 'and the reaper claim is released either way');
    }

    // ---- the reaper ----------------------------------------------------------------

    /**
     * An abandoned `indexing` claim would otherwise remove the entry from the sweep FOREVER, since the
     * sweep skips claimed entries. The reaper is what makes that recoverable.
     */
    public function test_the_reaper_releases_a_claim_abandoned_by_a_dead_worker(): void
    {
        $entry = $this->createEntry('Dokument', $this->document(4));

        $originalUpdatedAt = $entry->fresh()->updated_at;

        KnowledgeEntry::query()->whereKey($entry->id)->update([
            'index_status' => KnowledgeIndexStatus::INDEXING->value,
            'index_started_at' => now()->subSeconds((int) config('knowledge.index.stale_after') + 60),
            'indexed_digest' => null,
            'updated_at' => $originalUpdatedAt,
        ]);

        $this->artisan('knowledge:reap-stale-index')->assertSuccessful();

        $entry->refresh();
        $this->assertSame(KnowledgeIndexStatus::PENDING, $entry->index_status);
        $this->assertNull($entry->index_started_at);
        $this->assertEquals($originalUpdatedAt, $entry->updated_at, 'reaping must not look like an edit');
    }

    /** A fresh claim belongs to a live worker and must be left alone. */
    public function test_the_reaper_leaves_a_fresh_claim_alone(): void
    {
        $entry = $this->createEntry('Dokument', $this->document(4));

        KnowledgeEntry::query()->whereKey($entry->id)->update([
            'index_status' => KnowledgeIndexStatus::INDEXING->value,
            'index_started_at' => now()->subSeconds(5),
        ]);

        $this->artisan('knowledge:reap-stale-index')->assertSuccessful();

        $this->assertSame(KnowledgeIndexStatus::INDEXING, $entry->fresh()->index_status);
    }

    // ---- background writes never masquerade as edits -------------------------------

    /**
     * `updated_at` is the entry's user-visible "last edited" on both API resources. A background index
     * must not move it, or every list would show documents being edited by nobody.
     */
    public function test_indexing_does_not_touch_the_entry_updated_at(): void
    {
        Queue::fake();
        $entry = $this->createEntry('Dokument', $this->document(6));
        $before = $entry->fresh()->updated_at;

        $this->travel(5)->minutes();

        (new IndexKnowledgeEntryJob((string) $entry->id, (string) $this->workspace->id))
            ->handle(app(\App\Modules\Knowledge\Services\KnowledgeIndexService::class));

        $entry->refresh();
        $this->assertSame(KnowledgeIndexStatus::INDEXED, $entry->index_status);
        $this->assertEquals($before, $entry->updated_at);
    }

    // ---- a human edit landing MID-RUN ----------------------------------------------

    /**
     * B7 — THE concurrency claim of {@see KnowledgeIndexService::writeIndexColumns()}.
     *
     * The run loads the entry, then makes a provider call that can take seconds. If it settled by
     * saving the loaded MODEL, everything typed during those seconds would be silently rolled back to
     * the text the run started with — a data-loss bug with no error, no log line and no way for the
     * author to know it happened. The targeted, keyed builder update is what prevents that, and this
     * is the only test that can prove it: the edit has to land INSIDE the window, so it is performed
     * from a hook on the embedder, through the real HTTP write path, exactly where a real one would.
     *
     * Two properties are asserted, and they are different in kind:
     *
     *   THE TEXT SURVIVES — non-negotiable, and the reason the design is what it is.
     *   THE ENTRY STAYS A SWEEP CANDIDATE — the run settles `indexed_digest` for the text it actually
     *     embedded (version A), while the edit has advanced `index_digest` to version B. They disagree,
     *     so `needsIndexing()` stays true and the follow-up run (queued by the edit itself, and the
     *     scheduled sweep behind it) re-indexes the new text. Without this the chunks would describe
     *     A forever while the page showed B — a stale passage that IS retrieved and IS believed.
     */
    public function test_an_edit_landing_mid_run_survives_the_indexing_job(): void
    {
        Queue::fake();

        $versionA = $this->document(6, 'wersja pierwsza');
        $versionB = $this->document(6, 'wersja druga zupelnie inna');

        $entry = $this->createEntry('Dokument', $versionA);
        $this->assertSame(0, $this->embedder->calls, 'the queue is faked: nothing has been indexed yet');

        // An embedder that runs a callback in the middle of the "network call" — the only place a
        // concurrent write can be injected into the window the design is about.
        $hooked = new class extends FakeKnowledgeEmbedder
        {
            /** @var callable|null */
            public $duringEmbed = null;

            public function embed(array $texts): \App\Modules\Knowledge\DTOs\EmbeddingBatchResult
            {
                if ($this->duringEmbed !== null) {
                    $hook = $this->duringEmbed;
                    $this->duringEmbed = null;
                    $hook();
                }

                return parent::embed($texts);
            }
        };
        $this->app->instance(KnowledgeEmbedder::class, $hooked);

        $hooked->duringEmbed = function () use ($entry, $versionB): void {
            $this->travel(5)->minutes();

            $this->editContent($entry, $versionB, title: 'Dokument po poprawce');
        };

        (new IndexKnowledgeEntryJob((string) $entry->id, (string) $this->workspace->id))
            ->handle(app(\App\Modules\Knowledge\Services\KnowledgeIndexService::class));

        $this->assertSame(1, $hooked->calls, 'the hook must have fired inside a real embedding call');

        $entry->refresh();

        // THE assertion: the author's work is still there.
        $this->assertSame($versionB, $entry->content, 'an edit made during a run must not be rolled back');
        $this->assertSame('Dokument po poprawce', $entry->title);

        // ...and the run's own bookkeeping did not claim to have indexed it.
        $this->assertNotSame(
            $entry->index_digest,
            $entry->indexed_digest,
            'the run indexed version A, so the entry must still read as stale for version B',
        );
        $this->assertTrue($entry->needsIndexing());
        $this->assertTrue(
            KnowledgeEntry::query()->needsIndexing()->whereKey($entry->id)->exists(),
            'and the sweep must be able to find it — otherwise the chunks describe A forever',
        );
        $this->assertNull($entry->index_started_at, 'the claim is released either way');
    }

    /**
     * REGRESSION PIN (was a characterized defect, found by B7 and fixed in B9).
     *
     * `updated_at` has to survive TWO opposite pressures inside one run, and getting either wrong is
     * invisible in normal use:
     *
     *   - the run must not PUSH it forward (a background re-index is not a human edit — pinned by
     *     {@see test_indexing_does_not_touch_the_entrys_updated_at()});
     *   - the run must not PULL it back, which is what this pins. The provider call is a
     *     multi-second window; an edit that lands inside it moves the timestamp forward, and a settle
     *     that wrote the value the RUN had loaded would put it back. The content was never at risk
     *     (every write is column-scoped), but the entry's user-visible "last edited" would travel
     *     backwards and it would sink in every freshness ordering — the graph overview's filler
     *     nodes, the search tie-break.
     *
     * Both are now satisfied by the same expression: {@see KnowledgeIndexService::writeIndexColumns()}
     * writes the column's own current value back to itself, so the row's timestamp is whatever the
     * last real WRITER left there.
     */
    public function test_a_run_settling_after_a_concurrent_edit_keeps_the_editors_updated_at(): void
    {
        Queue::fake();

        $entry = $this->createEntry('Dokument', $this->document(6, 'wersja pierwsza'));
        $beforeTheRun = $entry->fresh()->updated_at;

        $hooked = new class extends FakeKnowledgeEmbedder
        {
            /** @var callable|null */
            public $duringEmbed = null;

            public function embed(array $texts): \App\Modules\Knowledge\DTOs\EmbeddingBatchResult
            {
                if ($this->duringEmbed !== null) {
                    $hook = $this->duringEmbed;
                    $this->duringEmbed = null;
                    $hook();
                }

                return parent::embed($texts);
            }
        };
        $this->app->instance(KnowledgeEmbedder::class, $hooked);

        $editedAt = null;

        $hooked->duringEmbed = function () use ($entry, &$editedAt): void {
            $this->travel(5)->minutes();

            $this->editContent($entry, $this->document(6, 'wersja druga zupelnie inna'));

            $editedAt = $entry->fresh()->updated_at;
        };

        (new IndexKnowledgeEntryJob((string) $entry->id, (string) $this->workspace->id))
            ->handle(app(\App\Modules\Knowledge\Services\KnowledgeIndexService::class));

        $this->assertNotNull($editedAt);
        $this->assertTrue($editedAt->greaterThan($beforeTheRun), 'the edit did move the timestamp forward');

        // ...and the run left it alone: the editor's timestamp is the one that survives the settle.
        $settledAt = $entry->fresh()->updated_at;

        $this->assertEquals(
            $editedAt,
            $settledAt,
            'the settle must keep the timestamp of whoever last EDITED the entry, not the one the run loaded',
        );
        $this->assertTrue(
            $settledAt->greaterThan($beforeTheRun),
            'and it must never travel backwards past where the run found it',
        );
    }

    /**
     * The same window, seen from the REVISION side: the edit that landed mid-run is a real version of
     * the document, and the run must not have swallowed it. Separated from the test above because a
     * fix that made the content survive by re-reading the row would still be wrong if the revision
     * history disagreed with it.
     */
    public function test_the_revision_written_mid_run_is_the_entrys_current_one(): void
    {
        Queue::fake();

        $entry = $this->createEntry('Dokument', $this->document(6, 'wersja pierwsza'));
        $revisionsBefore = $entry->revisions()->count();

        $hooked = new class extends FakeKnowledgeEmbedder
        {
            /** @var callable|null */
            public $duringEmbed = null;

            public function embed(array $texts): \App\Modules\Knowledge\DTOs\EmbeddingBatchResult
            {
                if ($this->duringEmbed !== null) {
                    $hook = $this->duringEmbed;
                    $this->duringEmbed = null;
                    $hook();
                }

                return parent::embed($texts);
            }
        };
        $this->app->instance(KnowledgeEmbedder::class, $hooked);

        $hooked->duringEmbed = function () use ($entry): void {
            $this->editContent($entry, $this->document(6, 'wersja druga zupelnie inna'));
        };

        (new IndexKnowledgeEntryJob((string) $entry->id, (string) $this->workspace->id))
            ->handle(app(\App\Modules\Knowledge\Services\KnowledgeIndexService::class));

        $entry->refresh();

        $this->assertSame($revisionsBefore + 1, $entry->revisions()->count(), 'the mid-run edit is a version');
        $this->assertNotNull($entry->current_revision_id);
        $this->assertSame(
            $entry->content,
            (string) $entry->revisions()->whereKey($entry->current_revision_id)->value('content'),
            'the current revision pointer must describe the text the entry actually holds',
        );
    }

    // ---- tenancy -------------------------------------------------------------------

    /**
     * The job re-establishes its OWN workspace rather than trusting whatever context the worker
     * happens to hold. Without that, a worker whose context leaked from a previous job would read —
     * and re-embed — another workspace's entry.
     */
    public function test_the_job_restores_its_workspace_context_before_indexing(): void
    {
        Queue::fake();
        $entry = $this->createEntry('Dokument', $this->document(6));

        Queue::assertPushed(
            IndexKnowledgeEntryJob::class,
            fn (IndexKnowledgeEntryJob $job): bool => $job->entryId === (string) $entry->id
                && $job->workspaceId === (string) $this->workspace->id,
        );

        // Simulate a worker holding NO context at all.
        app(TenantContext::class)->clear();

        (new IndexKnowledgeEntryJob((string) $entry->id, (string) $this->workspace->id))
            ->handle(app(\App\Modules\Knowledge\Services\KnowledgeIndexService::class));

        $this->assertSame($this->workspace->id, app(TenantContext::class)->id());
        $this->assertSame(KnowledgeIndexStatus::INDEXED, $entry->fresh()->index_status);
        $this->assertGreaterThan(0, $this->embeddedCount($entry));
    }

    /** An entry belonging to another workspace is invisible to this job — the scope holds. */
    public function test_a_job_cannot_index_another_workspaces_entry(): void
    {
        $entry = $this->createEntry('Dokument', $this->document(4));

        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);

        $this->embedder->reset();

        (new IndexKnowledgeEntryJob((string) $entry->id, (string) $other->id))
            ->handle(app(\App\Modules\Knowledge\Services\KnowledgeIndexService::class));

        $this->assertSame(0, $this->embedder->calls);
    }
}
