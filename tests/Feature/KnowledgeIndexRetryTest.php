<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Enums\KnowledgeIndexStatus;
use App\Modules\Knowledge\Jobs\IndexKnowledgeEntryJob;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * B6c — the three contract gaps the FE reader hit: the entry-level TRASH, the DENOMINATOR that makes
 * `partial` legible, and the RETRY that lets a person act on what those two now show them.
 *
 * They are tested together because they are one loop: the badge says "5 of 8", the user presses retry,
 * the entry goes back to `pending`. A test file per endpoint would split that loop across three files
 * and pin none of it.
 */
class KnowledgeIndexRetryTest extends TestCase
{
    use CreatesKnowledgeFixtures, RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private KnowledgeBase $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);

        $this->app->instance(KnowledgeEmbedder::class, new FakeKnowledgeEmbedder);

        $this->base = KnowledgeBase::factory()->create(['workspace_id' => $this->workspace->id]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- fixtures --------------------------------------------------------------------

    /** An entry written WITHOUT the auto-indexer, so its index state is exactly what a test sets. */
    private function entry(array $attributes = []): KnowledgeEntry
    {
        $enabled = config('knowledge.index.enabled');
        config()->set('knowledge.index.enabled', false);

        try {
            return KnowledgeEntry::factory()->create(array_merge([
                'workspace_id' => $this->workspace->id,
                'knowledge_base_id' => $this->base->id,
                'status' => KnowledgeEntryStatus::APPROVED,
            ], $attributes));
        } finally {
            config()->set('knowledge.index.enabled', $enabled);
        }
    }

    /** A stored passage; $embedded writes a vector under the CURRENT embedding model. */
    private function chunk(KnowledgeEntry $entry, int $ordinal, bool $embedded, ?string $model = null): KnowledgeEntryChunk
    {
        $chunk = KnowledgeEntryChunk::factory()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_entry_id' => $entry->id,
            'knowledge_base_id' => $this->base->id,
            'ordinal' => $ordinal,
            'content' => 'Passage ' . $ordinal,
        ]);

        if ($embedded) {
            ChunkVector::write(
                (string) $chunk->id,
                FakeKnowledgeEmbedder::vectorFor('passage ' . $ordinal, (int) config('knowledge.embedding.dimensions')),
                $model ?? (string) config('knowledge.embedding.model'),
                now(),
            );
        }

        return $chunk;
    }

    // ---- 1. the entry-level trash ------------------------------------------------------

    /**
     * The gap: the base-level trash cannot express "this entry was deleted inside a live base", so
     * without `trashed=1` a deleted entry was reachable only by an id nobody wrote down.
     */
    public function test_the_entry_list_can_show_the_trash(): void
    {
        $live = $this->entry(['title' => 'Zywy wpis']);
        $trashed = $this->entry(['title' => 'Usuniety wpis']);
        $trashed->delete();

        $default = $this->getJson("/api/knowledge/bases/{$this->base->id}/entries")->assertOk();
        $this->assertSame([(string) $live->id], $this->ids($default));

        $trash = $this->getJson("/api/knowledge/bases/{$this->base->id}/entries?trashed=1")->assertOk();
        $this->assertSame([(string) $trashed->id], $this->ids($trash));
        $this->assertNotNull($trash->json('data.0.deleted_at'));
    }

    /** The trash is filterable like any other list — the same query surface, not a second one. */
    public function test_the_trash_still_honours_the_other_filters(): void
    {
        $matching = $this->entry(['title' => 'Zwroty towaru']);
        $other = $this->entry(['title' => 'Zupelnie co innego']);
        $matching->delete();
        $other->delete();

        $response = $this->getJson("/api/knowledge/bases/{$this->base->id}/entries?trashed=1&search=Zwroty")->assertOk();

        $this->assertSame([(string) $matching->id], $this->ids($response));
    }

    /**
     * The loop closes: an entry that comes BACK leaves the trash listing and rejoins the live one.
     *
     * The restore used to be a button (`POST /entries/{id}/restore`) and is not one any more — bringing
     * a page back is authoring the base's contents. What restores an entry now is the BASE cascade and
     * the erasure tooling, both through {@see \App\Modules\Knowledge\Services\KnowledgeEntryService::restore()},
     * so the listing half of this test is asserted against that. The listing itself is untouched and is
     * the reason this still matters: `trashed=1` is how anybody sees an entry that fell out of a base.
     */
    public function test_an_entry_restored_from_the_trash_rejoins_the_live_listing(): void
    {
        $entry = $this->entry(['title' => 'Do przywrocenia']);
        $entry->delete();

        $id = $this->getJson("/api/knowledge/bases/{$this->base->id}/entries?trashed=1")
            ->assertOk()
            ->json('data.0.id');

        $this->restoreEntry($entry);

        $this->assertSame([], $this->ids(
            $this->getJson("/api/knowledge/bases/{$this->base->id}/entries?trashed=1")->assertOk()
        ));
        $this->assertSame([$id], $this->ids(
            $this->getJson("/api/knowledge/bases/{$this->base->id}/entries")->assertOk()
        ));
    }

    // ---- 2. the denominator ------------------------------------------------------------

    public function test_the_detail_view_reports_how_many_passages_are_indexed(): void
    {
        $entry = $this->entry();
        $this->chunk($entry, 0, embedded: true);
        $this->chunk($entry, 1, embedded: true);
        $this->chunk($entry, 2, embedded: false);
        $entry->forceFill(['chunks_count' => 3, 'index_status' => KnowledgeIndexStatus::PARTIAL])->save();

        $this->getJson("/api/knowledge/entries/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('data.index.chunks_count', 3)
            ->assertJsonPath('data.index.indexed_chunks_count', 2)
            ->assertJsonPath('data.index.status', 'partial');
    }

    /**
     * The same number on the LIST, and — the part worth pinning — as ONE correlated sub-select rather
     * than a query per row. Without this the badge would be the reason a 25-entry page fires 25 extra
     * queries, which is exactly how "just a small field" becomes a performance incident.
     */
    public function test_the_list_reports_it_without_an_n_plus_one(): void
    {
        $this->seedEntriesWithChunks(from: 0, to: 1);

        $withTwo = $this->countQueries(fn () => $this->getJson("/api/knowledge/bases/{$this->base->id}/entries"));

        $this->seedEntriesWithChunks(from: 2, to: 6);

        $withSeven = $this->countQueries(fn () => $this->getJson("/api/knowledge/bases/{$this->base->id}/entries"));

        // The COST of the page must not grow with the number of rows on it. Comparing two different
        // page sizes is the only way to say that: a single measurement passes just as happily against
        // a query per entry.
        $this->assertSame(
            $withTwo,
            $withSeven,
            'the indexed-chunk count must ride one sub-select, not one query per entry',
        );

        $response = $this->getJson("/api/knowledge/bases/{$this->base->id}/entries")->assertOk();

        $counts = array_map(
            static fn (array $row): int => $row['index']['indexed_chunks_count'],
            $response->json('data'),
        );
        // Alternating 2/1 by construction — so a sub-select that ignored its constraint (and reported
        // `chunks_count` twice over) would be caught here rather than looking correct.
        $this->assertSame([2, 1, 2, 1, 2, 1, 2], $counts);
    }

    /** Entries at consecutive positions, each with 2 passages of which the even ones have both embedded. */
    private function seedEntriesWithChunks(int $from, int $to): void
    {
        foreach (range($from, $to) as $index) {
            $entry = $this->entry(['position' => $index]);
            $this->chunk($entry, 0, embedded: true);
            $this->chunk($entry, 1, embedded: $index % 2 === 0);
            $entry->forceFill(['chunks_count' => 2])->save();
        }
    }

    /**
     * A vector from a DIFFERENT embedding model does not count. Two models share no coordinate system,
     * so counting a leftover would report an entry as complete while half of it was unsearchable — the
     * same rule the indexer settles state by, which is why both read one scope.
     */
    public function test_a_vector_from_another_embedding_model_is_not_counted(): void
    {
        $entry = $this->entry();
        $this->chunk($entry, 0, embedded: true);
        $this->chunk($entry, 1, embedded: true, model: 'text-embedding-ancient');
        $entry->forceFill(['chunks_count' => 2])->save();

        $this->getJson("/api/knowledge/entries/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('data.index.chunks_count', 2)
            ->assertJsonPath('data.index.indexed_chunks_count', 1);
    }

    public function test_an_entry_with_no_passages_reports_zero(): void
    {
        $entry = $this->entry();

        $this->getJson("/api/knowledge/entries/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('data.index.indexed_chunks_count', 0);
    }

    // ---- 3. retry -----------------------------------------------------------------------

    /**
     * @dataProvider retryableStates
     */
    public function test_a_run_that_did_not_finish_can_be_retried(string $status): void
    {
        Queue::fake();

        $entry = $this->entry();
        $entry->forceFill(['index_status' => $status, 'index_started_at' => now()->subHour()])->save();

        $this->postJson("/api/knowledge/entries/{$entry->id}/retry-index")
            ->assertOk()
            ->assertJsonPath('data.index.status', 'pending')
            // Back in the queue, so the affordance is gone until the run settles again.
            ->assertJsonPath('data.index.can_retry', false);

        $entry->refresh();
        $this->assertSame(KnowledgeIndexStatus::PENDING, $entry->index_status);
        // The stale claim is released, so the reaper has nothing left to chase.
        $this->assertNull($entry->index_started_at);

        Queue::assertPushed(
            IndexKnowledgeEntryJob::class,
            fn (IndexKnowledgeEntryJob $job): bool => $job->entryId === (string) $entry->id
                && $job->workspaceId === (string) $this->workspace->id,
        );
    }

    public static function retryableStates(): array
    {
        return [
            'failed' => ['failed'],
            'refused for budget' => ['pending_budget'],
            'partially indexed' => ['partial'],
        ];
    }

    /**
     * @dataProvider unretryableStates
     */
    public function test_a_state_with_nothing_to_retry_is_refused(string $status): void
    {
        Queue::fake();

        $entry = $this->entry();
        $entry->forceFill(['index_status' => $status])->save();

        $this->postJson("/api/knowledge/entries/{$entry->id}/retry-index")
            ->assertStatus(422)
            ->assertJsonValidationErrors('index');

        $this->assertSame($status, $entry->fresh()->index_status->value);
        Queue::assertNothingPushed();
    }

    public static function unretryableStates(): array
    {
        return [
            'already indexed' => ['indexed'],
            'claimed by a worker' => ['indexing'],
            'already queued' => ['pending'],
        ];
    }

    /** A retry is not an edit: the document must not look freshly authored because someone clicked. */
    public function test_a_retry_does_not_touch_the_entrys_updated_at(): void
    {
        Queue::fake();

        $entry = $this->entry();
        $entry->forceFill(['index_status' => 'failed'])->save();
        $before = $entry->fresh()->updated_at;

        $this->travel(2)->minutes();
        $this->postJson("/api/knowledge/entries/{$entry->id}/retry-index")->assertOk();

        $this->assertTrue($before->equalTo($entry->fresh()->updated_at));
    }

    /**
     * The kill switch is honoured where it always is — inside the job. The entry still lands in
     * `pending`, so the operator's switch delays the work instead of losing the user's request: the
     * sweep picks it up once indexing is turned back on.
     */
    public function test_a_retry_under_the_kill_switch_still_records_the_intent(): void
    {
        $entry = $this->entry();
        $entry->forceFill(['index_status' => 'failed'])->save();

        config()->set('knowledge.index.enabled', false);

        $this->postJson("/api/knowledge/entries/{$entry->id}/retry-index")
            ->assertOk()
            ->assertJsonPath('data.index.status', 'pending');

        $this->assertSame(KnowledgeIndexStatus::PENDING, $entry->fresh()->index_status);
    }

    /** A real retry re-runs the indexer end to end and settles the entry. */
    public function test_a_retry_actually_reindexes_the_entry(): void
    {
        $entry = $this->entry(['content' => str_repeat('Tresc wpisu o zwrotach. ', 40)]);
        $entry->forceFill(['index_status' => 'failed'])->save();

        $this->postJson("/api/knowledge/entries/{$entry->id}/retry-index")->assertOk();

        // QUEUE_CONNECTION=sync: the job ran in-process.
        $entry->refresh();
        $this->assertSame(KnowledgeIndexStatus::INDEXED, $entry->index_status);
        $this->assertGreaterThan(0, $entry->chunks_count);
        $this->assertSame(
            $entry->chunks_count,
            $entry->loadIndexedChunks()->indexedChunksCount(),
            'a successful retry must leave every passage carrying a current vector',
        );
    }

    public function test_another_workspaces_entry_cannot_be_retried(): void
    {
        $otherOwner = User::factory()->create();
        $other = Workspace::factory()->create(['owner_id' => $otherOwner->id]);
        $foreignBase = KnowledgeBase::factory()->create(['workspace_id' => $other->id]);
        $foreign = KnowledgeEntry::factory()->create([
            'workspace_id' => $other->id,
            'knowledge_base_id' => $foreignBase->id,
        ]);

        $this->postJson("/api/knowledge/entries/{$foreign->id}/retry-index")->assertNotFound();
    }

    /**
     * The module-wide fail-closed gate covers the new route too. 400, not 404: nothing is looked up
     * before the check, so the answer reveals nothing about whether the id exists.
     */
    public function test_the_retry_endpoint_requires_an_active_workspace(): void
    {
        $entry = $this->entry();
        $entry->forceFill(['index_status' => 'failed'])->save();

        $this->withHeaders(['X-Workspace-Id' => ''])
            ->postJson("/api/knowledge/entries/{$entry->id}/retry-index")
            ->assertStatus(400);
    }

    // ---- plumbing -------------------------------------------------------------------------

    /** @return array<int, string> */
    private function ids($response): array
    {
        return array_map(static fn (array $row): string => $row['id'], $response->json('data'));
    }

    private function countQueries(callable $callback): int
    {
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();

        try {
            $callback();

            return count(\Illuminate\Support\Facades\DB::getQueryLog());
        } finally {
            \Illuminate\Support\Facades\DB::disableQueryLog();
            \Illuminate\Support\Facades\DB::flushQueryLog();
        }
    }
}
