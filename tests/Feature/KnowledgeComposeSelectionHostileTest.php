<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Agents\KnowledgeDraftAgent;
use App\Modules\Knowledge\Agents\KnowledgeMentionAgent;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Enums\KnowledgeEntryType;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use App\Modules\Knowledge\Services\KnowledgeGraphOpsApplier;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * G8 — HOSTILE INPUT ON THE SURFACES THAT ARRIVED LAST.
 *
 * The launderer's own unit suite probes the MODEL's output. This file probes the two surfaces that
 * came after it and that a model cannot reach at all: the operation SELECTION a client sends back,
 * and the amendment columns a shadow carries into publication. Both are attacker-reachable in the
 * ordinary sense — a stale tab, a replayed request, a client written against last week's contract —
 * and both decide whether text lands in an approved entry.
 *
 * The standard every case is held to is the module's: nothing is written that should not be, the
 * refusal is a 422 or a reported skip rather than a 500, and a request that is refused writes NOTHING
 * AT ALL rather than the part the server happened to understand.
 */
class KnowledgeComposeSelectionHostileTest extends TestCase
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

    // ---- fixtures --------------------------------------------------------------------

    private function entry(string $title, ?KnowledgeEntryType $type = null, string $content = 'Tresc wpisu.'): KnowledgeEntry
    {
        return $this->makeEntry(
            base: $this->base,
            title: $title,
            content: $content,
            type: $type,
        );
    }

    private function compose(array $reply, array $mentions, string $source = 'Anna zmienila zespol.'): KnowledgeDraftSession
    {
        KnowledgeMentionAgent::fake(fn (): string => json_encode(['mentions' => $mentions], JSON_UNESCAPED_UNICODE));
        KnowledgeDraftAgent::fake(fn (): string => json_encode($reply, JSON_UNESCAPED_UNICODE));

        $id = $this->postJson("/api/knowledge/bases/{$this->base->id}/draft-sessions", [
            'source_text' => $source,
        ])->assertCreated()->json('data.id');

        return KnowledgeDraftSession::query()->findOrFail($id)->refresh();
    }

    /** @return array<int, array<string, mixed>> */
    private function mention(string $text, string $kind = 'person'): array
    {
        return [['text' => $text, 'kind' => $kind, 'context' => 'kontekst']];
    }

    private function twoPeople(): KnowledgeDraftSession
    {
        $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON);
        $this->entry('Bob Nowak', KnowledgeEntryType::PERSON);

        return $this->compose([
            'entries' => [],
            'graph_updates' => [
                ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'knows'],
                ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'opposes'],
            ],
        ], [
            ['text' => 'Anna Kowalska', 'kind' => 'person', 'context' => 'k'],
            ['text' => 'Bob Nowak', 'kind' => 'person', 'context' => 'k'],
        ]);
    }

    private function accept(KnowledgeDraftSession $session, array $payload)
    {
        return $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", $payload + [
            'entry_ids' => [],
            'status' => 'approved',
        ]);
    }

    // ---- the selection ----------------------------------------------------------------

    /**
     * THE SAME KEY TWICE IS REFUSED, rather than quietly de-duplicated.
     *
     * De-duplicating would be defensible on its own, and it is what the accessor does — but the RULE
     * runs first, and that ordering is the point: a client that sends a key twice is not a client that
     * meant it twice, it is a client whose selection state has a bug in it. Answering "fine" teaches
     * it that the state is sound.
     */
    public function test_a_duplicated_operation_key_is_refused(): void
    {
        $session = $this->twoPeople();

        // INVERTED from the G8 pin. The error used to hang off `graph_op_keys.<i>`, because `distinct`
        // is a per-ITEM rule — so a refusal about the SELECTION surfaced against the draft cards, the
        // composer screen recognising one by looking for `errors.graph_op_keys` exactly. The check now
        // lives in withValidator() and reports on the ARRAY, alongside the unknown-key and
        // inseparable-pair refusals it belongs with.
        $this->accept($session, ['graph_op_keys' => ['graph:0', 'graph:0']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('graph_op_keys');

        $this->assertSame(0, KnowledgeRelation::query()->count(), 'a refused request writes nothing at all');
    }

    /**
     * A KEY FROM THE PREVIOUS RUN, which is the ONLY way a real client produces an unknown one.
     *
     * A refinement rewrites `graph_ops` WHOLESALE, so the operations are renumbered under a review
     * that is already open — a second tab, a slow reader, a bulk accept queued behind a refine. The
     * old key still LOOKS valid, and applying the ones that happen to match would give the reviewer
     * some of what they approved and something they never saw for the rest.
     */
    public function test_a_key_from_before_a_refinement_is_refused_rather_than_reinterpreted(): void
    {
        $session = $this->twoPeople();

        $stale = array_column(
            $this->getJson("/api/knowledge/draft-sessions/{$session->id}/relations")->assertOk()->json('data.proposed_relations'),
            'key',
        );

        $this->assertSame(['graph:0', 'graph:1'], $stale);

        // The refinement: the same reviewer asks for something shorter, and the run comes back with a
        // single operation. `graph:1` no longer exists.
        KnowledgeDraftAgent::fake(fn (): string => json_encode([
            'entries' => [],
            'graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'knows']],
        ], JSON_UNESCAPED_UNICODE));

        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/refine", ['instruction' => 'tylko jedna relacja'])
            ->assertOk();

        $this->assertCount(1, $session->refresh()->graphOps()['graph_updates']);

        $this->accept($session, ['graph_op_keys' => $stale])
            ->assertStatus(422)
            ->assertJsonValidationErrors('graph_op_keys');

        $this->assertSame(0, KnowledgeRelation::query()->count());

        // ...and the key the CURRENT preview publishes is accepted, so the refusal is a guard on
        // staleness rather than on selection itself.
        $this->accept($session, ['graph_op_keys' => ['graph:0']])->assertOk();

        $this->assertSame(1, KnowledgeRelation::query()->count());
    }

    /**
     * AN EMPTY SELECTION AGAINST AN EMPTY PROPOSAL IS A NO-OP, not an error.
     *
     * A client that always sends the field — the honest way to implement a three-valued contract —
     * sends `[]` on a run that proposed no relations at all. Refusing that would make correct clients
     * special-case the empty case, which is how the two meanings of "no keys" get confused again.
     */
    public function test_an_empty_selection_against_a_proposal_with_no_operations_is_accepted(): void
    {
        $session = $this->compose(
            ['entries' => [['action' => 'create', 'slug' => 'nowy', 'title' => 'Nowy', 'content' => 'Tresc.', 'metadata' => []]]],
            $this->mention('Anna Kowalska'),
        );

        $draft = $session->drafts()->firstOrFail();

        $response = $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [(string) $draft->id],
            'graph_op_keys' => [],
            'status' => 'approved',
        ])->assertOk();

        $this->assertCount(1, $response->json('accepted'), 'the prose still publishes');
        $this->assertSame([], $response->json('skipped'), 'there was nothing to refuse');
        $this->assertSame(0, KnowledgeRelation::query()->count());
    }

    /**
     * "NO" MEANS NO — including to the entities the graph half would have created.
     *
     * The reviewer refuses everything: no drafts, no operations. That request used to create every
     * declared entity anyway, as a real entry in `approved` status, because the selection was handed to
     * the relation loop and not to `createDeclaredEntities()`. `entity:<n>` existed only inside the
     * ledger and never appeared on the wire, so the answer a reviewer most needs — "none of this" — was
     * the one answer the server did not implement.
     *
     * An entity is now created by a SELECTED OPERATION naming it, which is also how the review panel
     * presents it (`depends_on_draft`), rather than by a second set of keys nobody could see.
     */
    public function test_refusing_every_operation_creates_no_declared_entity(): void
    {
        $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON);

        $session = $this->compose([
            'entries' => [[
                'action' => 'create',
                'ref' => 'N1',
                'slug' => 'kwadratura',
                'title' => 'Kwadratura',
                'type' => 'organization',
                'content' => 'Software house.',
                'metadata' => [],
            ]],
            'graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'N1', 'type' => 'member_of']],
        ], $this->mention('Anna Kowalska'));

        $this->assertCount(1, $session->graphOps()['entities'], 'the draft claimed a handle');

        $draft = $session->drafts()->firstOrFail();

        // NEITHER the page nor the operation. The whole proposal is refused.
        $response = $this->accept($session, ['graph_op_keys' => []])->assertOk();

        $this->assertSame([], $response->json('relations'));
        $this->assertNull(
            KnowledgeEntry::query()->where('title', 'Kwadratura')->first(),
            'refusing the operation refuses the entity it needed',
        );

        // The refusal is REPORTED, not merely obeyed — the reviewer sees that their "no" took effect.
        $this->assertSame(
            [KnowledgeGraphOpsApplier::SKIP_NOT_SELECTED],
            array_column($response->json('skipped'), 'code'),
        );

        // NOTHING APPLIED YET, AND THE WIRE SAYS SO. Without this a client re-opening the session
        // cannot tell an applied proposal from a waiting one, and its only safe move is to offer the
        // whole batch again and lean on `already_applied` — safe, but it shows the wrong count.
        $this->assertSame(
            [],
            $this->getJson("/api/knowledge/draft-sessions/{$session->id}")->assertOk()->json('data.applied_graph_op_keys'),
        );

        // ...and the same session, accepted properly afterwards, creates it exactly once.
        $this->accept($session, [
            'entry_ids' => [(string) $draft->id],
            'graph_op_keys' => ['graph:0'],
        ])->assertOk();

        $this->assertCount(1, KnowledgeEntry::query()->where('title', 'Kwadratura')->get());
        $this->assertSame(1, KnowledgeRelation::query()->count());

        // ...and now the key IS reported — the operation key only, never the `entity:<n>=<uuid>` row
        // the ledger keeps beside it, which is internal bookkeeping carrying a database address.
        $applied = $this->getJson("/api/knowledge/draft-sessions/{$session->id}")
            ->assertOk()
            ->json('data.applied_graph_op_keys');

        $this->assertSame(['graph:0'], $applied);
    }

    /** A key that was never a key at all — the shape a hand-rolled client gets wrong. */
    public function test_a_malformed_operation_key_is_refused(): void
    {
        $session = $this->twoPeople();

        foreach ([['graph:'], ['0'], ['entity:0'], ['graph:-1'], [str_repeat('g', 40)]] as $keys) {
            $this->accept($session, ['graph_op_keys' => $keys])->assertStatus(422);
        }

        $this->assertSame(0, KnowledgeRelation::query()->count());
    }

    // ---- the amendment columns ----------------------------------------------------------

    /**
     * AN APPEND WITH NOTHING TO APPEND must not become a proposal to change an entry.
     *
     * An empty addition is the degenerate output of a model that decided mid-answer it had nothing to
     * say. Carried through, it produces a review card with an empty diff, and publishing it appends a
     * separator to a real entry — a revision that changes the text and says nothing, which is worse
     * than no revision because it makes the history lie about when the entry last moved.
     */
    public function test_an_append_with_an_empty_addition_never_becomes_a_shadow(): void
    {
        $target = $this->entry('Zwroty', null, 'Polityka zwrotow towaru.');
        $before = (string) $target->content;

        $session = $this->compose([
            'entries' => [],
            'wiki_updates' => [['entity' => 'E1', 'op' => 'append', 'content' => '   ']],
        ], $this->mention('Zwroty', 'concept'), 'Zwroty bez zmian.');

        $shadows = $session->drafts()->whereNotNull('targets_entry_id')->get();

        $this->assertCount(0, $shadows, 'an empty addition is not a proposal');

        // Accepting the session anyway changes nothing about the entry.
        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [],
            'status' => 'approved',
        ])->assertOk();

        $this->assertSame($before, (string) $target->fresh()->content);
    }

    /**
     * A REWRITE THAT LOST ITS LOCK STILL REFUSES TO OVERWRITE A CONCURRENT EDIT.
     *
     * `target_revision_id` is the whole interlock of the rewrite path, and this is the one state in
     * which it can be absent on a row that is nonetheless a rewrite: the frozen revision was destroyed
     * (an erasure, a history purge) while the proposal sat on the table. A null read as "no expected
     * revision" would turn the safest operation in the module into a silent overwrite of somebody's
     * work — the exact failure the column exists to prevent, reachable by deleting a row.
     *
     * Pinned as CURRENT BEHAVIOUR: today the write goes through unlocked. See the G8 report.
     */
    public function test_a_rewrite_whose_frozen_revision_is_gone_is_applied_without_a_lock(): void
    {
        $target = $this->entry('Zwroty', null, 'Polityka zwrotow towaru w calosci.');

        $session = $this->compose([
            'entries' => [],
            'wiki_updates' => [['entity' => 'E1', 'op' => 'rewrite', 'content' => 'Zupelnie nowa polityka.']],
        ], $this->mention('Zwroty', 'concept'), 'Nowa polityka zwrotow.');

        $shadow = $session->drafts()->firstOrFail();

        $this->assertFalse($shadow->isAppendShadow(), 'the fixture must really be a rewrite');
        $this->assertNotNull($shadow->target_revision_id);

        // Somebody else's amendment lands on the target, and the revision the composer read is destroyed.
        $this->updateEntry($target, content: 'Poprawka z innej sesji.');
        $shadow->forceFill(['target_revision_id' => null])->save();

        $response = $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [(string) $shadow->id],
            'status' => 'approved',
        ])->assertOk();

        // INVERTED from the G8 pin. A rewrite with no frozen revision used to apply with NO check at
        // all — `assertNotStale()` returns early on a null token — silently replacing a concurrent
        // human edit. Recoverable from history, but nobody was ever offered the choice.
        //
        // It is REFUSED now, as a conflict: the G5.1 rule ("where we cannot prove what the model worked
        // from, we do not replace") applied at the one point that knows. A conflict rather than a
        // degrade-to-append, because a rewrite's content is a WHOLE NEW BODY and appending it would
        // paste the document on top of itself — and because the client already offers a rebase here,
        // which is exactly what re-establishes the missing token.
        $this->assertSame([], $response->json('accepted'), 'nothing was published');
        $this->assertCount(1, $response->json('conflicts'));
        $this->assertSame(
            'Poprawka z innej sesji.',
            (string) $target->fresh()->content,
            'the earlier write was not overwritten',
        );
    }
}
