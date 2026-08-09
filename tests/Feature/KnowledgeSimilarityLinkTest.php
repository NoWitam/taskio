<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Enums\KnowledgeLinkSource;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Services\KnowledgeSimilarityLinker;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * MATERIALIZED SIMILARITY EDGES (B2b): what the vector layer proposes, what it refuses to propose,
 * and what it must never propose again.
 *
 * Two fixture techniques, each for a different question.
 *
 *   IDENTICAL DOCUMENTS exercise the REAL path end to end. The fake embedder derives its vector from
 *   the text, and the indexer embeds `title + heading path + passage` — so two entries with the same
 *   title and the same body produce passages that embed identically, i.e. cosine 1.0, through the
 *   ordinary save → index job → linker pipeline with nothing stubbed.
 *
 *   HAND-WRITTEN VECTORS ({@see writeVector()}) exercise the THRESHOLD. A pair at a chosen angle has
 *   a cosine of exactly cos(angle), so "0.955 links and 0.540 does not" is an assertion about the
 *   configured cut rather than about whatever the embedder happened to produce. Nothing else can pin a
 *   number that is the difference between a useful panel and a hairball.
 */
class KnowledgeSimilarityLinkTest extends TestCase
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

    // ---- helpers -------------------------------------------------------------------

    /** Long enough that every passage clears `similarity.min_chunk_chars`. */
    private function document(string $subject, int $paragraphs = 3): string
    {
        $parts = [];

        for ($i = 1; $i <= $paragraphs; $i++) {
            $sentences = [];

            for ($s = 1; $s <= 12; $s++) {
                $sentences[] = "Akapit {$i} zdanie {$s} o temacie {$subject} wypelniajacy ten fragment dokumentu.";
            }

            $parts[] = implode(' ', $sentences);
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

    private function firstChunk(KnowledgeEntry $entry): KnowledgeEntryChunk
    {
        return KnowledgeEntryChunk::query()
            ->withoutEmbedding()
            ->where('knowledge_entry_id', $entry->getKey())
            ->orderBy('ordinal')
            ->firstOrFail();
    }

    /**
     * Put a unit vector at `$angle` radians from the first axis into a passage. Two passages written
     * this way have a cosine of exactly cos(a − b), which is what makes the threshold assertable.
     */
    private function writeVector(KnowledgeEntryChunk $chunk, float $angle): void
    {
        $vector = array_fill(0, (int) config('knowledge.embedding.dimensions'), 0.0);
        $vector[0] = cos($angle);
        $vector[1] = sin($angle);

        ChunkVector::write(
            (string) $chunk->id,
            $vector,
            (string) config('knowledge.embedding.model'),
            now(),
        );
    }

    /** @return array<int, KnowledgeLink> */
    private function similarityLinksFrom(KnowledgeEntry $entry): array
    {
        return KnowledgeLink::query()
            ->where('from_entry_id', $entry->getKey())
            ->ofSource(KnowledgeLinkSource::SIMILARITY)
            ->get()
            ->all();
    }

    private function link(KnowledgeEntry $entry): int
    {
        return app(KnowledgeSimilarityLinker::class)->link((string) $entry->getKey());
    }

    // ---- the ordinary path ----------------------------------------------------------

    /**
     * The whole pipeline, unstubbed: saving a second copy of a document makes the index notice, and
     * the edge appears without anybody asking for it.
     */
    public function test_indexing_an_entry_materializes_its_similarity_edges(): void
    {
        $content = $this->document('cennik');

        $first = $this->createEntry('Cennik', $content);
        $second = $this->createEntry('Cennik', $content);

        $links = $this->similarityLinksFrom($second);

        $this->assertCount(1, $links);
        $this->assertSame((string) $first->id, (string) $links[0]->to_entry_id);
        $this->assertSame($first->slug, $links[0]->target_slug);
        $this->assertEqualsWithDelta(1.0, (float) $links[0]->score, 1e-3);
        $this->assertNull($links[0]->dismissed_at);

        // The evidence is the citation address of BOTH ends, so "why is this related?" is answerable
        // by showing two passages rather than by asking for trust.
        $this->assertIsArray($links[0]->evidence);
        $this->assertArrayHasKey('from_chunk_ordinal', $links[0]->evidence);
        $this->assertArrayHasKey('to_chunk_ordinal', $links[0]->evidence);
        $this->assertIsInt($links[0]->evidence['from_chunk_ordinal']);
        $this->assertIsInt($links[0]->evidence['to_chunk_ordinal']);

        // The first entry had no neighbour when IT was indexed, so the pair is asymmetric — by design.
        // Consumers read the union of both directions, which the entry resource already exposes.
        $this->assertSame([], $this->similarityLinksFrom($first));
    }

    /** Unrelated documents produce no edges at all — the default state of a base is not "connected". */
    public function test_unrelated_entries_are_not_linked(): void
    {
        $this->createEntry('Cennik', $this->document('cennik'));
        $second = $this->createEntry('Urlopy', $this->document('urlopy'));

        $this->assertSame([], $this->similarityLinksFrom($second));
    }

    /**
     * The union of `links` and `backlinks` on the entry resource is what makes the asymmetry
     * invisible to a reader — pinned here because every consumer depends on it.
     */
    public function test_both_ends_of_an_asymmetric_edge_see_it_through_the_entry_resource(): void
    {
        $content = $this->document('cennik');
        $first = $this->createEntry('Cennik', $content);
        $second = $this->createEntry('Cennik', $content);

        $forward = $this->getJson("/api/knowledge/entries/{$second->id}")->assertOk();
        $this->assertSame(
            [(string) $first->id],
            collect($forward->json('data.links'))->where('source', 'similarity')->pluck('to_entry_id')->all(),
        );

        $backward = $this->getJson("/api/knowledge/entries/{$first->id}")->assertOk();
        $this->assertSame(
            [(string) $second->id],
            collect($backward->json('data.backlinks'))->where('source', 'similarity')->pluck('from_entry_id')->all(),
        );
    }

    /**
     * A re-index that rebuilt nothing must not rebuild the edges either. Observed through the link's
     * IDENTITY: the pass is delete + insert, so a row that kept its id is a pass that did not run.
     */
    public function test_an_unchanged_save_does_not_rebuild_the_edges(): void
    {
        $content = $this->document('cennik');
        $this->createEntry('Cennik', $content);
        $second = $this->createEntry('Cennik', $content);

        $before = $this->similarityLinksFrom($second)[0]->id;

        // The same text saved again — an accepted amendment that turned out to change nothing.
        $this->updateEntry($second, content: $content);

        $this->assertSame($before, $this->similarityLinksFrom($second)[0]->id);
    }

    // ---- the rules ------------------------------------------------------------------

    /** The configured cut, asserted as a number: 0.955 links, 0.540 does not. */
    public function test_the_threshold_decides_which_pairs_become_edges(): void
    {
        $target = $this->createEntry('Cennik', $this->document('cennik'));
        $source = $this->createEntry('Urlopy', $this->document('urlopy'));

        $this->writeVector($this->firstChunk($target), 0.0);

        // cos(0.3) ≈ 0.955 — comfortably above the 0.86 default.
        $this->writeVector($this->firstChunk($source), 0.3);
        $this->assertSame(1, $this->link($source));
        $this->assertEqualsWithDelta(cos(0.3), (float) $this->similarityLinksFrom($source)[0]->score, 1e-3);

        // cos(1.0) ≈ 0.540 — related-looking to a human eye, and firmly refused.
        $this->writeVector($this->firstChunk($source), 1.0);
        $this->assertSame(0, $this->link($source));
        $this->assertSame([], $this->similarityLinksFrom($source));
    }

    /**
     * Short FRAGMENTS of a long entry are still excluded at both ends. They are near-identical to
     * every other short fragment for reasons of form rather than meaning, and one such rule turns a
     * graph into a hairball.
     *
     * The floor is raised above the fixture rather than the fixture written below the floor: the
     * chunker MERGES a short trailing fragment into its predecessor by design (`chunking.min_chars`),
     * so a genuinely sub-300 chunk of a multi-chunk entry cannot be produced on purpose. Moving the
     * cut is the same experiment with a fixture that exists.
     */
    public function test_short_fragments_of_a_multi_chunk_entry_never_propose_an_edge(): void
    {
        config()->set('knowledge.similarity.min_chunk_chars', 5000);

        // The SAME title as well as the same body: the embedded text is title + heading trail +
        // passage, so anything less than that would differ in the vectors rather than in the length.
        $document = $this->document('cennik');
        $target = $this->createEntry('Notatka', $document);
        $source = $this->createEntry('Notatka', $document);

        $this->assertGreaterThan(
            1,
            KnowledgeEntryChunk::query()->where('knowledge_entry_id', $source->getKey())->count(),
            'the fixture must be a MULTI-chunk entry — a single-chunk one is deliberately exempt',
        );

        // Identical text, so the vectors are identical: the ONLY thing refusing the edge is the floor.
        $this->assertSame(0, $this->link($source));

        // Drop the floor and the very same pair links — proving the length rule, not a broken lookup.
        config()->set('knowledge.similarity.min_chunk_chars', 10);
        $this->assertSame(1, $this->link($source));
        $this->assertSame((string) $target->id, (string) $this->similarityLinksFrom($source)[0]->to_entry_id);
    }

    /**
     * B10 — A WHOLE-ENTRY PASSAGE IS EXEMPT FROM THE FLOOR, at both ends.
     *
     * Found on dev: two short notes about the Eiffel Tower had no edges at all, because the floor
     * aimed at boilerplate fragments was also excluding entries that are short in their entirety. A
     * dictionary-style note is a complete unit of meaning and its embedded text carries the title, so
     * it is exactly as comparable as a long one — and a base of such notes drawing NOTHING is the
     * worst possible answer, because the screen gives no hint why.
     *
     * The floor is set far above both notes, so nothing but the exemption can be producing the edge.
     */
    public function test_a_single_chunk_entry_links_however_short_it_is(): void
    {
        config()->set('knowledge.similarity.min_chunk_chars', 5000);

        $short = 'Wieza Eiffla to najbardziej rozpoznawalny symbol Paryza.';

        $target = $this->createEntry('Notatka', $short);
        $source = $this->createEntry('Notatka', $short);

        foreach ([$target, $source] as $entry) {
            $this->assertSame(
                1,
                KnowledgeEntryChunk::query()->where('knowledge_entry_id', $entry->getKey())->count(),
                'the fixture must be a single-chunk entry',
            );
        }

        $this->assertSame(1, $this->link($source));
        $this->assertSame((string) $target->id, (string) $this->similarityLinksFrom($source)[0]->to_entry_id);
    }

    /** The exemption is about LENGTH, not about the threshold: an unrelated short note still gets nothing. */
    public function test_the_exemption_does_not_lower_the_similarity_threshold(): void
    {
        config()->set('knowledge.similarity.min_chunk_chars', 5000);

        $target = $this->createEntry('Notatka', 'Wieza Eiffla to symbol Paryza.');
        $source = $this->createEntry('Inna', 'Zwroty przyjmujemy w czternascie dni od zakupu.');

        // cos(1.4) ≈ 0.170 — comfortably below even the SHORT bar, so length cannot be what refuses it.
        $this->writeVector($this->firstChunk($target), 0.0);
        $this->writeVector($this->firstChunk($source), 1.4);

        $this->assertSame(0, $this->link($source));
    }

    // ---- B10.1: the bar is length-aware ---------------------------------------------------
    //
    // Cosine is not comparable across lengths. Measured on dev, two entries any reader calls related
    // ("Zoja66 zwiedzała wieżę Eiffla" / "Zoja66 była na wycieczce w Paryżu") scored 0.6498 — nowhere
    // near the 0.86 calibrated on long prose, and nowhere near the unrelated band either.

    /**
     * A SHORT pair is judged against `threshold_short`. cos(0.9) ≈ 0.622 stands in for the measured
     * 0.6498: refused by the standard bar, accepted by the short one.
     */
    public function test_a_short_pair_is_judged_against_the_short_threshold(): void
    {
        $target = $this->createEntry('Eiffla', 'Wieza Eiffla to symbol Paryza.');
        $source = $this->createEntry('Paryz', 'Wycieczka do Paryza w lipcu.');

        $this->writeVector($this->firstChunk($target), 0.0);
        $this->writeVector($this->firstChunk($source), 0.9);

        $this->assertLessThan(
            (int) config('knowledge.similarity.short_chunk_chars'),
            (int) $this->firstChunk($source)->char_length,
            'both ends of the fixture must be short',
        );

        $this->assertSame(1, $this->link($source), 'a short pair clears the short bar');

        // ...and it really is the SHORT bar doing it: raise that one alone and the edge is refused.
        config()->set('knowledge.similarity.threshold_short', 0.95);
        $this->assertSame(0, $this->link($source));
    }

    /** ONE short end is enough — otherwise a note and the article about it stay permanently unlinkable. */
    public function test_a_short_to_long_pair_also_uses_the_short_threshold(): void
    {
        $long = $this->createEntry('Artykul', $this->document('paryz'));
        $short = $this->createEntry('Notatka', 'Wycieczka do Paryza w lipcu.');

        $this->writeVector($this->firstChunk($long), 0.0);
        $this->writeVector($this->firstChunk($short), 0.9);

        $this->assertGreaterThanOrEqual(
            (int) config('knowledge.similarity.short_chunk_chars'),
            (int) $this->firstChunk($long)->char_length,
            'the long end of the fixture must be long',
        );

        $this->assertSame(1, $this->link($short));
    }

    /** A LONG pair keeps the strict bar — the calibration it was measured for is untouched. */
    public function test_a_long_pair_still_needs_the_standard_threshold(): void
    {
        $target = $this->createEntry('Pierwszy', $this->document('cennik'));
        $source = $this->createEntry('Drugi', $this->document('rabaty'));

        // cos(0.9) ≈ 0.622: over the short bar, under the standard one. A long pair must be refused.
        $this->writeVector($this->firstChunk($target), 0.0);
        $this->writeVector($this->firstChunk($source), 0.9);

        $this->assertSame(0, $this->link($source), 'long passages must not inherit the short bar');

        // The same pair at cos(0.3) ≈ 0.955 clears the standard bar, proving the lookup itself works.
        $this->writeVector($this->firstChunk($source), 0.3);
        $this->assertSame(1, $this->link($source));
    }

    /**
     * The graph's DEFAULT `min_score` has to follow the lowest bar that can produce an edge. If it
     * stayed at `threshold`, every short-pair edge would be written to the database and then filtered
     * straight back out of the default view — an empty screen over a full table, visible from neither
     * side.
     */
    public function test_short_pair_edges_are_visible_in_the_default_graph(): void
    {
        $target = $this->createEntry('Eiffla', 'Wieza Eiffla to symbol Paryza.');
        $source = $this->createEntry('Paryz', 'Wycieczka do Paryza w lipcu.');

        $this->writeVector($this->firstChunk($target), 0.0);
        $this->writeVector($this->firstChunk($source), 0.9);
        $this->assertSame(1, $this->link($source));

        $edges = $this->getJson("/api/knowledge/bases/{$this->base->id}/graph?entry={$source->id}&sources=similarity")
            ->assertOk()
            ->json('data.edges');

        $this->assertCount(1, $edges);
        $this->assertLessThan((float) config('knowledge.similarity.threshold'), $edges[0]['score']);
    }

    /** An entry may draw at most `similarity.max_links` edges, however many things it resembles. */
    public function test_an_entry_draws_no_more_than_the_configured_maximum(): void
    {
        $source = $this->createEntry('Zrodlo', $this->document('zrodlo'));
        $this->writeVector($this->firstChunk($source), 0.0);

        for ($i = 1; $i <= 7; $i++) {
            $target = $this->createEntry("Cel {$i}", $this->document("cel {$i}"));
            $this->writeVector($this->firstChunk($target), 0.0);
        }

        $written = $this->link($source);

        $this->assertSame((int) config('knowledge.similarity.max_links'), $written);
        $this->assertCount((int) config('knowledge.similarity.max_links'), $this->similarityLinksFrom($source));
    }

    // ---- a human's "no" is permanent -------------------------------------------------

    /**
     * THE rule that makes suggestions bearable. A dismissed edge survives a re-index untouched and is
     * never re-proposed — otherwise "no, these are not related" would last until the next time anybody
     * edited a paragraph.
     */
    public function test_a_dismissed_edge_is_not_resurrected_by_a_re_index(): void
    {
        $content = $this->document('cennik');
        $first = $this->createEntry('Cennik', $content);
        $second = $this->createEntry('Cennik', $content);

        $link = $this->similarityLinksFrom($second)[0];

        $this->postJson("/api/knowledge/links/{$link->id}/dismiss")->assertOk();
        $dismissedAt = KnowledgeLink::query()->findOrFail($link->id)->dismissed_at;
        $this->assertNotNull($dismissedAt);

        // Force a genuine re-index of every entry WITHOUT changing a word: a pipeline bump re-chunks,
        // the digests match, the vectors are reused, and the linker runs again.
        config()->set('knowledge.chunking.version', (int) config('knowledge.chunking.version') + 1);
        $this->artisan('knowledge:sweep-index')->assertSuccessful();

        $links = $this->similarityLinksFrom($second);

        $this->assertCount(1, $links, 'the dismissed edge must not be duplicated by a live one');
        $this->assertSame((string) $link->id, (string) $links[0]->id, 'the dismissed row itself must survive');
        $this->assertEquals($dismissedAt, $links[0]->dismissed_at);

        // The OTHER direction is not affected by this pair's dismissal: it is a different edge, and the
        // re-index is free to propose it.
        $this->assertCount(1, $this->similarityLinksFrom($first));
    }

    /** Un-dismissing brings the edge back with the score and evidence it already had. */
    public function test_a_dismissal_can_be_undone(): void
    {
        $content = $this->document('cennik');
        $this->createEntry('Cennik', $content);
        $second = $this->createEntry('Cennik', $content);

        $link = $this->similarityLinksFrom($second)[0];

        $this->postJson("/api/knowledge/links/{$link->id}/dismiss")->assertOk()
            ->assertJsonPath('data.id', (string) $link->id);
        $this->assertNotNull(KnowledgeLink::query()->findOrFail($link->id)->dismissed_at);

        $restored = $this->deleteJson("/api/knowledge/links/{$link->id}/dismiss")->assertOk();

        $this->assertNull($restored->json('data.dismissed_at'));
        $this->assertNotNull($restored->json('data.score'));
        $this->assertNotNull($restored->json('data.evidence'));
    }

    /** Dismissing twice keeps the ORIGINAL timestamp — "when did we decide this" survives a double click. */
    public function test_dismissing_twice_keeps_the_first_timestamp(): void
    {
        $content = $this->document('cennik');
        $this->createEntry('Cennik', $content);
        $second = $this->createEntry('Cennik', $content);

        $link = $this->similarityLinksFrom($second)[0];

        $first = $this->postJson("/api/knowledge/links/{$link->id}/dismiss")->assertOk()->json('data.dismissed_at');
        $this->travel(5)->minutes();
        $again = $this->postJson("/api/knowledge/links/{$link->id}/dismiss")->assertOk()->json('data.dismissed_at');

        $this->assertSame($first, $again);
    }

    /**
     * Only a MACHINE'S suggestion may be dismissed. A wikilink is what the entry's text says: the way
     * to remove it is to edit the text, and a 422 that says so is more useful than a 403 that does not.
     */
    public function test_a_wikilink_cannot_be_dismissed(): void
    {
        $entry = $this->createEntry('Cennik', 'Zobacz takze [[rabaty]] oraz pozostale zasady rozliczen.');

        $link = KnowledgeLink::query()
            ->where('from_entry_id', $entry->getKey())
            ->ofSource(KnowledgeLinkSource::WIKILINK)
            ->firstOrFail();

        $this->postJson("/api/knowledge/links/{$link->id}/dismiss")
            ->assertStatus(422)
            ->assertJsonValidationErrors('source');

        $this->assertNull(KnowledgeLink::query()->findOrFail($link->id)->dismissed_at);
    }

    /** Another workspace's edge does not exist as far as this workspace is concerned. */
    public function test_a_link_from_another_workspace_cannot_be_dismissed(): void
    {
        $foreign = Workspace::factory()->create(['owner_id' => $this->user->id]);
        app(TenantContext::class)->set($foreign);

        $base = KnowledgeBase::factory()->create(['workspace_id' => $foreign->id]);
        $from = KnowledgeEntry::factory()->create(['workspace_id' => $foreign->id, 'knowledge_base_id' => $base->id]);
        $link = KnowledgeLink::create([
            'workspace_id' => $foreign->id,
            'knowledge_base_id' => $base->id,
            'from_entry_id' => $from->id,
            'target_slug' => 'cokolwiek',
            'source' => KnowledgeLinkSource::SIMILARITY->value,
        ]);

        app(TenantContext::class)->set($this->workspace);

        $this->postJson("/api/knowledge/links/{$link->id}/dismiss")->assertNotFound();
    }

    // ---- degradation ------------------------------------------------------------------

    /**
     * The `php` vector store must draw the same edges. Its neighbour lookup is a genuinely different
     * code path — it reads the source vector into PHP instead of comparing it inside Postgres — so
     * parity here is not implied by the search test.
     */
    public function test_the_php_similarity_fallback_draws_the_same_edge(): void
    {
        $target = $this->createEntry('Cennik', $this->document('cennik'));
        $source = $this->createEntry('Urlopy', $this->document('urlopy'));

        $this->writeVector($this->firstChunk($target), 0.0);
        $this->writeVector($this->firstChunk($source), 0.2);

        config()->set('knowledge.vector_store', 'php');

        $this->assertSame(1, $this->link($source));
        $this->assertSame((string) $target->id, (string) $this->similarityLinksFrom($source)[0]->to_entry_id);
        $this->assertEqualsWithDelta(cos(0.2), (float) $this->similarityLinksFrom($source)[0]->score, 1e-3);
    }

    /**
     * The linker spends nothing, so an exhausted budget cannot stop it — but an entry with no vectors
     * has nothing to compare, and that must be a quiet no-op rather than an error.
     */
    public function test_an_entry_without_vectors_links_nothing_and_does_not_fail(): void
    {
        config()->set('knowledge.index.enabled', false);
        $entry = $this->createEntry('Cennik', $this->document('cennik'));
        config()->set('knowledge.index.enabled', true);

        $this->assertSame(0, $this->link($entry));
        $this->assertSame([], $this->similarityLinksFrom($entry));
    }
}
