<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Agents\KnowledgeDraftAgent;
use App\Modules\Knowledge\Agents\KnowledgeMentionAgent;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Enums\KnowledgeEntryType;
use App\Modules\Knowledge\Enums\KnowledgeRelationState;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryRevision;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use App\Modules\Knowledge\Models\KnowledgeRelationEvent;
use App\Modules\Knowledge\Services\KnowledgeGraphOpsApplier;
use App\Modules\Knowledge\Support\DraftRunNotes;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * G5 — APPLYING a reviewed proposal, which is the only step of the composer that writes.
 *
 * The properties defended here are the ones that make an irreversible, partially-applicable,
 * possibly-retried write safe to put behind one button:
 *
 *   a GRAPH-ONLY answer is a success, because the commonest incremental update creates no entries;
 *   applying twice does the work ONCE, because a double click and a re-delivered job look identical;
 *   an APPEND never conflicts and a REWRITE still does, because only one of them is commutative;
 *   an entity and the relation that needs it land together or not at all;
 *   and nothing that did not happen is silent.
 */
class KnowledgeGraphApplyTest extends TestCase
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

    // ---- fixtures -------------------------------------------------------------------

    private function entry(string $title, ?KnowledgeEntryType $type = null, string $content = 'Tresc wpisu.'): KnowledgeEntry
    {
        return $this->makeEntry(
            base: $this->base,
            title: $title,
            content: $content,
            type: $type,
        );
    }

    private function compose(array $reply, array $mentions, string $source = 'Anna odeszla z Acme w lipcu.'): KnowledgeDraftSession
    {
        KnowledgeMentionAgent::fake(fn (): string => json_encode(['mentions' => $mentions], JSON_UNESCAPED_UNICODE));
        KnowledgeDraftAgent::fake(fn (): string => json_encode($reply, JSON_UNESCAPED_UNICODE));

        $id = $this->postJson("/api/knowledge/bases/{$this->base->id}/draft-sessions", [
            'source_text' => $source,
        ])->assertCreated()->json('data.id');

        return KnowledgeDraftSession::query()->findOrFail($id)->refresh();
    }

    private function accept(KnowledgeDraftSession $session, array $entryIds = [])
    {
        return $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => $entryIds === [] ? [(string) \Illuminate\Support\Str::uuid()] : $entryIds,
            'status' => 'approved',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function mention(string $text): array
    {
        return [['text' => $text, 'kind' => 'person', 'context' => 'kontekst']];
    }

    // ---- the graph-only contract ------------------------------------------------------

    /**
     * THE RULE THIS BATCH SETTLED: an answer that changes only the graph is a SUCCESS.
     *
     * "Anna left Acme in July" names two entries that already exist and creates none. Under a "no
     * entries means failure" rule that update was unmakeable through the composer — the user would be
     * told the run produced nothing while the server held a perfectly good proposal.
     */
    public function test_a_graph_only_answer_is_ready_and_applies(): void
    {
        $anna = $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);

        $this->makeRelation($this->base, $anna, $acme, KnowledgeRelationType::MEMBER_OF);

        $session = $this->compose(
            ['entries' => [], 'graph_updates' => [['op' => 'end', 'relation' => 'R1', 'valid_to' => '2026-07-01']]],
            $this->mention('Anna Kowalska'),
        );

        $this->assertSame('ready', $session->refresh()->status?->value, 'a graph-only run is not a failure');
        $this->assertSame(0, $session->drafts()->count());

        $response = $this->accept($session)->assertOk();

        $this->assertCount(1, $response->json('relations'));
        $this->assertSame(KnowledgeRelationState::ENDED->value, KnowledgeRelation::query()->firstOrFail()->state?->value);
        $this->assertSame('2026-07-01', KnowledgeRelation::query()->firstOrFail()->valid_to?->toDateString());
    }

    /** BOTH halves empty is still a failure — there is nothing to show a reviewer. */
    public function test_an_answer_with_neither_entries_nor_graph_work_fails(): void
    {
        $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON);

        $session = $this->compose(['entries' => []], $this->mention('Anna Kowalska'));

        $this->assertSame('failed', $session->refresh()->status?->value);
        $this->assertSame('empty', $session->failure_reason);
    }

    // ---- idempotency --------------------------------------------------------------------

    /**
     * Applying twice does the work ONCE.
     *
     * A double click, a re-delivered job and a browser retrying a timed-out POST are indistinguishable
     * from the server's side, and the ledger is what makes the second one a no-op rather than a second
     * relation. This codebase has paid for that lesson before.
     */
    public function test_accepting_the_same_proposal_twice_applies_it_once(): void
    {
        $anna = $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON, 'Anna pracuje.');
        $this->entry('Bob Nowak', KnowledgeEntryType::PERSON);

        $session = $this->compose([
            'entries' => [],
            'wiki_updates' => [['entity' => 'E1', 'op' => 'append', 'section' => 'Kalendarium', 'content' => '- 2026-07-01: odeszla.']],
            'graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'knows']],
        ], [
            ['text' => 'Anna Kowalska', 'kind' => 'person', 'context' => 'k'],
            ['text' => 'Bob Nowak', 'kind' => 'person', 'context' => 'k'],
        ]);

        $this->accept($session)->assertOk();
        $revisionsAfterFirst = KnowledgeEntryRevision::query()->where('knowledge_entry_id', $anna->id)->count();

        $second = $this->accept($session->refresh())->assertOk();

        $this->assertSame(1, KnowledgeRelation::query()->count(), 'one relation, not two');
        $this->assertSame(
            $revisionsAfterFirst,
            KnowledgeEntryRevision::query()->where('knowledge_entry_id', $anna->id)->count(),
            'no second revision',
        );

        $codes = array_column($second->json('skipped'), 'code');
        $this->assertContains(KnowledgeGraphOpsApplier::SKIP_ALREADY_APPLIED, $codes, 'the repeat is REPORTED, not silent');
    }

    // ---- the reviewed content channel -------------------------------------------------------

    /**
     * A `wiki_updates` change to an EXISTING entry becomes a REVIEWABLE draft, and touches nothing
     * until somebody accepts that draft.
     *
     * This is the defect the batch exists for. The operation used to reach the graph applier, which
     * resolved the entity by slug and wrote whenever ANY draft in the session was accepted — no card,
     * no diff, no chance to refuse — while the same conceptual change sent as an `entries` amendment
     * went through the whole shadow machinery. One channel walked around the other's safeguards.
     */
    public function test_a_content_change_to_an_existing_entry_is_reviewable_before_it_lands(): void
    {
        $original = "Anna pracuje.\n\n## Kalendarium\n\n- 2026-01-01: start.";
        $anna = $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON, $original);

        $session = $this->compose([
            // NOTE: no `entries` at all — the proposal arrives purely through the other channel.
            'entries' => [],
            'wiki_updates' => [['entity' => 'E1', 'op' => 'append', 'section' => 'Kalendarium', 'content' => '- 2026-07-01: odeszla.']],
        ], $this->mention('Anna Kowalska'));

        // A CARD EXISTS, before anything is written.
        $shadow = $session->drafts()->firstOrFail();

        $this->assertTrue($shadow->isShadow());
        $this->assertTrue($shadow->isAppendShadow());
        $this->assertSame((string) $anna->id, (string) $shadow->targets_entry_id);
        $this->assertSame($anna->current_revision_id, $shadow->target_revision_id);

        // ...and the reviewer can see exactly what would change.
        $this->assertStringContainsString('- 2026-07-01: odeszla.', $shadow->amendedBody());
        $this->assertStringContainsString('Anna pracuje.', $shadow->amendedBody());

        // Nothing has been written.
        $this->assertSame($original, (string) $anna->fresh()->content);
    }

    /** Accepting an UNRELATED draft leaves that entry alone — the batch no longer fires wholesale. */
    public function test_accepting_an_unrelated_draft_does_not_touch_the_proposed_entry(): void
    {
        $anna = $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON, 'Anna pracuje.');

        $session = $this->compose([
            'entries' => [['action' => 'create', 'slug' => 'nowy', 'title' => 'Nowy', 'content' => 'Tresc.', 'metadata' => []]],
            'wiki_updates' => [['entity' => 'E1', 'op' => 'append', 'content' => '- dopisek.']],
        ], $this->mention('Anna Kowalska'));

        $unrelated = $session->drafts()->whereNull('targets_entry_id')->firstOrFail();

        $this->accept($session, [(string) $unrelated->id])->assertOk();

        $this->assertSame('Anna pracuje.', (string) $anna->fresh()->content, 'the un-accepted proposal did not fire');
    }

    /**
     * AN APPEND IS STILL COMMUTATIVE — the property was kept, not traded away for the review gate.
     *
     * The shadow stores the ADDITION and composes from the live text at acceptance, so "add this dated
     * line" applies cleanly over somebody else's edit instead of becoming a 409.
     */
    public function test_an_append_survives_an_edit_made_after_the_composition(): void
    {
        $anna = $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON, "Anna pracuje.\n\n## Kalendarium\n\n- 2026-01-01: start.");

        $session = $this->compose([
            'entries' => [],
            'wiki_updates' => [['entity' => 'E1', 'op' => 'append', 'section' => 'Kalendarium', 'content' => '- 2026-07-01: odeszla.']],
        ], $this->mention('Anna Kowalska'));

        $shadow = $session->drafts()->firstOrFail();

        // SOMEBODY ELSE'S AMENDMENT LANDS ON THE TARGET between the composition and the click.
        $this->updateEntry($anna, content: "Anna pracuje od 2020.\n\n## Kalendarium\n\n- 2026-01-01: start.");

        $response = $this->accept($session, [(string) $shadow->id])->assertOk();

        $this->assertSame([], $response->json('conflicts'), 'an append never conflicts');

        $content = (string) $anna->fresh()->content;
        $this->assertStringContainsString('Anna pracuje od 2020.', $content, "the other person's edit survived");
        $this->assertStringContainsString('- 2026-07-01: odeszla.', $content, 'and so did the append');
        // Chronological: the new line goes at the END of the section, not under the heading.
        $this->assertTrue(mb_strpos($content, '2026-01-01') < mb_strpos($content, '2026-07-01'));
    }

    /**
     * A REWRITE still carries its lock, so a moved target is a conflict a human settles.
     *
     * The token is `target_revision_id`, frozen when the composer read the entry — the B11b mechanism,
     * which this channel now SHARES instead of having its own weaker one.
     */
    public function test_a_rewrite_of_a_moved_target_conflicts(): void
    {
        $anna = $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON, 'Anna pracuje.');

        $session = $this->compose([
            'entries' => [],
            'wiki_updates' => [['entity' => 'E1', 'op' => 'rewrite', 'content' => 'Zupelnie nowa tresc.']],
        ], $this->mention('Anna Kowalska'));

        $shadow = $session->drafts()->firstOrFail();

        $this->assertSame('rewrite', $shadow->amend_mode);
        $this->assertSame($anna->current_revision_id, $shadow->target_revision_id);

        $this->updateEntry($anna, content: 'Ktos inny to zmienil.');

        $response = $this->accept($session, [(string) $shadow->id])->assertOk();

        $this->assertCount(1, $response->json('conflicts'));
        $this->assertSame('Ktos inny to zmienil.', (string) $anna->fresh()->content, 'nothing was overwritten');
    }

    /** ...and an unopposed rewrite applies. A lock that refuses everything is not a lock. */
    public function test_a_rewrite_without_a_concurrent_edit_applies(): void
    {
        $anna = $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON, 'Anna pracuje.');

        $session = $this->compose([
            'entries' => [],
            'wiki_updates' => [['entity' => 'E1', 'op' => 'rewrite', 'content' => 'Zupelnie nowa tresc.']],
        ], $this->mention('Anna Kowalska'));

        $shadow = $session->drafts()->firstOrFail();

        $this->accept($session, [(string) $shadow->id])->assertOk()->assertJsonPath('conflicts', []);

        $this->assertSame('Zupelnie nowa tresc.', (string) $anna->fresh()->content);
    }

    /**
     * The model cannot switch its own lock off — and now it cannot even name the field.
     *
     * The revision is taken from the frozen context when the shadow is built; the model's answer is not
     * consulted for it at any point on this path.
     */
    public function test_a_revision_smuggled_by_the_model_does_not_disable_the_lock(): void
    {
        $anna = $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON, 'Anna pracuje.');

        $session = $this->compose([
            'entries' => [],
            'wiki_updates' => [[
                'entity' => 'E1',
                'op' => 'rewrite',
                'content' => 'Zupelnie nowa tresc.',
                'expected_revision_id' => 'rev-wymyslona-przez-model',
                'target_revision_id' => 'rev-wymyslona-przez-model',
            ]],
        ], $this->mention('Anna Kowalska'));

        $shadow = $session->drafts()->firstOrFail();

        $this->assertSame($anna->current_revision_id, $shadow->target_revision_id, 'the smuggled value is ignored');

        $this->updateEntry($anna, content: 'Ktos inny to zmienil.');

        $this->accept($session, [(string) $shadow->id])->assertOk()->assertJsonCount(1, 'conflicts');
        $this->assertSame('Ktos inny to zmienil.', (string) $anna->fresh()->content);
    }

    // ---- selecting which relation operations apply --------------------------------------------

    /** Three proposed relations, ONE chosen: exactly one is written, and the other two say so. */
    public function test_a_subset_of_relation_operations_applies_exactly_that_subset(): void
    {
        $anna = $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON);
        $this->entry('Bob Nowak', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);

        $session = $this->compose([
            'entries' => [],
            'graph_updates' => [
                ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'knows'],
                ['op' => 'create', 'from' => 'E1', 'to' => 'E3', 'type' => 'member_of'],
                ['op' => 'create', 'from' => 'E2', 'to' => 'E3', 'type' => 'member_of'],
            ],
        ], [
            ['text' => 'Anna Kowalska', 'kind' => 'person', 'context' => 'k'],
            ['text' => 'Bob Nowak', 'kind' => 'person', 'context' => 'k'],
            ['text' => 'Acme', 'kind' => 'organization', 'context' => 'k'],
        ]);

        $this->assertCount(3, $session->graphOps()['graph_updates']);

        $response = $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [],
            'graph_op_keys' => ['graph:1'],
            'status' => 'approved',
        ])->assertOk();

        $this->assertCount(1, $response->json('relations'));
        $this->assertSame(1, KnowledgeRelation::query()->count());

        $relation = KnowledgeRelation::query()->firstOrFail();
        $this->assertSame((string) $anna->id, (string) $relation->from_entry_id);
        $this->assertSame((string) $acme->id, (string) $relation->to_entry_id);

        // THE REFUSALS ARE VISIBLE. A "no" that leaves no trace reads exactly like a bug that dropped
        // the operation, and the reviewer cannot tell which happened.
        $notSelected = array_values(array_filter(
            $response->json('skipped'),
            static fn (array $skip): bool => $skip['code'] === KnowledgeGraphOpsApplier::SKIP_NOT_SELECTED,
        ));

        $this->assertCount(2, $notSelected);
        $this->assertEqualsCanonicalizing(['graph:0', 'graph:2'], array_column($notSelected, 'op'));
    }

    /** No list at all means ALL of them — the compatible reading, for a client that does not select. */
    public function test_omitting_the_selection_applies_everything(): void
    {
        $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON);
        $this->entry('Bob Nowak', KnowledgeEntryType::PERSON);

        $session = $this->compose([
            'entries' => [],
            'graph_updates' => [
                ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'knows'],
                ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'opposes'],
            ],
        ], [
            ['text' => 'Anna Kowalska', 'kind' => 'person', 'context' => 'k'],
            ['text' => 'Bob Nowak', 'kind' => 'person', 'context' => 'k'],
        ]);

        $this->accept($session)->assertOk();

        $this->assertSame(2, KnowledgeRelation::query()->count());
    }

    /** An EMPTY list is a reviewer refusing every relation — not the same as not selecting at all. */
    public function test_an_empty_selection_applies_nothing(): void
    {
        $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON);
        $this->entry('Bob Nowak', KnowledgeEntryType::PERSON);

        $session = $this->compose([
            'entries' => [],
            'graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'knows']],
        ], [
            ['text' => 'Anna Kowalska', 'kind' => 'person', 'context' => 'k'],
            ['text' => 'Bob Nowak', 'kind' => 'person', 'context' => 'k'],
        ]);

        $response = $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [],
            'graph_op_keys' => [],
            'status' => 'approved',
        ])->assertOk();

        $this->assertSame(0, KnowledgeRelation::query()->count());
        $this->assertSame(
            [KnowledgeGraphOpsApplier::SKIP_NOT_SELECTED],
            array_column($response->json('skipped'), 'code'),
        );
    }

    /**
     * A key the proposal does not contain is a 422, not a silent skip.
     *
     * It means the CLIENT IS HOLDING A STALE PREVIEW — almost always because the draft was refined and
     * the operations renumbered. Applying the keys it did recognise would give the reviewer some of
     * what they approved and none of the rest, without saying so.
     */
    public function test_an_unknown_operation_key_is_refused(): void
    {
        $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON);
        $this->entry('Bob Nowak', KnowledgeEntryType::PERSON);

        $session = $this->compose([
            'entries' => [],
            'graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'knows']],
        ], [
            ['text' => 'Anna Kowalska', 'kind' => 'person', 'context' => 'k'],
            ['text' => 'Bob Nowak', 'kind' => 'person', 'context' => 'k'],
        ]);

        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [],
            'graph_op_keys' => ['graph:0', 'graph:7'],
            'status' => 'approved',
        ])->assertStatus(422)->assertJsonValidationErrors('graph_op_keys');

        $this->assertSame(0, KnowledgeRelation::query()->count(), 'a refused request writes nothing at all');
    }

    /** The key the PREVIEW offers is the key `accept` takes — one string, server-owned. */
    public function test_the_preview_publishes_the_key_accept_expects(): void
    {
        $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON);
        $this->entry('Bob Nowak', KnowledgeEntryType::PERSON);

        $session = $this->compose([
            'entries' => [],
            'graph_updates' => [
                ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'knows'],
                ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'opposes'],
            ],
        ], [
            ['text' => 'Anna Kowalska', 'kind' => 'person', 'context' => 'k'],
            ['text' => 'Bob Nowak', 'kind' => 'person', 'context' => 'k'],
        ]);

        $keys = array_column(
            $this->getJson("/api/knowledge/draft-sessions/{$session->id}/relations")->assertOk()->json('data.proposed_relations'),
            'key',
        );

        $this->assertSame(['graph:0', 'graph:1'], $keys);

        // ...and handing one straight back applies exactly that operation.
        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [],
            'graph_op_keys' => [$keys[1]],
            'status' => 'approved',
        ])->assertOk();

        $this->assertSame('opposes', KnowledgeRelation::query()->firstOrFail()->relation_type?->value);
    }

    // ---- replacements ----------------------------------------------------------------------

    /** A replacement fixture: Anna is a member of Acme, and the run moves her to Inna firma. */
    private function replacementSession(): KnowledgeDraftSession
    {
        $anna = $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);
        $this->entry('Inna firma', KnowledgeEntryType::ORGANIZATION);

        $this->makeRelation($this->base, $anna, $acme, KnowledgeRelationType::MEMBER_OF);

        return $this->compose([
            'entries' => [],
            'graph_updates' => [
                ['op' => 'end', 'relation' => 'R1', 'valid_to' => '2026-07-01'],
                ['op' => 'create', 'from' => 'E1', 'to' => 'E3', 'type' => 'member_of', 'replaces' => 'R1'],
            ],
        ], [
            ['text' => 'Anna Kowalska', 'kind' => 'person', 'context' => 'k'],
            ['text' => 'Acme', 'kind' => 'organization', 'context' => 'k'],
            ['text' => 'Inna firma', 'kind' => 'organization', 'context' => 'k'],
        ]);
    }

    /**
     * A REPLACEMENT: the old relation ends AND points at the one that took its place.
     *
     * Direction is old → new, which is what the column was built for — a reader following a fact that
     * has stopped being true arrives at the one that replaced it, instead of at a dead end.
     */
    public function test_a_replacement_links_the_old_relation_to_the_new_one(): void
    {
        $session = $this->replacementSession();

        $this->assertSame('R1', $session->graphOps()['graph_updates'][1]['replaces']);

        $oldId = (string) KnowledgeRelation::query()->firstOrFail()->id;

        $this->accept($session)->assertOk();

        $old = KnowledgeRelation::query()->findOrFail($oldId);
        $new = KnowledgeRelation::query()->whereKeyNot($oldId)->firstOrFail();

        $this->assertSame(KnowledgeRelationState::ENDED->value, $old->state?->value);
        $this->assertSame((string) $new->id, (string) $old->superseded_by_id, 'the old fact points at the new one');
        $this->assertSame('2026-07-01', $old->valid_to?->toDateString(), 'the ending date the run chose survives');

        // THE LOG CARRIES THE LINK, not only the column.
        $supersede = KnowledgeRelationEvent::query()
            ->where('op', KnowledgeRelationEvent::OP_SUPERSEDE)
            ->where('relation_id', $oldId)
            ->firstOrFail();

        $this->assertSame((string) $new->id, $supersede->after['superseded_by_id']);
    }

    /** The pair is marked on BOTH halves in the preview, so a client can group them. */
    public function test_the_preview_marks_both_halves_of_a_replacement(): void
    {
        $session = $this->replacementSession();

        $proposed = $this->getJson("/api/knowledge/draft-sessions/{$session->id}/relations")
            ->assertOk()->json('data.proposed_relations');

        $this->assertSame('graph:1', $proposed[0]['pair_with'], 'the end points at its replacement');
        $this->assertSame('graph:0', $proposed[1]['pair_with'], 'and the replacement points back');
        $this->assertSame('R1', $proposed[1]['replaces']);
    }

    /**
     * SPLITTING A PAIR IS REFUSED, not silently completed.
     *
     * Auto-including the missing half would write something the reviewer never ticked — the same class
     * of defect as a checkbox that does not do what it says. It is not a dead end either: taking both
     * works, and taking neither is always allowed.
     */
    public function test_selecting_half_a_replacement_is_refused(): void
    {
        $session = $this->replacementSession();

        foreach ([['graph:0'], ['graph:1']] as $half) {
            $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
                'entry_ids' => [],
                'graph_op_keys' => $half,
                'status' => 'approved',
            ])->assertStatus(422)->assertJsonValidationErrors('graph_op_keys');
        }

        $this->assertSame(1, KnowledgeRelation::query()->count(), 'a refused request writes nothing');

        // ...and BOTH together is accepted, so the refusal is a guard rather than a wall.
        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [],
            'graph_op_keys' => ['graph:0', 'graph:1'],
            'status' => 'approved',
        ])->assertOk();

        $this->assertSame(2, KnowledgeRelation::query()->count());
    }

    /** Refusing BOTH halves is always allowed — the pair is inseparable, not compulsory. */
    public function test_refusing_a_whole_replacement_is_allowed(): void
    {
        $session = $this->replacementSession();

        $response = $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [],
            'graph_op_keys' => [],
            'status' => 'approved',
        ])->assertOk();

        $this->assertSame(1, KnowledgeRelation::query()->count());
        $this->assertCount(2, $response->json('skipped'));
    }

    // ---- the wikilink guard ----------------------------------------------------------------

    /**
     * A rewrite that drops links the target carries is allowed through, LOUDLY.
     *
     * A refusal would be wrong on the merits — a rewrite that removes a paragraph is supposed to remove
     * its links — but the diff alone is not enough. It shows that text changed; it does not say "this
     * deletes an outgoing edge somebody wrote on purpose", and that is precisely the change a reviewer
     * skims past, because the remaining sentence reads perfectly well.
     */
    public function test_a_rewrite_that_drops_wikilinks_is_reported_with_the_list(): void
    {
        $this->entry('Cennik', null, 'Cennik uslug.');
        $this->entry('Zwroty', null, 'Polityka zwrotow.');
        $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON, 'Anna pisze o [[cennik]] i [[zwroty]].');

        $session = $this->compose([
            'entries' => [],
            'wiki_updates' => [['entity' => 'E1', 'op' => 'rewrite', 'content' => 'Anna pisze o [[cennik]].']],
        ], $this->mention('Anna Kowalska'));

        $note = collect($session->runNotes())->firstWhere('code', DraftRunNotes::AMEND_LINKS_LOST);

        $this->assertNotNull($note, 'losing a link damages OTHER entries, not just this one');
        $this->assertSame(['zwroty'], $note['links'], 'the reviewer is told WHICH link, not merely that one went');

        // ...and the proposal still stands: it is a warning, not a veto.
        $this->assertCount(1, $session->drafts()->get());
    }

    /** A rewrite that keeps every link says nothing — a guard that always fires is noise. */
    public function test_a_rewrite_that_keeps_its_links_is_silent(): void
    {
        $this->entry('Cennik', null, 'Cennik uslug.');
        $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON, 'Anna pisze o [[cennik]].');

        $session = $this->compose([
            'entries' => [],
            'wiki_updates' => [['entity' => 'E1', 'op' => 'rewrite', 'content' => 'Anna pisze duzo o [[Cennik]].']],
        ], $this->mention('Anna Kowalska'));

        $this->assertNull(
            collect($session->runNotes())->firstWhere('code', DraftRunNotes::AMEND_LINKS_LOST),
            'a link kept under a different case is not lost',
        );
    }

    // ---- what the reviewer is SHOWN ---------------------------------------------------------

    /**
     * THE CARD AND THE DIFF MUST STATE THE EFFECT OF ACCEPTING, not the shape of the stored row.
     *
     * An append shadow stores its ADDITION alone — the property that keeps it commutative — so a client
     * rendering `content` would tell the reviewer that the whole entry is about to be replaced by one
     * sentence, which is the opposite of what the button does. A diff that misstates the outcome is
     * worse than no diff: the reviewer is not uninformed, they are confidently wrong.
     */
    public function test_an_append_shadow_publishes_its_mode_and_its_result(): void
    {
        $original = "Anna pracuje.\n\n## Kalendarium\n\n- 2026-01-01: start.";
        $anna = $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON, $original);

        $session = $this->compose([
            'entries' => [],
            'wiki_updates' => [['entity' => 'E1', 'op' => 'append', 'section' => 'Kalendarium', 'content' => '- 2026-07-01: odeszla.']],
        ], $this->mention('Anna Kowalska'));

        $shadow = $session->drafts()->firstOrFail();

        $card = collect($this->getJson("/api/knowledge/draft-sessions/{$session->id}")->assertOk()->json('data.drafts'))
            ->firstWhere('id', (string) $shadow->id);

        $this->assertSame('append', $card['amend_mode']);
        $this->assertSame('Kalendarium', $card['amend_section']);
        $this->assertSame('- 2026-07-01: odeszla.', $card['content'], 'the raw column is still the addition');
        // ...and the field a card should actually render.
        $this->assertStringContainsString('Anna pracuje.', $card['amended_body']);
        $this->assertStringContainsString('- 2026-07-01: odeszla.', $card['amended_body']);

        // THE DIFF: `from` is the target's revision, `to` is the RESULT — so the picture is "one line
        // added", not "everything replaced".
        $diff = $this->getJson("/api/knowledge/entries/{$shadow->id}/draft-diff?baseline=target")->assertOk();

        $this->assertSame($original, $diff->json('from.content'));
        $this->assertStringContainsString('Anna pracuje.', $diff->json('to.content'));
        $this->assertStringContainsString('- 2026-07-01: odeszla.', $diff->json('to.content'));
        $this->assertNotSame('- 2026-07-01: odeszla.', $diff->json('to.content'), 'the raw addition is not the diff');
    }

    /** A REWRITE is unchanged, byte for byte — `amended_body` is its own content. */
    public function test_a_rewrite_shadow_is_reported_exactly_as_before(): void
    {
        $anna = $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON, 'Anna pracuje.');

        $session = $this->compose([
            'entries' => [],
            'wiki_updates' => [['entity' => 'E1', 'op' => 'rewrite', 'content' => 'Zupelnie nowa tresc.']],
        ], $this->mention('Anna Kowalska'));

        $shadow = $session->drafts()->firstOrFail();

        $card = collect($this->getJson("/api/knowledge/draft-sessions/{$session->id}")->assertOk()->json('data.drafts'))
            ->firstWhere('id', (string) $shadow->id);

        $this->assertSame('rewrite', $card['amend_mode']);
        $this->assertNull($card['amend_section']);
        $this->assertSame('Zupelnie nowa tresc.', $card['amended_body']);

        $this->assertSame(
            'Zupelnie nowa tresc.',
            $this->getJson("/api/knowledge/entries/{$shadow->id}/draft-diff?baseline=target")->assertOk()->json('to.content'),
        );
    }

    /** A plain draft is not an amendment, so the three fields are absent rather than null-shaped. */
    public function test_a_plain_draft_carries_no_amend_fields(): void
    {
        $session = $this->compose(
            ['entries' => [['action' => 'create', 'slug' => 'nowy', 'title' => 'Nowy', 'content' => 'Tresc.', 'metadata' => []]]],
            $this->mention('Anna Kowalska'),
        );

        $card = $this->getJson("/api/knowledge/draft-sessions/{$session->id}")->assertOk()->json('data.drafts.0');

        $this->assertArrayNotHasKey('amend_mode', $card);
        $this->assertArrayNotHasKey('amended_body', $card);
    }

    /** The result is composed from the target's LIVE text, so the card cannot go stale. */
    public function test_the_shown_result_follows_a_concurrent_edit_of_the_target(): void
    {
        $anna = $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON, 'Anna pracuje.');

        $session = $this->compose([
            'entries' => [],
            'wiki_updates' => [['entity' => 'E1', 'op' => 'append', 'content' => '- dopisek.']],
        ], $this->mention('Anna Kowalska'));

        $this->updateEntry($anna, content: 'Anna pracuje od 2020.');

        $shadow = $session->drafts()->firstOrFail();
        $card = collect($this->getJson("/api/knowledge/draft-sessions/{$session->id}")->assertOk()->json('data.drafts'))
            ->firstWhere('id', (string) $shadow->id);

        // The same composition the publish path will perform, so what is shown is what will happen.
        $this->assertStringContainsString('Anna pracuje od 2020.', $card['amended_body']);
        $this->assertStringContainsString('- dopisek.', $card['amended_body']);
    }

    // ---- entities and relations as one act -------------------------------------------------

    /**
     * A new subject and the relation that needs it land TOGETHER.
     *
     * "Anna met Bob" is not two decisions: a Bob with no relation is a stub nobody asked for, and the
     * relation cannot exist without him.
     *
     * BOB IS AN ENTRY CARRYING A `ref`, not a separately declared entity. The model no longer declares
     * entities at all — it writes the entry and gives it a handle, and the server synthesises the
     * declaration from the laundered draft. So Bob arrives as a review CARD like any other new page,
     * and the reviewer accepting that card is what lets the relation bind to it.
     */
    public function test_a_new_entity_and_its_relation_are_created_together(): void
    {
        $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON);

        $session = $this->compose([
            'entries' => [[
                'action' => 'create',
                'ref' => 'N1',
                'slug' => 'bob-nowak',
                'title' => 'Bob Nowak',
                'type' => 'person',
                'content' => 'Bob Nowak jest znajomym Anny.',
                'metadata' => [],
            ]],
            'graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'N1', 'type' => 'knows', 'description' => 'Poznali sie.']],
        ], $this->mention('Anna Kowalska'));

        $response = $this->accept($session, [(string) $session->drafts()->firstOrFail()->id])->assertOk();

        $bob = KnowledgeEntry::query()->where('title', 'Bob Nowak')->first();

        $this->assertNotNull($bob, 'the subject the relation needed was published');
        $this->assertSame(KnowledgeEntryType::PERSON, $bob->entry_type);
        $this->assertCount(1, $response->json('relations'));

        $relation = KnowledgeRelation::query()->firstOrFail();
        $this->assertSame((string) $bob->id, (string) $relation->to_entry_id);
        // Provenance: a model proposed it and a person approved it. That is not the same as "a human
        // drew this", and the base has to be able to tell them apart later.
        $this->assertSame('composer', $relation->origin?->value);
        $this->assertSame((string) $session->id, (string) $relation->draft_session_id);
    }

    /** Every mutation writes its audit line, in the same transaction as the mutation. */
    public function test_applying_writes_the_audit_trail(): void
    {
        $anna = $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);

        $this->makeRelation($this->base, $anna, $acme, KnowledgeRelationType::MEMBER_OF);

        $session = $this->compose(
            ['entries' => [], 'graph_updates' => [['op' => 'end', 'relation' => 'R1', 'valid_to' => '2026-07-01']]],
            $this->mention('Anna Kowalska'),
        );

        $this->accept($session)->assertOk();

        $this->assertSame(1, KnowledgeRelationEvent::query()->where('op', KnowledgeRelationEvent::OP_END)->count());
    }

    // ---- skipped, never silent ---------------------------------------------------------------

    /** A relation whose entity was NOT accepted in this batch is reported, not thrown. */
    public function test_a_relation_whose_dependency_is_missing_is_skipped_with_a_reason(): void
    {
        $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON);

        $session = $this->compose([
            'entries' => [],
            // The relation names N1, but no entity declares it — the dependency cannot be satisfied.
            'graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'N1', 'type' => 'knows']],
        ], $this->mention('Anna Kowalska'));

        // The launderer already refuses an unknown handle, so this proposal reaches the applier empty —
        // which is itself the point: a dependency that cannot be met never becomes a write.
        $this->assertSame([], $session->graphOps()['graph_updates']);
        $this->assertNotSame([], $session->graphOps()['rejected']);
    }

    /**
     * A target trashed between composition and acceptance surfaces as a CONFLICT, not a silent no-op.
     *
     * It reaches the shadow path, which is the right home for it: the proposal has a card, so its
     * failure belongs on the same card the reviewer clicked, next to the other reasons an amendment
     * cannot be applied. The graph applier has no code of its own for this — it never emitted one, and
     * the `entity_gone` constant that suggested otherwise has been removed.
     */
    public function test_a_target_that_vanished_is_reported(): void
    {
        $anna = $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON, 'Anna pracuje.');

        $session = $this->compose([
            'entries' => [],
            'wiki_updates' => [['entity' => 'E1', 'op' => 'append', 'content' => '- nowa linia.']],
        ], $this->mention('Anna Kowalska'));

        $shadow = $session->drafts()->firstOrFail();

        // The target goes into the trash under the proposal — the base cascade or the erasure command,
        // which are the two things that can still do this.
        $this->trashEntry($anna);

        $response = $this->accept($session, [(string) $shadow->id])->assertOk();

        $this->assertSame([], $response->json('accepted'), 'nothing was published');
        $this->assertCount(1, $response->json('conflicts'));
    }

    /** A relation somebody else ended first is skipped — the outcome the reviewer wanted is already true. */
    public function test_a_relation_ended_by_somebody_else_is_skipped(): void
    {
        $anna = $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);

        $relation = $this->makeRelation($this->base, $anna, $acme, KnowledgeRelationType::MEMBER_OF);

        $session = $this->compose(
            ['entries' => [], 'graph_updates' => [['op' => 'end', 'relation' => 'R1', 'valid_to' => '2026-07-01']]],
            $this->mention('Anna Kowalska'),
        );

        // Somebody else's accepted proposal ends the same relation first.
        $this->endRelation($relation);

        $response = $this->accept($session)->assertOk();

        $codes = array_column($response->json('skipped'), 'code');
        $this->assertContains(KnowledgeGraphOpsApplier::SKIP_RELATION_GONE, $codes);
    }

    // ---- the merged context ---------------------------------------------------------------

    /**
     * NAMED ∪ TOPICAL, de-duplicated.
     *
     * The two legs answer different questions, and a base needs both: a document that names Anna and
     * is also about the refund policy should arrive with BOTH entries, and an entry that is both named
     * and on topic is one entity with the stronger provenance rather than two.
     */
    public function test_the_context_merges_named_and_topical_entries(): void
    {
        $anna = $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON, 'Anna prowadzi zespol.');

        $session = $this->compose(['entries' => []], $this->mention('Anna Kowalska'));

        $handles = array_column($session->resolutionSet()['entities'], 'handle');
        $matched = array_column($session->resolutionSet()['entities'], 'matched_by');

        // Anna appears ONCE even though she is both named and (being the only indexed entry) the
        // nearest topical match.
        $this->assertSame(count($handles), count(array_unique($handles)));
        // `exact`, because the mention IS the title — the cheapest rung of the ladder, reached before
        // anything was embedded on this entity's behalf.
        $this->assertContains('exact', $matched);
        $this->assertSame(1, count(array_filter(
            $session->resolutionSet()['entities'],
            static fn (array $entity): bool => $entity['slug'] === $anna->slug,
        )), 'named and topical are de-duplicated by entry');
    }

    /** The amendment allow-list still reads from the merge — the silent-degradation trap. */
    public function test_the_amendment_allow_list_reads_from_the_merged_context(): void
    {
        $target = $this->entry('Zwroty', null, 'Polityka zwrotow towaru.');

        $session = $this->compose(
            ['entries' => [['action' => 'update', 'targets_slug' => $target->slug, 'title' => 'Zwroty', 'content' => 'Zwroty w 30 dni.', 'metadata' => []]]],
            [['text' => 'Zwroty', 'kind' => 'concept', 'context' => 'zmiana']],
            'Zwroty przyjmujemy w 30 dni.',
        );

        $draft = $session->drafts()->firstOrFail();

        $this->assertTrue($draft->isShadow(), 'an empty allow-list would silently make this a NEW entry');
        $this->assertSame($target->current_revision_id, $draft->target_revision_id);
    }

    // ---- the flag ------------------------------------------------------------------------

    public function test_with_the_flag_off_accept_behaves_exactly_as_before(): void
    {
        config()->set('knowledge.graph_extraction.enabled', false);

        $session = $this->compose(
            ['entries' => [['action' => 'create', 'slug' => 'nowy', 'title' => 'Nowy', 'content' => 'Tresc.', 'metadata' => []]]],
            $this->mention('Anna Kowalska'),
        );

        $draft = $session->drafts()->firstOrFail();

        $response = $this->accept($session, [(string) $draft->id])->assertOk();

        $this->assertCount(1, $response->json('accepted'));
        $this->assertSame([], $response->json('relations'));
        $this->assertSame([], $response->json('skipped'));
        $this->assertSame(0, KnowledgeRelation::query()->count());
    }
}
