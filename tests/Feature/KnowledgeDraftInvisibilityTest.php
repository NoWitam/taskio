<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\DTOs\KnowledgeEntryDTO;
use App\Modules\Knowledge\Enums\KnowledgeBindingMode;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Enums\KnowledgeLinkSource;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeBinding;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use App\Modules\Knowledge\Services\KnowledgeCompiler;
use App\Modules\Knowledge\Services\KnowledgeEntryService;
use App\Modules\Knowledge\Services\KnowledgeRetrievalService;
use App\Modules\Knowledge\Services\KnowledgeSubjectPurgeService;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Knowledge\Support\ShadowSlug;
use App\Modules\Knowledge\Support\SubjectPhrases;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * THE INVISIBILITY MATRIX — one test per surface a draft could leak through (B16).
 *
 * ------------------------------------------------------------------------------------------------
 * WHY THIS CLASS EXISTS SEPARATELY FROM THE COMPOSER'S OWN TESTS
 *
 * A draft is an ordinary `knowledge_entries` row carrying a `draft_session_id`
 * ({@see \App\Modules\Knowledge\Models\Scopes\WithoutDraftsScope}). That decision bought revisions,
 * slugs, metadata validation and an atomic accept for free — and its entire price is that EVERY read
 * in the product must stop seeing those rows. The composer's tests prove the composer works; they
 * cannot prove that a surface nobody thought about is safe, because a surface nobody thought about
 * has no test in the file about the feature it belongs to.
 *
 * So the matrix is organised by SURFACE rather than by feature, and it is deliberately exhaustive
 * over the reads that exist today: list, filtered table, both search legs, the graph's nodes AND its
 * edges, an entry's backlink panel, the bot compiler in INLINE and in RAG, the trash, the erasure
 * scan, ghost adoption, and the base's aggregate counters. When a future feature adds a read, this
 * file is where the missing row is obvious.
 *
 * ------------------------------------------------------------------------------------------------
 * THE FIXTURES ARE DELIBERATELY HOSTILE
 *
 * Several of these surfaces cannot leak a draft TODAY for an incidental reason — a draft is never
 * indexed, so it owns no chunks; a draft draws no links, so no edge names it. Testing against that
 * incidental fact would pin nothing: the day indexing or linking changes, the scope would be the only
 * thing standing between a machine draft and a customer-facing answer, and no test would notice it
 * had been removed.
 *
 * So the fixtures MANUFACTURE what a draft never has — indexed chunks with real vectors, materialised
 * link rows, an `approved` status — and then assert the surface still refuses it. Each such fixture
 * says so at the point it is built.
 */
class KnowledgeDraftInvisibilityTest extends TestCase
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

        $this->base = KnowledgeBase::factory()->create([
            'workspace_id' => $this->workspace->id,
            'language' => 'pl',
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- fixtures ----------------------------------------------------------------------

    /**
     * A drafting session with no AI involved.
     *
     * The composer's own agent is faked in the composer's tests; here the run itself is irrelevant —
     * what matters is the ROW SHAPE (`draft_session_id` set), so the drafts are built directly. That
     * keeps every test in this file independent of how a draft came to exist, which is the point: the
     * scope must hold for a draft produced by any future path too.
     */
    private function composerSession(array $attributes = []): KnowledgeDraftSession
    {
        return KnowledgeDraftSession::factory()->create(array_merge([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
        ], $attributes));
    }

    private function draft(KnowledgeDraftSession $session, array $attributes = []): KnowledgeEntry
    {
        return KnowledgeEntry::factory()->create(array_merge([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
            'draft_session_id' => $session->id,
            'status' => KnowledgeEntryStatus::PROPOSED,
            'title' => 'Szkic o zwrotach',
            'slug' => 'szkic-o-zwrotach',
            'content' => 'Zwroty przyjmujemy w terminie czternastu dni od doreczenia przesylki.',
        ], $attributes));
    }

    private function live(array $attributes = []): KnowledgeEntry
    {
        return KnowledgeEntry::factory()->create(array_merge([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
            'status' => KnowledgeEntryStatus::APPROVED,
            'title' => 'Prawdziwy wpis',
            'slug' => 'prawdziwy-wpis',
            'content' => 'Tresc wpisu, ktory naprawde istnieje w bazie wiedzy.',
        ], $attributes));
    }

    /**
     * Give an entry an INDEXED passage whose vector is an exact match for `$alignedTo`.
     *
     * A draft is never indexed, so this is a state the product cannot reach on its own. It is built
     * anyway: without it, "the vector leg does not return drafts" would be true only because there was
     * nothing to return, and the assertion would survive the removal of the very filter it exists to
     * protect.
     */
    private function indexedChunk(KnowledgeEntry $entry, string $alignedTo): KnowledgeEntryChunk
    {
        // A LIVE entry was already chunked by the observer when it was saved, so re-align that passage
        // rather than adding a second one (the table's (entry, ordinal) uniqueness would refuse it).
        // A DRAFT owns none, which is exactly the state this helper manufactures.
        $chunk = KnowledgeEntryChunk::query()
            ->withoutEmbedding()
            ->where('knowledge_entry_id', $entry->getKey())
            ->orderBy('ordinal')
            ->first()
            ?? KnowledgeEntryChunk::factory()->forEntry($entry)->create([
                'content' => (string) $entry->content,
                'char_start' => 0,
                'char_length' => mb_strlen((string) $entry->content),
            ]);

        ChunkVector::write(
            (string) $chunk->id,
            FakeKnowledgeEmbedder::vectorFor($alignedTo, (int) config('knowledge.embedding.dimensions')),
            (string) config('knowledge.embedding.model'),
            now(),
        );

        $entry->forceFill(['chunks_count' => max(1, (int) $entry->chunks_count)])->saveQuietly();

        return $chunk;
    }

    /** @return array<int, string> */
    private function idsOf(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }

    // ---- the entry list and the filtered table ------------------------------------------

    public function test_the_base_entry_list_never_lists_a_draft(): void
    {
        $live = $this->live();
        $draft = $this->draft($this->composerSession());

        $listed = $this->getJson("/api/knowledge/bases/{$this->base->id}/entries")->assertOk()->json('data');

        $this->assertSame([(string) $live->id], $this->idsOf($listed));
        $this->assertNotContains((string) $draft->id, $this->idsOf($listed));
    }

    /**
     * EVERY filter, including the one that names a draft's own status.
     *
     * `proposed` is exactly what the composer stamps on a draft, so `?status[]=proposed` is the query
     * most likely to surface one — and a filter is applied AFTER the global scope, so a filter can
     * never widen what the scope narrowed. This pins that direction of composition rather than
     * assuming it.
     */
    public function test_no_filter_on_the_entry_table_can_surface_a_draft(): void
    {
        $session = $this->composerSession();
        $draft = $this->draft($session, ['stale_at' => now()->subDay()]);
        $this->live(['status' => KnowledgeEntryStatus::PROPOSED, 'title' => 'Zywy proposed', 'slug' => 'zywy-proposed']);

        $queries = [
            'status[]=proposed',
            'search=Szkic',
            'stale=1',
            'status[]=proposed&search=Szkic&stale=1',
        ];

        foreach ($queries as $query) {
            $rows = $this->getJson("/api/knowledge/bases/{$this->base->id}/entries?{$query}")->assertOk()->json('data');

            $this->assertNotContains(
                (string) $draft->id,
                $this->idsOf($rows),
                "the filter `{$query}` must not be able to reach a draft",
            );
        }
    }

    // ---- search: BOTH legs ---------------------------------------------------------------

    /**
     * Neither leg of hybrid search returns a draft — and the vector leg is tested with a draft that
     * OWNS an indexed passage aligned to the query, which is the only way the assertion means anything.
     *
     * The keyword leg is scoped by the model; the meaning leg ranks CHUNK rows, whose table carries no
     * draft column at all. What keeps a draft out of the meaning leg is the hydration step re-reading
     * the entries through the scope — the same defence that keeps a demoted entry out. If that step
     * were ever dropped for being redundant, this test is what says otherwise.
     */
    public function test_neither_search_leg_returns_a_draft_even_when_it_owns_an_indexed_passage(): void
    {
        $query = 'zwroty przesylek';

        $draft = $this->draft($this->composerSession(), ['title' => 'Zwroty przesylek']);
        $this->indexedChunk($draft, $query);

        $live = $this->live(['title' => 'Zwroty krajowe', 'slug' => 'zwroty-krajowe']);
        // THE LIVE ENTRY'S PASSAGE IS ALIGNED TOO, and that is a CONTROL rather than decoration.
        //
        // The negative half of this test ("the draft is absent") is a property of the scope. The
        // POSITIVE half ("the live entry is present") was, until this line, an accident: the entry
        // was indexed by the observer, so its stored vector is the embedding of its own unrelated
        // body, and its RANK among the `chunk_top_k` passages the meaning leg pulls was therefore
        // arbitrary. With two passages in the base it always made the cut; with enough passages
        // competing it does not, and the test then fails for a reason that has nothing to do with
        // drafts — the exact shape of the intermittent red this file has produced.
        //
        // Aligning it also makes the fixture strictly MORE hostile, which is this file's whole
        // method: the two passages now carry BYTE-IDENTICAL vectors and are tied at every rank, so
        // the only thing separating them is the filter under test.
        $this->indexedChunk($live, $query);

        // The keyword leg: the draft's title is a better literal match than the live entry's.
        $scoped = $this->getJson("/api/knowledge/bases/{$this->base->id}/search?q=" . urlencode($query))->assertOk();
        $this->assertFalse($scoped->json('meta.vector_search_skipped'), 'the meaning leg must actually have run');
        $this->assertNotContains((string) $draft->id, $this->idsOf($scoped->json('data')));
        $this->assertContains((string) $live->id, $this->idsOf($scoped->json('data')));

        // And the workspace-wide endpoint, which spans bases and re-derives the same scope.
        $global = $this->getJson('/api/knowledge/search?q=' . urlencode($query))->assertOk();
        $this->assertNotContains((string) $draft->id, $this->idsOf($global->json('data')));
    }

    // ---- the graph: nodes AND edges --------------------------------------------------------

    /**
     * A draft is neither a circle nor a line — asserted with a MATERIALISED edge row pointing at it.
     *
     * Drafts draw no links today, so the natural fixture proves nothing. The row is written by hand:
     * the graph must drop the edge because one of its endpoints is not a visible node, which is the
     * invariant the canvas lays itself out on.
     */
    public function test_the_base_graph_shows_neither_a_draft_node_nor_an_edge_touching_one(): void
    {
        $live = $this->live();
        $other = $this->live(['title' => 'Drugi wpis', 'slug' => 'drugi-wpis']);
        $draft = $this->draft($this->composerSession());

        KnowledgeLink::factory()->between($live, $other)->create();
        // Hostile fixture: an edge a draft would never draw, written directly.
        KnowledgeLink::factory()->between($draft, $live)->create();
        KnowledgeLink::factory()->between($live, $draft)->create();

        $graph = $this->getJson("/api/knowledge/bases/{$this->base->id}/graph")->assertOk()->json('data');

        $nodeIds = array_column($graph['nodes'], 'id');
        $this->assertNotContains((string) $draft->id, $nodeIds);
        $this->assertContains((string) $live->id, $nodeIds);

        foreach ($graph['edges'] as $edge) {
            $this->assertNotSame((string) $draft->id, $edge['from'], 'no edge may start at a draft');
            $this->assertNotSame((string) $draft->id, $edge['to'], 'no edge may end at a draft');
            // The invariant itself, restated: every endpoint that survives IS a node.
            $this->assertContains($edge['from'], $nodeIds);
            $this->assertContains($edge['to'], $nodeIds);
        }
    }

    /**
     * The LIVE entry's own link panels: a draft pointing at it must not appear as a backlink.
     *
     * This is the surface a reader actually looks at, and it is served by a relation on
     * `knowledge_links` — a table with no draft column — so the defence is the `fromEntry` relation
     * resolving through the scope. Worth its own row because a null endpoint could also render as an
     * empty card rather than being dropped, which would leak the EXISTENCE of a proposal.
     */
    public function test_a_live_entrys_backlinks_never_include_a_draft(): void
    {
        $live = $this->live();
        $draft = $this->draft($this->composerSession());

        KnowledgeLink::factory()->between($draft, $live)->create();
        KnowledgeLink::factory()->between($live, $draft)->source(KnowledgeLinkSource::SIMILARITY)->create();

        $payload = $this->getJson("/api/knowledge/entries/{$live->id}")->assertOk()->json('data');

        foreach ($payload['backlinks'] ?? [] as $backlink) {
            $this->assertNotSame((string) $draft->id, (string) ($backlink['entry']['id'] ?? null));
        }

        foreach ($payload['links'] ?? [] as $link) {
            $this->assertNotSame((string) $draft->id, (string) ($link['entry']['id'] ?? null));
        }
    }

    // ---- the bot's context: INLINE and RAG ---------------------------------------------------

    /**
     * The one that actually matters: the text a BOT quotes to a customer as fact.
     *
     * Both modes, and with the draft forced to `approved` — the status filter would otherwise do the
     * work and the scope would be untested. INLINE compiles the base; RAG ranks passages, so the draft
     * is given an indexed passage aligned to the bot's query as well.
     */
    public function test_the_bot_context_never_carries_a_draft_in_either_binding_mode(): void
    {
        $query = 'jak dlugo trwaja zwroty';

        $draft = $this->draft($this->composerSession(), [
            'status' => KnowledgeEntryStatus::APPROVED,
            'content' => 'TAJNA TRESC SZKICU, ktora nie moze trafic do kontekstu bota.',
        ]);
        $this->indexedChunk($draft, $query);

        $inline = app(KnowledgeCompiler::class)->compileForBinding($this->binding(KnowledgeBindingMode::INLINE), 8000);
        $this->assertNull($inline, 'a base holding only an (approved) draft must compile to nothing');

        $rag = app(KnowledgeRetrievalService::class)->forBinding($this->binding(KnowledgeBindingMode::RAG), $query, 8000);
        $this->assertNull($rag, 'retrieval must not be able to reach a draft passage either');

        // And once a real entry exists, the draft is still absent from what does compile.
        $live = $this->live(['content' => 'Jawna tresc, ktora moze trafic do kontekstu.']);
        $this->indexedChunk($live, $query);

        foreach ([KnowledgeBindingMode::INLINE, KnowledgeBindingMode::RAG] as $mode) {
            $compiled = app(KnowledgeRetrievalService::class)->forBinding($this->binding($mode), $query, 8000);

            $this->assertNotNull($compiled);
            $this->assertStringNotContainsString('TAJNA TRESC SZKICU', $compiled->text, "mode {$mode->value} leaked a draft");
            $this->assertStringContainsString('Jawna tresc', $compiled->text);
            // The RECEIPT too, not only the prose: a consumer records `entry_ids` as "what the model
            // was shown", and a draft named there would be an audit trail claiming an approval.
            $this->assertNotContains((string) $draft->id, $compiled->entryIds);
            $this->assertContains((string) $live->id, $compiled->entryIds);
        }
    }

    /**
     * A TYPED RELATION touching a draft is neither an edge nor a reason for a node — the row written
     * by hand, because the composer draws none until acceptance.
     *
     * A NEW SURFACE, and it needed its own row rather than riding on the wikilink one above. Relations
     * live in a different table, are walked by a different query, and — unlike a link — they EXPAND the
     * node set: `relationRows()` pulls a neighbour into the graph purely for being related, at the
     * maximum ranking weight. So a relation is the one edge kind that can drag a draft onto the canvas
     * as a NODE rather than merely drawing a line to one, which is a strictly worse leak: the circle
     * carries the draft's title.
     */
    public function test_a_typed_relation_touching_a_draft_is_neither_an_edge_nor_a_node(): void
    {
        $live = $this->live();
        $other = $this->live(['title' => 'Drugi wpis', 'slug' => 'drugi-wpis']);
        $draft = $this->draft($this->composerSession());

        KnowledgeRelation::factory()->between($live, $other)->create();
        // Hostile: relations a composer would only ever write on acceptance, in both directions.
        KnowledgeRelation::factory()->between($draft, $live)->create();
        KnowledgeRelation::factory()->between($live, $draft)->create();

        $graph = $this->getJson("/api/knowledge/bases/{$this->base->id}/graph?relations=1")->assertOk()->json('data');

        $nodeIds = array_column($graph['nodes'], 'id');

        $this->assertNotContains((string) $draft->id, $nodeIds, 'a relation must not pull a draft onto the canvas');
        $this->assertContains((string) $live->id, $nodeIds);

        foreach ($graph['edges'] as $edge) {
            $this->assertNotSame((string) $draft->id, $edge['from']);
            $this->assertNotSame((string) $draft->id, $edge['to']);
            $this->assertContains($edge['from'], $nodeIds);
            $this->assertContains($edge['to'], $nodeIds);
        }

        // And the entry's own reader panel, which reads the same table from one side.
        $payload = $this->getJson("/api/knowledge/entries/{$live->id}")->assertOk()->json('data');

        foreach ($payload['relations'] ?? [] as $relation) {
            $this->assertNotSame((string) $draft->id, (string) ($relation['from_entry_id'] ?? null));
            $this->assertNotSame((string) $draft->id, (string) ($relation['to_entry_id'] ?? null));
        }
    }

    /**
     * A SHADOW'S PROPOSED ADDITION is not knowledge either — and it is the leak with the worst
     * consequences, because a shadow's text is written to READ as part of an approved entry.
     *
     * The amendment columns arrived after the matrix was built, and they changed the shape of the
     * risk: an ordinary draft is a whole page nobody has approved, which a reader would at least
     * recognise as unfamiliar, while an append is one sentence engineered to sit inside a page they
     * already trust. If it reached retrieval it would be quoted with the target's authority.
     *
     * The fixture is hostile in the same way as the rest of the file: the shadow is forced to
     * `approved` and given an indexed passage, so the status filter and "a draft owns no chunks"
     * cannot do the work the scope is supposed to do.
     */
    public function test_an_amendment_shadows_proposed_text_reaches_neither_search_nor_a_bot(): void
    {
        $secret = 'ZAPROPONOWANY DOPISEK, ktorego nikt nie zatwierdzil';
        $query = 'termin zwrotow';

        $target = $this->live(['content' => 'Zwroty przyjmujemy zgodnie z regulaminem.']);

        $shadow = $this->draft($this->composerSession(), [
            'status' => KnowledgeEntryStatus::APPROVED,
            'title' => (string) $target->title,
            'slug' => ShadowSlug::mint(),
            'content' => $secret,
        ]);
        $shadow->forceFill([
            'targets_entry_id' => $target->id,
            'target_revision_id' => $target->current_revision_id,
            'amend_mode' => 'append',
            'amend_section' => null,
        ])->save();

        $this->indexedChunk($shadow, $query);
        $this->indexedChunk($target, $query);

        // The composed RESULT is what a reviewer is shown, and it really does contain the addition —
        // otherwise the assertions below would pass against an empty proposal.
        $this->assertStringContainsString($secret, $shadow->fresh()->amendedBody());

        // ...but nothing a reader or a bot can reach carries it.
        $hits = $this->getJson("/api/knowledge/bases/{$this->base->id}/search?q=" . urlencode($query))->assertOk();

        $this->assertNotContains((string) $shadow->id, $this->idsOf($hits->json('data')));
        $this->assertStringNotContainsString($secret, json_encode($hits->json()));

        foreach ([KnowledgeBindingMode::INLINE, KnowledgeBindingMode::RAG] as $mode) {
            $compiled = app(KnowledgeRetrievalService::class)->forBinding($this->binding($mode), $query, 8000);

            $this->assertNotNull($compiled, 'the target itself is perfectly good context');
            $this->assertStringNotContainsString($secret, $compiled->text, "mode {$mode->value} leaked a proposal");
            $this->assertNotContains((string) $shadow->id, $compiled->entryIds);
        }

        // And the entry the proposal targets is untouched until somebody accepts it.
        $this->assertStringNotContainsString($secret, (string) $target->fresh()->content);
    }

    private function binding(KnowledgeBindingMode $mode): KnowledgeBinding
    {
        return KnowledgeBinding::factory()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
            'mode' => $mode,
        ]);
    }

    // ---- the trash ---------------------------------------------------------------------------

    /** A REJECTED draft is soft-deleted, and the entry trash must not offer it back to a human. */
    public function test_the_trash_never_lists_a_rejected_draft(): void
    {
        $live = $this->live();
        $draft = $this->draft($this->composerSession());

        // REJECTING a draft is a route a person still has — refusing a proposal is what a reviewer is
        // for. TRASHING a published entry is not, so the live one is put in the bin the way the base
        // cascade and the erasure command do it.
        $this->deleteJson("/api/knowledge/entries/{$draft->id}/draft")->assertNoContent();
        $this->trashEntry($live);

        $trashed = $this->getJson("/api/knowledge/bases/{$this->base->id}/entries?trashed=1")->assertOk()->json('data');

        $this->assertSame([(string) $live->id], $this->idsOf($trashed));

        // Nor by raw id. This is the mechanism the listing above rides on, asserted directly because it
        // is what the withdrawn restore endpoint used to resolve through: the draft scope hides a
        // rejected proposal even from a `withTrashed()` lookup, so nothing that reaches for one by id
        // finds it either.
        $this->assertNull(KnowledgeEntry::withTrashed()->whereKey($draft->id)->first());
        $this->assertNotNull(KnowledgeEntry::withTrashed()->withDrafts()->whereKey($draft->id)->first());
    }

    // ---- erasure ------------------------------------------------------------------------------

    /**
     * SUBJECT PURGE — a draft is an ordinary row to an erasure request.
     *
     * This test used to pin the OPPOSITE, as a decision to review: the scan lifted the soft-delete
     * filter but not the draft scope, so a draft ENTRY was invisible while its own REVISIONS were not
     * (revisions carry no draft column). That combination was the worst of both — apply deleted the
     * history and left the live draft text, name and all, sitting in the session.
     *
     * Resolved in favour of "nothing is left behind": invisibility is a UI property, not a storage one,
     * and "nobody can see it" is a different claim from "it is not there". The draft is reported and
     * purged like any other row; its target, if it is a shadow, is untouched.
     */
    public function test_the_subject_purge_reaches_a_draft_entry_as_an_ordinary_row(): void
    {
        $subject = 'Jan Kowalski';

        $live = $this->live(['content' => "Sprawa dotyczy {$subject} i jego zamowienia."]);

        $session = $this->composerSession();
        $draft = $this->draft($session, ['content' => "Szkic wspomina {$subject} po imieniu."]);
        $draft->revisions()->create([
            'workspace_id' => $this->workspace->id,
            'title' => (string) $draft->title,
            'content' => "Poprzednia wersja szkicu o {$subject}.",
            'metadata' => [],
        ]);

        $phrases = SubjectPhrases::fromInput([$subject]);
        $service = app(KnowledgeSubjectPurgeService::class);

        $report = $service->scan((string) $this->workspace->id, $phrases);

        $entryIds = array_map(static fn ($match): string => $match->id, $report->entries);

        $this->assertContains((string) $live->id, $entryIds, 'the live entry naming the subject must be found');
        $this->assertContains((string) $draft->id, $entryIds, 'the DRAFT must be found too — it holds the same name');

        // Flagged, so the operator can see that some of what is being destroyed is unapproved output.
        $draftMatch = collect($report->entries)->firstWhere('id', (string) $draft->id);
        $this->assertTrue($draftMatch->isDraft);
        $this->assertSame(1, $report->draftEntriesPurged());

        // The draft's history is NOT double-counted: its entry is being purged, so the cascade takes it.
        $this->assertNotContains(
            (string) $draft->id,
            array_map(static fn ($match): string => $match->entryId, $report->revisions),
        );

        $service->apply($report, $phrases);

        $this->assertNull(KnowledgeEntry::query()->withDrafts()->withTrashed()->find($draft->id));
        $this->assertSame(0, $draft->revisions()->count());
    }

    /** A shadow proposal is erased; the LIVE entry it merely proposed to amend is not. */
    public function test_purging_a_shadow_draft_leaves_its_target_standing(): void
    {
        $subject = 'Jan Kowalski';

        $target = $this->live(['content' => 'Tresc bez zadnych nazwisk.']);

        $session = $this->composerSession();
        $shadow = $this->draft($session, ['content' => "Propozycja zmiany wspominajaca {$subject}."]);
        $shadow->forceFill([
            'slug' => ShadowSlug::mint(),
            'targets_entry_id' => $target->id,
            'target_revision_id' => $target->current_revision_id,
        ])->save();

        $phrases = SubjectPhrases::fromInput([$subject]);
        $service = app(KnowledgeSubjectPurgeService::class);

        $service->apply($service->scan((string) $this->workspace->id, $phrases), $phrases);

        $this->assertNull(KnowledgeEntry::query()->withDrafts()->withTrashed()->find($shadow->id));
        $this->assertNotNull($target->fresh(), 'the entry the proposal targeted is not the subject of the erasure');
    }

    /**
     * THE gap nothing else reached: the raw material somebody PASTED into the composer.
     *
     * It is never chunked, never indexed and referenced by no entry, so purging every entry in the
     * workspace would have left the name sitting in `source_text`. A matched session is abandoned
     * whole — the only honest granularity for an opaque blob.
     */
    public function test_a_session_whose_pasted_material_names_the_subject_is_abandoned(): void
    {
        $subject = 'Jan Kowalski';

        $session = $this->composerSession(['source_text' => "Notatka ze spotkania z {$subject} o budzecie."]);
        $draft = $this->draft($session, ['content' => 'Tresc szkicu bez nazwiska.']);

        $phrases = SubjectPhrases::fromInput([$subject]);
        $service = app(KnowledgeSubjectPurgeService::class);

        $report = $service->scan((string) $this->workspace->id, $phrases);

        $this->assertCount(1, $report->sessions);
        $this->assertSame((string) $session->id, $report->sessions[0]->id);
        $this->assertSame(['source_text'], $report->sessions[0]->matchedIn);
        $this->assertSame(1, $report->sessions[0]->drafts);

        $service->apply($report, $phrases);

        $this->assertNull(KnowledgeDraftSession::query()->find($session->id));
        $this->assertNull(
            KnowledgeEntry::query()->withDrafts()->withTrashed()->find($draft->id),
            'abandoning takes the session\'s drafts with it',
        );
    }

    /** The instruction history is user-typed text too — "rewrite the part about X" IS the name. */
    public function test_a_session_whose_prompt_history_names_the_subject_is_abandoned(): void
    {
        $subject = 'Jan Kowalski';

        $session = $this->composerSession(['source_text' => 'Material bez nazwisk.']);
        $session->forceFill(['prompt_history' => [
            ['at' => now()->toISOString(), 'instruction' => "Rozwin fragment o {$subject}."],
        ]])->save();

        $phrases = SubjectPhrases::fromInput([$subject]);
        $service = app(KnowledgeSubjectPurgeService::class);

        $report = $service->scan((string) $this->workspace->id, $phrases);

        $this->assertCount(1, $report->sessions);
        $this->assertSame(['prompt_history'], $report->sessions[0]->matchedIn);

        $service->apply($report, $phrases);

        $this->assertNull(KnowledgeDraftSession::query()->find($session->id));
    }

    /** A session nobody's material names is left alone. */
    public function test_an_unrelated_session_is_not_touched(): void
    {
        $session = $this->composerSession(['source_text' => 'Material o zwrotach towaru.']);

        $phrases = SubjectPhrases::fromInput(['Jan Kowalski']);
        $service = app(KnowledgeSubjectPurgeService::class);

        $report = $service->scan((string) $this->workspace->id, $phrases);

        $this->assertSame([], $report->sessions);

        $service->apply($report, $phrases);

        $this->assertNotNull($session->fresh());
    }

    // ---- ghost adoption -------------------------------------------------------------------------

    /**
     * A ghost is a `[[link]]` at a slug nobody has written yet. A DRAFT occupying that slug must not
     * satisfy it — the reference would resolve to text no human has approved, and the red-link chip
     * would go green for work that has not happened.
     *
     * Acceptance is what adopts it, and the second half of this test is what proves the first half is
     * a scope effect rather than a broken adoption pass.
     */
    public function test_a_ghost_is_adopted_by_acceptance_and_never_by_the_draft_itself(): void
    {
        // Through the API, so the wikilink pass really runs and really leaves a ghost behind.
        $authorId = (string) $this->makeEntry(base: $this->base, title: 'Regulamin', content: 'Patrz [[cennik]] po szczegoly.')->id;

        $ghost = KnowledgeLink::query()->where('from_entry_id', $authorId)->where('target_slug', 'cennik')->firstOrFail();
        $this->assertNull($ghost->to_entry_id, 'the fixture must start as a ghost, or this test proves nothing');

        // Through the SERVICE with a session id — the composer's own path, so the adoption pass runs
        // and is given every chance to resolve the red link onto the draft.
        $session = $this->composerSession();
        $draft = app(KnowledgeEntryService::class)->create(
            $this->base,
            new KnowledgeEntryDTO(
                title: 'Cennik',
                content: 'Proponowany cennik hurtowy.',
                metadata: [],
                status: KnowledgeEntryStatus::PROPOSED,
                staleAt: null,
            ),
            (string) $session->id,
        );

        $this->assertSame('cennik', $draft->slug, 'the draft must occupy the very slug the ghost names');
        $this->assertNull($ghost->fresh()->to_entry_id, 'a draft must not satisfy a red link');

        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [(string) $draft->id],
            'status' => 'approved',
        ])->assertOk();

        $this->assertSame(
            (string) $draft->id,
            (string) $ghost->fresh()->to_entry_id,
            'acceptance is what adopts the ghost',
        );
    }

    // ---- the base's aggregate counters -------------------------------------------------------------

    /** The numbers on a base card: a draft must move none of them. */
    public function test_the_base_aggregates_do_not_count_drafts(): void
    {
        $this->live();

        $before = $this->getJson("/api/knowledge/bases/{$this->base->id}")->assertOk()->json('data');

        $session = $this->composerSession();
        $draft = $this->draft($session);
        KnowledgeLink::factory()->ghost($draft, 'nieistniejacy-wpis')->create();

        $after = $this->getJson("/api/knowledge/bases/{$this->base->id}")->assertOk()->json('data');

        $this->assertSame($before['entries_count'], $after['entries_count']);
        $this->assertSame($before['index_summary'], $after['index_summary']);
        $this->assertSame($before['ghost_links_count'], $after['ghost_links_count']);
        $this->assertSame(1, $after['index_summary']['total']);

        // The list endpoint computes the same aggregates through a different path — pin both.
        $listed = collect($this->getJson('/api/knowledge/bases')->assertOk()->json('data'))
            ->firstWhere('id', (string) $this->base->id);

        $this->assertSame(1, $listed['entries_count']);
        $this->assertSame(0, $listed['ghost_links_count']);
    }

    // ---- one session cannot see another's work -------------------------------------------------------

    /**
     * `withDrafts()` is an escape hatch from the DRAFT scope only — never from tenancy, and never from
     * the session boundary. Two sessions in the same base must not read each other's proposals.
     */
    public function test_another_sessions_drafts_are_invisible_to_mine(): void
    {
        $mine = $this->composerSession();
        $theirs = $this->composerSession();

        $myDraft = $this->draft($mine, ['title' => 'Moj szkic', 'slug' => 'moj-szkic']);
        $theirDraft = $this->draft($theirs, ['title' => 'Cudzy szkic', 'slug' => 'cudzy-szkic']);

        $payload = $this->getJson("/api/knowledge/draft-sessions/{$mine->id}")->assertOk()->json('data');
        $draftIds = array_column($payload['drafts'], 'id');

        $this->assertSame([(string) $myDraft->id], $draftIds);

        // The relations preview reads the same relation, so it must agree.
        $relations = $this->getJson("/api/knowledge/draft-sessions/{$mine->id}/relations")->assertOk()->json('data');
        $this->assertNotContains((string) $theirDraft->id, array_column($relations['nodes'], 'id'));

        // And accepting cannot reach across, even by naming the id outright.
        $accepted = $this->postJson("/api/knowledge/draft-sessions/{$mine->id}/accept", [
            'entry_ids' => [(string) $theirDraft->id],
            'status' => 'approved',
        ])->assertOk();

        $this->assertSame([], $accepted->json('accepted'));
        $this->assertNotNull($theirDraft->fresh()->draft_session_id, 'the other session\'s draft is untouched');
    }

    /**
     * EVERY composer endpoint refuses a session from another workspace — at BINDING, before any policy.
     *
     * Tested per surface rather than once: the middleware order that makes this a 404 is app-wide, but
     * which endpoints are bound (rather than taking a raw id, as the base restore endpoints do) is a
     * per-route fact, and a new route added without the binding would pass a single generic test.
     */
    public function test_every_composer_endpoint_refuses_another_workspaces_session(): void
    {
        $stranger = User::factory()->create();
        $other = Workspace::factory()->create(['owner_id' => $stranger->id]);
        $otherBase = KnowledgeBase::factory()->create(['workspace_id' => $other->id]);
        $foreign = KnowledgeDraftSession::factory()->create([
            'workspace_id' => $other->id,
            'knowledge_base_id' => $otherBase->id,
        ]);

        $id = $foreign->id;

        $this->getJson("/api/knowledge/draft-sessions/{$id}")->assertNotFound();
        $this->getJson("/api/knowledge/draft-sessions/{$id}/relations")->assertNotFound();
        $this->postJson("/api/knowledge/draft-sessions/{$id}/refine", ['instruction' => 'krocej'])->assertNotFound();
        $this->postJson("/api/knowledge/draft-sessions/{$id}/accept", ['entry_ids' => [], 'status' => 'approved'])->assertNotFound();
        $this->postJson("/api/knowledge/draft-sessions/{$id}/rebase", ['entry_id' => (string) $foreign->id])->assertNotFound();
        $this->postJson("/api/knowledge/draft-sessions/{$id}/expand-context")->assertNotFound();
        $this->deleteJson("/api/knowledge/draft-sessions/{$id}")->assertNotFound();
        $this->getJson("/api/knowledge/bases/{$otherBase->id}/compose-availability")->assertNotFound();
        $this->postJson("/api/knowledge/bases/{$otherBase->id}/draft-sessions", ['source_text' => 'x'])->assertNotFound();
    }
}
