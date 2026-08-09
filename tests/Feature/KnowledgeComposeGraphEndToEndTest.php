<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Agents\KnowledgeDraftAgent;
use App\Modules\Knowledge\Agents\KnowledgeMentionAgent;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Enums\KnowledgeEntryType;
use App\Modules\Knowledge\Enums\KnowledgeRelationState;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use App\Modules\Knowledge\Models\KnowledgeRelationEvent;
use App\Modules\Knowledge\Services\KnowledgeGraphOpsApplier;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Knowledge\Support\KnowledgeGraphOps;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * G8 — THE WHOLE CHAPTER, ONCE, AS A USER LIVES IT.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY ONE LONG TEST RATHER THAN TWELVE SHORT ONES
 *
 * Every stage of the Wiki-Graph already has its own file, and each of them proves its stage in
 * isolation against a fixture built by hand for it: the resolution suite starts from a mention list
 * nobody extracted, the launderer's unit suite starts from a reply nobody generated, and the apply
 * suite starts from a `graph_ops` column nobody laundered. That is the right way to test each stage —
 * and it is precisely why none of them can fail when two stages stop agreeing about the shape they
 * hand each other. A handle renamed between the freeze and the launderer, a `wiki_updates` entry
 * absorbed into a shadow at one layer and still expected at the next, an operation key that means one
 * thing to the preview and another to `accept`: every one of those is green everywhere and broken in
 * production.
 *
 * So this file runs ONE session end to end, with nothing hand-built between the stages, and asserts
 * the things a user would notice. It is deliberately the only test in the module that does that, and
 * it is deliberately long: splitting it would reintroduce the seams it exists to cross.
 *
 * ------------------------------------------------------------------------------------------------
 * WHAT IS FAKED, AND WHAT IS NOT
 *
 * The two AI calls are faked at the AGENT (`::fake()`), which is the module's own seam — everything
 * downstream of the provider's bytes is the real code, including the JSON decoding, the fence guard,
 * the laundering and the whole apply path. Embeddings run through {@see FakeKnowledgeEmbedder}, which
 * is deterministic and COUNTS, so "this cost no vector call" is an assertion rather than a hope.
 *
 * Nothing else is stubbed. The database is real, the observers fire, the queue is synchronous, and
 * every read goes through the HTTP API the frontend actually calls.
 */
class KnowledgeComposeGraphEndToEndTest extends TestCase
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

        config()->set('knowledge.graph_extraction.enabled', true);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- fixtures -------------------------------------------------------------------------

    private function entry(string $title, ?KnowledgeEntryType $type = null, string $content = 'Tresc wpisu.'): KnowledgeEntry
    {
        return $this->makeEntry(
            base: $this->base,
            title: $title,
            content: $content,
            type: $type,
        );
    }

    private function compose(array $reply, array $mentions, string $source): KnowledgeDraftSession
    {
        KnowledgeMentionAgent::fake(fn (): string => json_encode(['mentions' => $mentions], JSON_UNESCAPED_UNICODE));
        KnowledgeDraftAgent::fake(fn (): string => json_encode($reply, JSON_UNESCAPED_UNICODE));

        $id = $this->postJson("/api/knowledge/bases/{$this->base->id}/draft-sessions", [
            'source_text' => $source,
        ])->assertCreated()->json('data.id');

        return KnowledgeDraftSession::query()->findOrFail($id)->refresh();
    }

    private function chunkCount(KnowledgeEntry $entry): int
    {
        return KnowledgeEntryChunk::query()
            ->withoutEmbedding()
            ->where('knowledge_entry_id', $entry->getKey())
            ->whereNotNull('indexed_at')
            ->count();
    }

    // ---- the chapter ------------------------------------------------------------------------

    /**
     * A note somebody pasted in, from raw text to a changed base — every stage, one session.
     *
     * The material is the ordinary case the whole feature was built for: it calls an existing person by
     * their FIRST NAME ONLY, states a fact about them that belongs in their own entry, and names a
     * company the base has never heard of. Getting that right requires all three phases to agree:
     * phase 1 must recognise "Łukasz" without paying for a vector, phase 2 must be able to address him
     * by a handle and propose an amendment plus a typed relation, and phase 3 must apply exactly the
     * subset a reviewer ticked.
     */
    public function test_a_pasted_note_becomes_an_amended_entry_a_new_entity_and_a_dated_relation(): void
    {
        // --- the base as it stands ---------------------------------------------------------
        $lukasz = $this->entry(
            'Łukasz Barszcz',
            KnowledgeEntryType::PERSON,
            "Łukasz prowadzi projekt zwrotów od marca.\n\n## Kalendarium\n\n- 2026-03-01: objął projekt.",
        );
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION, 'Acme sprzedaje elektronikę.');

        $oldRelationId = (string) $this->makeRelation(
            $this->base,
            $lukasz,
            $acme,
            KnowledgeRelationType::MEMBER_OF,
            description: 'Pracuje w Acme od 2024.',
        )->id;

        // --- what the two models will say --------------------------------------------------
        $this->embedder->reset();

        $session = $this->compose(
            [
                // A PLAIN new draft, deliberately included so the acceptance below is a real SUBSET
                // choice rather than "accept everything" wearing a list.
                'entries' => [[
                    'action' => 'create',
                    'slug' => 'polityka-zwrotow-2026',
                    'title' => 'Polityka zwrotów 2026',
                    'type' => 'concept',
                    'content' => 'Zwroty przyjmujemy w 30 dni.',
                    'metadata' => [],
                ], [
                    // THE NEW SUBJECT, as an ordinary entry carrying a HANDLE. There is no `entities`
                    // section any more: the model writes the page and claims `N1` on it, and the server
                    // synthesises the declaration from the laundered draft — one channel, so "Kwadratura"
                    // cannot arrive twice and become `kwadratura` and `kwadratura-2`.
                    'action' => 'create',
                    'ref' => 'N1',
                    'slug' => 'kwadratura',
                    'title' => 'Kwadratura',
                    'type' => 'organization',
                    'content' => 'Kwadratura to software house z Wrocławia.',
                    'metadata' => [],
                ]],
                'wiki_updates' => [
                    // Aimed at an entry that EXISTS — this one must be absorbed into a shadow.
                    ['entity' => 'E1', 'op' => 'append', 'section' => 'Kalendarium', 'content' => '- 2026-07-01: przeszedł do Kwadratury.'],
                ],
                'graph_updates' => [
                    ['op' => 'end', 'relation' => 'R1', 'valid_to' => '2026-07-01'],
                    ['op' => 'create', 'from' => 'E1', 'to' => 'N1', 'type' => 'member_of', 'description' => 'Przeszedł do Kwadratury.', 'valid_from' => '2026-07-01', 'replaces' => 'R1'],
                ],
            ],
            [['text' => 'Łukasz', 'kind' => 'person', 'context' => 'Łukasz przeszedł do Kwadratury']],
            'Łukasz przeszedł od lipca do Kwadratury. Zwroty przyjmujemy w 30 dni.',
        );

        // ================================================================================
        // PHASE 1 — the freeze, and what it did NOT cost
        // ================================================================================

        $resolution = $session->resolutionSet();

        $this->assertCount(1, $resolution['entities']);
        $this->assertSame('E1', $resolution['entities'][0]['handle']);
        $this->assertSame('Łukasz Barszcz', $resolution['entities'][0]['title']);
        $this->assertSame([], $resolution['ambiguous'], 'one Łukasz in the base is not an ambiguity');
        $this->assertSame([], $resolution['unresolved']);

        // ZERO VECTOR WORK ON THE NAME, pinned by the counter rather than described in a comment.
        //
        // A first name is the case the deterministic ladder exists for, and it is also the case that
        // silently falls through to the kNN rung if a rung above it regresses — which costs money on
        // every session and is invisible in the result, because the kNN pass usually finds the same
        // person. ONE batch carrying ONE text (the source, for the topical leg) is the whole spend.
        $this->assertSame(1, $this->embedder->calls, 'the freeze is one batch');
        $this->assertCount(1, $this->embedder->batches[0], 'a name matched lexically buys no vector of its own');

        // The relation was frozen with the entity, which is what let the model address it as `R1`.
        $this->assertSame('R1', $resolution['entities'][0]['relations'][0]['handle']);
        $this->assertSame('member_of', $resolution['entities'][0]['relations'][0]['type']);

        // ================================================================================
        // PHASE 2 — what the laundering kept, moved and refused
        // ================================================================================

        $ops = $session->graphOps();

        // THE ENTITY WAS SYNTHESISED FROM THE DRAFT, and carries the marker that binds the two.
        $this->assertCount(1, $ops['entities'], 'one create-draft claimed a handle');
        $this->assertSame('Kwadratura', $ops['entities'][0]['title']);
        $this->assertSame('N1', $ops['entities'][0]['ref']);
        $this->assertSame('organization', $ops['entities'][0]['entry_type']);
        $this->assertSame('kwadratura', $ops['entities'][0]['from_draft_slug'], 'the handle is bound to its draft');

        // `wiki_updates` IS EMPTY. The append aimed at Łukasz is gone from this channel entirely — not
        // dropped, MOVED to a review card, and the report says so. That is the invariant which closed
        // the hole where an existing entry's text could be rewritten with no card at all; and a new
        // entity's body no longer travels here either, because the entry itself carries it.
        $this->assertSame([], $ops['wiki_updates']);
        $this->assertContains(
            KnowledgeGraphOps::WARN_MOVED_TO_REVIEW,
            array_column($ops['warnings'], 'code'),
            'the reviewer is told the proposal moved rather than vanished',
        );

        $this->assertCount(2, $ops['graph_updates']);
        $this->assertSame('R1', $ops['graph_updates'][1]['replaces'], 'the replacement bound to the ending');

        // ================================================================================
        // THE REVIEW SCREEN — what a human is shown before they decide
        // ================================================================================

        $payload = $this->getJson("/api/knowledge/draft-sessions/{$session->id}")->assertOk()->json('data');

        $cards = collect($payload['drafts']);

        // THREE cards, and the third one is the point of the whole refactor: the new SUBJECT gets a
        // review card like anything else. It used to be a declared entity, which meant it was written
        // into the base with no card, no diff and nothing for a reviewer to refuse.
        $this->assertCount(3, $cards, 'the new page, the new subject and the amendment are all proposals');
        $this->assertSame('organization', $cards->firstWhere('title', 'Kwadratura')['entry_type']);

        $shadowCard = $cards->firstWhere('amend_mode', 'append');
        $this->assertNotNull($shadowCard, 'the absorbed wiki update is a reviewable amendment card');
        $this->assertSame('Kalendarium', $shadowCard['amend_section']);

        // THE CARD SHOWS THE RESULT, NOT THE ADDITION. A card rendering the stored column would tell
        // the reviewer that a person's whole entry is about to become one bullet point.
        $this->assertSame('- 2026-07-01: przeszedł do Kwadratury.', $shadowCard['content'], 'the column still holds the addition alone');
        $this->assertStringContainsString('Łukasz prowadzi projekt zwrotów', $shadowCard['amended_body']);
        $this->assertStringContainsString('- 2026-03-01: objął projekt.', $shadowCard['amended_body']);
        $this->assertStringContainsString('- 2026-07-01: przeszedł do Kwadratury.', $shadowCard['amended_body']);

        $newCard = $cards->firstWhere('title', 'Polityka zwrotów 2026');
        $this->assertNotNull($newCard);
        $this->assertArrayNotHasKey('amend_mode', $newCard, 'a plain draft is not an amendment');

        // The relation panel: both halves of the replacement, each pointing at the other, and the
        // operation keys the accept call is about to hand straight back.
        $preview = $this->getJson("/api/knowledge/draft-sessions/{$session->id}/relations")->assertOk()->json('data');

        $this->assertSame(['graph:0', 'graph:1'], array_column($preview['proposed_relations'], 'key'));
        $this->assertSame('graph:1', $preview['proposed_relations'][0]['pair_with']);
        $this->assertSame('graph:0', $preview['proposed_relations'][1]['pair_with']);
        $this->assertSame(['N1'], $preview['proposed_relations'][1]['depends_on_draft']);
        $this->assertSame('Łukasz Barszcz', $preview['proposed_relations'][1]['from_title']);
        $this->assertSame('Kwadratura', $preview['proposed_relations'][1]['to_title']);

        // NOTHING HAS BEEN WRITTEN YET. It is a proposal until a person says otherwise.
        $this->assertSame(1, KnowledgeRelation::query()->count(), 'still only the original relation');
        $this->assertNull(KnowledgeEntry::query()->where('title', 'Kwadratura')->first());
        $this->assertStringNotContainsString('Kwadratury', (string) $lukasz->fresh()->content);

        // A draft is never indexed, so no machine text can be reached by a search or a bot yet.
        $shadow = $session->drafts()->whereNotNull('targets_entry_id')->firstOrFail();
        $this->assertSame(0, $this->chunkCount($shadow), 'an unaccepted proposal owns no indexed passage');

        // ================================================================================
        // PHASE 3 — the reviewer accepts a SUBSET
        // ================================================================================

        $kwadraturaDraft = $session->drafts()->where('title', 'Kwadratura')->firstOrFail();

        $response = $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            // The amendment and the new SUBJECT yes; the unrelated new page no. The subject has to be
            // ticked now — it is a card, and the relation that names it binds to what the reviewer
            // published rather than minting its own copy.
            'entry_ids' => [(string) $shadow->id, (string) $kwadraturaDraft->id],
            'graph_op_keys' => ['graph:0', 'graph:1'],
            'status' => 'approved',
        ])->assertOk();

        $this->assertCount(2, $response->json('accepted'));
        $this->assertSame([], $response->json('conflicts'));
        // BOTH halves of the replacement are reported as work done — the ending is a write too, and a
        // response that named only the new relation would understate what the button just did.
        $this->assertCount(2, $response->json('relations'));

        // --- the existing entry was WIDENED, not replaced ----------------------------------
        $lukasz->refresh();

        $this->assertStringContainsString('Łukasz prowadzi projekt zwrotów od marca.', (string) $lukasz->content);
        $this->assertStringContainsString('- 2026-03-01: objął projekt.', (string) $lukasz->content);
        $this->assertStringContainsString('- 2026-07-01: przeszedł do Kwadratury.', (string) $lukasz->content);
        $this->assertNull($lukasz->draft_session_id, 'the target is a real entry, not a draft');

        // The shadow was a proposal and has been applied; it must not linger as a stale one.
        $this->assertNull(KnowledgeEntry::query()->withDrafts()->withTrashed()->find($shadow->id));

        // --- the declared entity became a real entry, with the body the run wrote -----------
        $kwadratura = KnowledgeEntry::query()->where('title', 'Kwadratura')->firstOrFail();

        $this->assertSame('Kwadratura to software house z Wrocławia.', (string) $kwadratura->content);
        $this->assertSame(KnowledgeEntryType::ORGANIZATION, $kwadratura->entry_type);
        $this->assertSame(KnowledgeEntryStatus::APPROVED, $kwadratura->status);

        // --- the relation, with its description and its dates -------------------------------
        $relation = KnowledgeRelation::query()->whereKeyNot($oldRelationId)->firstOrFail();

        $this->assertSame((string) $lukasz->id, (string) $relation->from_entry_id);
        $this->assertSame((string) $kwadratura->id, (string) $relation->to_entry_id);
        $this->assertSame('member_of', $relation->relation_type?->value);
        $this->assertSame('Przeszedł do Kwadratury.', $relation->description);
        $this->assertSame('2026-07-01', $relation->valid_from?->toDateString());
        $this->assertSame('composer', $relation->origin?->value, 'a model proposed it and a person approved it');

        // --- the fact that stopped being true POINTS AT the one that replaced it -------------
        $old = KnowledgeRelation::query()->findOrFail($oldRelationId);

        $this->assertSame(KnowledgeRelationState::ENDED->value, $old->state?->value);
        $this->assertSame('2026-07-01', $old->valid_to?->toDateString());
        $this->assertSame((string) $relation->id, (string) $old->superseded_by_id);

        // --- the event log carries the same story --------------------------------------------
        $this->assertSame(1, KnowledgeRelationEvent::query()->where('op', KnowledgeRelationEvent::OP_END)->where('relation_id', $oldRelationId)->count());
        $supersede = KnowledgeRelationEvent::query()
            ->where('op', KnowledgeRelationEvent::OP_SUPERSEDE)
            ->where('relation_id', $oldRelationId)
            ->firstOrFail();
        $this->assertSame((string) $relation->id, $supersede->after['superseded_by_id']);

        // --- INDEXING STARTED, and only after acceptance --------------------------------------
        //
        // The order is the whole point: nothing a machine wrote is embedded until a human has approved
        // it, and everything a human approves is embedded without them asking. Both halves are checked,
        // because either one alone is satisfied by a broken implementation (never indexing, or always).
        $this->assertGreaterThan(0, $this->chunkCount($kwadratura), 'the accepted entity was indexed');
        $this->assertGreaterThan(0, $this->chunkCount($lukasz), 'the amended entry was re-indexed');

        // --- and the subset really was a subset ------------------------------------------------
        $unaccepted = KnowledgeEntry::query()->withDrafts()->where('title', 'Polityka zwrotów 2026')->firstOrFail();

        $this->assertSame((string) $session->id, (string) $unaccepted->draft_session_id, 'the page nobody ticked is still a draft');
        $this->assertSame(0, $this->chunkCount($unaccepted), 'and it was never indexed');
    }

    // ---- the assumption the whole dependency UI stands on -------------------------------------

    /**
     * A RELATION IS ONLY EVER WRITTEN BETWEEN ENTRIES THAT EXIST BY THE END OF THIS ACCEPT.
     *
     * The composer panel greys a relation out while `depends_on_draft` names a draft the reviewer has
     * not accepted, and that affordance is only honest if the server would in fact refuse. Pinned from
     * the SERVER side, because the panel's own tests mock the store: they prove the row is greyed, not
     * that greying it was necessary.
     *
     * The hostile fixture is the one the panel cannot produce — the reviewer accepting NO drafts at all
     * while ticking a relation that names one.
     */
    public function test_a_relation_naming_a_draft_nobody_accepted_is_reported_rather_than_written(): void
    {
        $lukasz = $this->entry('Łukasz Barszcz', KnowledgeEntryType::PERSON);

        $session = $this->compose(
            [
                'entries' => [[
                    'action' => 'create',
                    'ref' => 'N1',
                    'slug' => 'kwadratura',
                    'title' => 'Kwadratura',
                    'type' => 'organization',
                    'content' => 'Software house z Wrocławia.',
                    'metadata' => [],
                ]],
                'graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'N1', 'type' => 'member_of']],
            ],
            [['text' => 'Łukasz', 'kind' => 'person', 'context' => 'k']],
            'Łukasz przeszedł do Kwadratury.',
        );

        $kwadratura = $session->drafts()->where('title', 'Kwadratura')->firstOrFail();

        // FIRST ACCEPT: the page is published, no relation is ticked. This is the state the three-valued
        // contract exists to express — "publish the pages, I will look at the graph later".
        $first = $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [(string) $kwadratura->id],
            'graph_op_keys' => [],
            'status' => 'approved',
        ])->assertOk();

        $this->assertSame(
            [KnowledgeGraphOpsApplier::SKIP_NOT_SELECTED],
            array_column($first->json('skipped'), 'code'),
            'the refusal is reported, never silent',
        );
        $this->assertSame(0, KnowledgeRelation::query()->count());

        // SECOND ACCEPT, the same session, now ticking the relation.
        $second = $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [],
            'graph_op_keys' => ['graph:0'],
            'status' => 'approved',
        ])->assertOk();

        // THE DEFERRED HALF COMPLETES — inverted from the G8 pin, and now for a second reason.
        //
        // `createDeclaredEntities()` used to record `entity:0` in `applied_ops` on the first pass and
        // return nothing on the second, so the handle map no longer carried `N1` even though the entry
        // it names existed. Every relation touching it came back `dependency_not_accepted` — a code
        // that reads like the reviewer's own decision — and the staged review this gate exists to
        // encourage ("publish the pages now, I will look at the graph in a minute") lost its second
        // half permanently, with nothing anywhere saying so.
        //
        // The handle now resolves by finding the PUBLISHED DRAFT it was minted from, so a second pass
        // re-offers the same entry however the first pass went.
        $this->assertCount(1, $second->json('relations'), 'the deferred relation was applied');
        $this->assertSame(1, KnowledgeRelation::query()->count());
        $this->assertNotContains(
            KnowledgeGraphOpsApplier::SKIP_DEPENDENCY_NOT_ACCEPTED,
            array_column($second->json('skipped'), 'code'),
        );

        // ONE ENTRY, and it is the one the reviewer published — not a second copy the graph half minted
        // for itself. This is the duplicate the single-channel refactor exists to make impossible:
        // `mintSlug()` would have de-collided it to `kwadratura-2` without a word.
        $published = KnowledgeEntry::query()->where('title', 'Kwadratura')->get();

        $this->assertCount(1, $published, 'the subject exists once and only once');
        $this->assertSame((string) $kwadratura->id, (string) $published->first()->id, 'and it is the reviewed one');
        $this->assertSame(
            (string) $published->first()->id,
            (string) KnowledgeRelation::query()->firstOrFail()->to_entry_id,
        );
        $this->assertNotNull($lukasz->fresh());
    }

    /**
     * The mirror case, and the one the panel offers most often: BOTH ends already exist, so the
     * reviewer publishes a relation while accepting no prose at all.
     *
     * `entry_ids: []` with a non-empty selection is a real request shape — a graph-only answer is the
     * commonest incremental update there is — and it used to be unexpressible, because the rule
     * demanded at least one id and the caller had to invent one.
     */
    public function test_a_relation_between_two_existing_entries_needs_no_accepted_draft_at_all(): void
    {
        $lukasz = $this->entry('Łukasz Barszcz', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);

        $session = $this->compose(
            [
                'entries' => [],
                'graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of', 'description' => 'Zatrudniony od lipca.']],
            ],
            [
                ['text' => 'Łukasz', 'kind' => 'person', 'context' => 'k'],
                ['text' => 'Acme', 'kind' => 'organization', 'context' => 'k'],
            ],
            'Łukasz pracuje w Acme.',
        );

        $this->assertSame(
            [],
            $this->getJson("/api/knowledge/draft-sessions/{$session->id}/relations")
                ->assertOk()->json('data.proposed_relations.0.depends_on_draft'),
            'neither end is a draft, so the panel offers the row unblocked',
        );

        $response = $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [],
            'graph_op_keys' => ['graph:0'],
            'status' => 'approved',
        ])->assertOk();

        $this->assertSame([], $response->json('accepted'), 'no prose was published');
        $this->assertCount(1, $response->json('relations'));

        $relation = KnowledgeRelation::query()->firstOrFail();

        $this->assertSame((string) $lukasz->id, (string) $relation->from_entry_id);
        $this->assertSame((string) $acme->id, (string) $relation->to_entry_id);
        $this->assertSame('Zatrudniony od lipca.', $relation->description);
        $this->assertSame((string) $session->id, (string) $relation->draft_session_id);
    }

    /**
     * `end R1` ENDS THE RELATION R1 NAMED — with a second relation of the same type in the base.
     *
     * THE GAP THIS CLOSES IS THE FIXTURE, not the assertion. Every graph test in the module had exactly
     * one relation per type, so the applier could look a handle up by TYPE, take the lowest id in the
     * whole base, and be right every single time. Six hundred green tests — and in any real base, where
     * `member_of` is the commonest edge there is, `end R1` ended somebody else's employment.
     *
     * The setup is therefore the point: TWO active `member_of` relations, and the one the session is
     * about is deliberately NOT the one that sorts first. Krystyna's is created first, so with ordered
     * uuids it is the row the old lookup always returned; the session resolves BARTOSZ, whose relation is
     * second. A regression re-ends Krystyna, deterministically.
     *
     * Only Bartosz is mentioned, so Krystyna is not in the frozen set at all — the sharpest form of the bug:
     * her relation was never shown to the model and never given a handle, and was still the row that
     * got written.
     */
    public function test_an_end_lands_on_the_frozen_relation_when_the_base_holds_two_of_that_type(): void
    {
        $krystyna = $this->entry('Krystyna Kowalska', KnowledgeEntryType::PERSON, 'Krystyna pracuje w Globexie.');
        $bartosz = $this->entry('Bartosz Nowak', KnowledgeEntryType::PERSON, 'Bartosz pracuje w Globexie.');
        $globex = $this->entry('Globex', KnowledgeEntryType::ORGANIZATION, 'Globex robi maszyny.');

        // KRYSTYNA'S FIRST — with ordered uuids this is the row the old type-and-lowest-id lookup returned
        // for every handle of this type, so a regression here fails every run rather than one in two.
        $krystynaRelationId = (string) $this->makeRelation(
            $this->base,
            $krystyna,
            $globex,
            KnowledgeRelationType::MEMBER_OF,
            description: 'Krystyna pracuje w Globexie od 2023.',
        )->id;

        $bartoszRelationId = (string) $this->makeRelation(
            $this->base,
            $bartosz,
            $globex,
            KnowledgeRelationType::MEMBER_OF,
            description: 'Bartosz pracuje w Globexie od 2024.',
        )->id;

        $session = $this->compose(
            ['graph_updates' => [['op' => 'end', 'relation' => 'R1', 'valid_to' => '2026-08-01']]],
            [['text' => 'Bartosz', 'kind' => 'person', 'context' => 'Bartosz odszedł z Globexu']],
            'Bartosz odszedł z Globexu z końcem lipca.',
        );

        // The freeze saw ONE person and ONE of the two relations, and it recorded WHICH ROW the handle
        // names. That id is the fix — a handle that has to be re-derived is not an identifier.
        $resolution = $session->resolutionSet();

        $this->assertCount(1, $resolution['entities'], 'only Bartosz was mentioned');
        $this->assertSame('Bartosz Nowak', $resolution['entities'][0]['title']);
        $this->assertCount(1, $resolution['entities'][0]['relations']);
        $this->assertSame('R1', $resolution['entities'][0]['relations'][0]['handle']);
        $this->assertSame($bartoszRelationId, $resolution['entities'][0]['relations'][0]['id']);

        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [],
            'status' => 'approved',
        ])->assertOk();

        // BARTOSZ'S ENDED...
        $bartoszRelation = KnowledgeRelation::query()->findOrFail($bartoszRelationId);

        $this->assertSame(KnowledgeRelationState::ENDED, $bartoszRelation->state);
        $this->assertSame('2026-08-01', $bartoszRelation->valid_to?->toDateString());

        // ...AND KRYSTYNA STILL WORKS THERE. She was never mentioned, never resolved, never given a handle.
        // The only thing she had in common with the operation was the verb.
        $krystynaRelation = KnowledgeRelation::query()->findOrFail($krystynaRelationId);

        $this->assertSame(KnowledgeRelationState::ACTIVE, $krystynaRelation->state);
        $this->assertNull($krystynaRelation->valid_to);
    }

    /**
     * ONE ROW, ONE HANDLE — even when both of its ends are in the set.
     *
     * The freeze lists a relation under each entity it touches, read from that entity's side, and that
     * is right: "Krystyna knows Bartosz" and "Bartosz knows Krystyna" are one fact told from two sides, and a composer
     * needs it on both entities. What was wrong was minting a FRESH handle per listing, so a single
     * edge was both `R1` and `R3`, and `end R1` and `end R3` were the same write. Nothing downstream
     * could tell, because nothing downstream knew which row a handle stood for.
     *
     * Direction and label still differ per side. Only the identity is shared.
     */
    public function test_a_relation_with_both_ends_in_the_set_gets_exactly_one_handle(): void
    {
        $krystyna = $this->entry('Krystyna Kowalska', KnowledgeEntryType::PERSON, 'Krystyna zna Bartosza.');
        $bartosz = $this->entry('Bartosz Nowak', KnowledgeEntryType::PERSON, 'Bartosz zna Krystyne.');

        $knowsId = (string) $this->makeRelation(
            $this->base,
            $krystyna,
            $bartosz,
            KnowledgeRelationType::KNOWS,
            description: 'Znają się z konferencji.',
        )->id;

        $session = $this->compose(
            ['graph_updates' => []],
            [
                ['text' => 'Krystyna', 'kind' => 'person', 'context' => 'Krystyna i Bartosz'],
                ['text' => 'Bartosz', 'kind' => 'person', 'context' => 'Krystyna i Bartosz'],
            ],
            'Krystyna i Bartosz pracowali razem nad projektem.',
        );

        $entities = $session->resolutionSet()['entities'];

        $this->assertCount(2, $entities, 'both people resolved');

        // The same row, located under each entity BY ITS ID, must carry the same handle.
        $listings = [];

        foreach ($entities as $entity) {
            foreach ($entity['relations'] as $relation) {
                if ($relation['id'] === $knowsId) {
                    $listings[] = $relation;
                }
            }
        }

        $this->assertCount(2, $listings, 'the edge is listed under both of its ends');
        $this->assertSame($listings[0]['handle'], $listings[1]['handle'], 'one row, one handle');
        $this->assertNotSame($listings[0]['direction'], $listings[1]['direction'], 'read from opposite sides');

        // And no handle anywhere in the set names two different rows.
        $byHandle = [];

        foreach ($entities as $entity) {
            foreach ($entity['relations'] as $relation) {
                $byHandle[$relation['handle']][$relation['id']] = true;
            }
        }

        foreach ($byHandle as $handle => $ids) {
            $this->assertCount(1, $ids, "handle {$handle} names exactly one relation");
        }
    }
}
