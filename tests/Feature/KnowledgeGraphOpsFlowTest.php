<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Agents\KnowledgeDraftAgent;
use App\Modules\Knowledge\Agents\KnowledgeMentionAgent;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Enums\KnowledgeEntryType;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Knowledge\Support\KnowledgeGraphOps;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * G4 END TO END — the editor-in-chief contract as it behaves through a real session.
 *
 * The unit suite proves the laundering refuses what it should. This one proves the wiring: that the
 * context is frozen ONCE, that the handles the prompt offered are the handles the answer is judged
 * against, that a refusal reaches the reviewer, and — the pin the whole feature rests on — that with
 * the flag DOWN nothing about the composer changed at all.
 */
class KnowledgeGraphOpsFlowTest extends TestCase
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

    // ---- fixtures -------------------------------------------------------------------

    private function fakeExtraction(array $mentions): void
    {
        KnowledgeMentionAgent::fake(fn (): string => json_encode(['mentions' => $mentions], JSON_UNESCAPED_UNICODE));
    }

    private function fakeComposer(array $reply): void
    {
        KnowledgeDraftAgent::fake(fn (): string => json_encode($reply, JSON_UNESCAPED_UNICODE));
    }

    private function entries(): array
    {
        return [['action' => 'create', 'slug' => 'x', 'title' => 'X', 'content' => 'Tresc.', 'metadata' => []]];
    }

    private function entry(string $title, ?KnowledgeEntryType $type = null, string $content = 'Tresc wpisu.'): KnowledgeEntry
    {
        return $this->makeEntry(
            base: $this->base,
            title: $title,
            content: $content,
            type: $type,
        );
    }

    private function start(string $sourceText = 'Anna zmieniła zespół.'): KnowledgeDraftSession
    {
        $id = $this->postJson("/api/knowledge/bases/{$this->base->id}/draft-sessions", [
            'source_text' => $sourceText,
        ])->assertCreated()->json('data.id');

        return KnowledgeDraftSession::query()->findOrFail($id)->refresh();
    }

    // ---- the consolidation ------------------------------------------------------------

    /**
     * ONE context, ONE embedding batch.
     *
     * Before this, both the retrieval pass and the resolution pass froze entry text and both paid for
     * an embedding — so a session bought the same context twice and would have quoted the same entries
     * into one prompt twice.
     */
    public function test_with_extraction_on_only_one_context_is_frozen(): void
    {
        $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON, 'Anna prowadzi zespol zwrotow.');

        $this->fakeExtraction([['text' => 'Anna Kowalska', 'kind' => 'person', 'context' => 'Anna zmieniła zespół']]);
        $this->fakeComposer(['entries' => $this->entries()]);

        $this->embedder->reset();

        $session = $this->start();

        $this->assertNull($session->retrieval_set, 'the retrieval column is cleared, not merely ignored');
        $this->assertNotSame([], $session->resolutionSet()['entities']);

        // ONE batch for the whole freeze, carrying only the SOURCE: every name resolved
        // deterministically so no mention needed a vector of its own, and the source is embedded once
        // for the topical leg. Before the merge this was two calls answering two questions.
        $this->assertSame(1, $this->embedder->calls, 'one batch, both questions');
        $this->assertCount(1, $this->embedder->batches[0], 'a deterministically matched name costs no vector');
    }

    /** With open names in play it is STILL one batch — the names and the source travel together. */
    public function test_a_vector_resolution_costs_a_single_batch(): void
    {
        $this->entry('Zwroty towaru');

        $this->fakeExtraction([
            ['text' => 'Kwadratura', 'kind' => 'organization', 'context' => 'a'],
            ['text' => 'Peryskop', 'kind' => 'product', 'context' => 'b'],
        ]);
        $this->fakeComposer(['entries' => $this->entries()]);

        $this->embedder->reset();

        $this->start('Material o rzeczach, ktorych baza nie zna.');

        $this->assertSame([3], array_map('count', $this->embedder->batches), 'two open names plus the source, once');
    }

    /**
     * THE SAFETY PATH SURVIVES THE SWITCH.
     *
     * The amendment allow-list and the truncated→append rule were both derived from the retrieval set.
     * Had they kept reading it, turning extraction on would have emptied the allow-list — which does
     * not fail loudly, it silently degrades every amendment into a NEW entry, breeding exactly the
     * duplicates this layer exists to prevent.
     */
    public function test_the_amendment_allow_list_follows_the_new_source(): void
    {
        $target = $this->entry('Zwroty', null, 'Polityka zwrotow towaru.');

        $this->fakeExtraction([['text' => 'Zwroty', 'kind' => 'concept', 'context' => 'zmiana zwrotow']]);
        $this->fakeComposer(['entries' => [
            ['action' => 'update', 'targets_slug' => $target->slug, 'title' => 'Zwroty', 'content' => 'Zwroty w 30 dni.', 'metadata' => []],
        ]]);

        $session = $this->start('Zwroty przyjmujemy w 30 dni.');
        $drafts = $session->drafts()->get();

        $this->assertCount(1, $drafts);
        $this->assertTrue($drafts[0]->isShadow(), 'the amendment resolved against the RESOLUTION set');
        $this->assertSame((string) $target->id, (string) $drafts[0]->targets_entry_id);
        // The optimistic-lock token came across with it — without this the amendment path would have
        // lost its interlock the moment the source changed.
        $this->assertSame($target->current_revision_id, $drafts[0]->target_revision_id);
    }

    /** And so does the rule that an entry shown in part may only be appended to. */
    public function test_the_truncated_append_rule_follows_the_new_source(): void
    {
        // BOTH caps, because the full-content cap is floored at the excerpt cap — lowering one alone
        // leaves the other governing, which is exactly the trap that made this test pass vacuously the
        // first time I wrote it.
        config()->set('knowledge.drafting.retrieval_excerpt_chars', 200);
        config()->set('knowledge.drafting.amend_full_chars', 200);

        $long = str_repeat('Polityka zwrotow towaru w sklepie. ', 40) . 'OSTATNIE ZDANIE.';
        $target = $this->entry('Zwroty', null, $long);

        $this->fakeExtraction([['text' => 'Zwroty', 'kind' => 'concept', 'context' => 'zmiana zwrotow']]);
        $this->fakeComposer(['entries' => [
            ['action' => 'update', 'mode' => 'rewrite', 'targets_slug' => $target->slug, 'title' => 'Zwroty', 'content' => 'Zwroty w 30 dni.', 'metadata' => []],
        ]]);

        $shadow = $this->start('Zwroty przyjmujemy w 30 dni.')->drafts()->firstOrFail();

        $this->assertTrue($shadow->isAppendShadow(), 'an entry shown in part may only be appended to');
        // The shadow holds the ADDITION; the RESULT is what the reviewer is shown, and the withheld
        // tail is still in it.
        $this->assertSame('Zwroty w 30 dni.', (string) $shadow->content);
        $this->assertStringContainsString('OSTATNIE ZDANIE.', $shadow->amendedBody(), 'the withheld tail survives');
        $this->assertStringEndsWith('Zwroty w 30 dni.', $shadow->amendedBody());
    }

    // ---- the prompt ---------------------------------------------------------------------

    public function test_the_prompt_offers_handles_and_never_ids(): void
    {
        $anna = $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON, 'Anna prowadzi zespol.');
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);

        $this->makeRelation($this->base, $anna, $acme, KnowledgeRelationType::MEMBER_OF, description: 'Prowadzi zespol zwrotow.');

        $this->fakeExtraction([['text' => 'Anna Kowalska', 'kind' => 'person', 'context' => 'Anna zmieniła zespół']]);
        $this->fakeComposer(['entries' => $this->entries()]);

        $this->start();

        KnowledgeDraftAgent::assertPrompted(function (AgentPrompt $prompt) use ($anna): bool {
            return str_contains($prompt->prompt, 'KNOWN ENTITIES')
                && str_contains($prompt->prompt, '[E1] Anna Kowalska')
                && str_contains($prompt->prompt, '[R1]')
                && str_contains($prompt->prompt, 'Prowadzi zespol zwrotow.')
                // THE IDENTITY RULE: a database id never reaches the model, so a hallucinated one
                // cannot be mistaken for a real one.
                && !str_contains($prompt->prompt, (string) $anna->id);
        });
    }

    /**
     * WITH THE FLAG DOWN THE PROMPT IS BYTE-IDENTICAL to the tree as it stood after G1.
     *
     * This is the pin the whole staged rollout rests on: everything G3 and G4 added is additive and
     * reachable only through the switch, so an operator turning it off gets the composer they had.
     */
    public function test_with_the_flag_off_the_prompt_carries_nothing_new(): void
    {
        config()->set('knowledge.graph_extraction.enabled', false);

        $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON, 'Anna prowadzi zespol.');

        $this->fakeExtraction([['text' => 'Anna Kowalska', 'kind' => 'person', 'context' => 'x']]);
        $this->fakeComposer(['entries' => $this->entries()]);

        $session = $this->start();

        KnowledgeMentionAgent::assertNeverPrompted();

        KnowledgeDraftAgent::assertPrompted(fn (AgentPrompt $prompt): bool => !str_contains($prompt->prompt, 'KNOWN ENTITIES')
            && !str_contains($prompt->prompt, 'NAMES THAT COULD MEAN SEVERAL THINGS')
            && !str_contains($prompt->prompt, '[E1]'));

        // ...and nothing is laundered or stored either.
        $this->assertSame([], $session->refresh()->graphOps()['graph_updates']);
        $this->assertSame([], $session->graphOps()['rejected']);
    }

    // ---- the round trip -------------------------------------------------------------------

    public function test_a_well_formed_proposal_is_stored_and_exposed(): void
    {
        $anna = $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON, 'Anna prowadzi zespol.');

        $this->fakeExtraction([['text' => 'Anna Kowalska', 'kind' => 'person', 'context' => 'Anna poznala Boba']]);
        $this->fakeComposer([
            // BOB IS AN ENTRY CARRYING A HANDLE. The composer no longer declares entities at all; the
            // server synthesises one per create-draft, so `N1` is claimed on the entry itself.
            'entries' => [...$this->entries(), [
                'action' => 'create',
                'ref' => 'N1',
                'slug' => 'bob-nowak',
                'title' => 'Bob Nowak',
                'type' => 'person',
                'content' => 'Bob Nowak.',
                'metadata' => [],
            ]],
            'graph_updates' => [
                ['op' => 'create', 'from' => 'E1', 'to' => 'N1', 'type' => 'knows', 'description' => 'Poznali sie na przegladzie.', 'valid_from' => '2026-08-04'],
                // Dropped: a verb outside the closed vocabulary is never approximated.
                ['op' => 'create', 'from' => 'E1', 'to' => 'N1', 'type' => 'mentored'],
                // Dropped: the model has no power to remove a statement.
                ['op' => 'delete', 'relation' => 'R1'],
            ],
        ]);

        $session = $this->start('Anna poznała Boba Nowaka.');

        $body = $this->getJson("/api/knowledge/draft-sessions/{$session->id}")->assertOk()->json('data.graph_ops');

        $this->assertCount(1, $body['graph_updates']);
        $this->assertSame('knows', $body['graph_updates'][0]['type']);
        $this->assertSame('2026-08-04', $body['graph_updates'][0]['valid_from']);
        $this->assertCount(1, $body['entities']);

        $codes = array_column($body['rejected'], 'code');
        $this->assertContains(KnowledgeGraphOps::REJECT_UNKNOWN_TYPE, $codes);
        $this->assertContains(KnowledgeGraphOps::REJECT_FORBIDDEN_OP, $codes);

        // NOTHING WAS WRITTEN. It is a proposal; a human accepts it.
        $this->assertSame(0, KnowledgeRelation::query()->count());
    }

    /** A truncated or fenced reply fails the run and writes nothing — never a 500. */
    public function test_a_broken_reply_fails_the_session_without_writing(): void
    {
        $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON);
        $this->fakeExtraction([['text' => 'Anna Kowalska', 'kind' => 'person', 'context' => 'x']]);

        // JSON cut off in the middle — the shape a streamed answer takes when a provider drops it.
        KnowledgeDraftAgent::fake(fn (): string => '{"entries":[{"action":"create","slug":"x","title":"X","content":"Tre');

        $session = $this->start();

        $this->assertSame('failed', $session->refresh()->status?->value);
        $this->assertSame('unparseable', $session->failure_reason);
        $this->assertSame(0, KnowledgeRelation::query()->count());
        $this->assertSame(0, $session->drafts()->count());
    }

    /** A fenced answer still parses: the decoder digs the object out of the code fence. */
    public function test_a_code_fenced_reply_is_still_read(): void
    {
        $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON);
        $this->fakeExtraction([['text' => 'Anna Kowalska', 'kind' => 'person', 'context' => 'x']]);

        KnowledgeDraftAgent::fake(fn (): string => "```json\n" . json_encode(['entries' => $this->entries()]) . "\n```");

        $this->assertSame('ready', $this->start()->refresh()->status?->value);
    }

    // ---- the preview ---------------------------------------------------------------------

    /**
     * A proposed relation naming an entity this same run wants to CREATE cannot be applied until that
     * draft is accepted — and the reviewer is told so BEFORE the click, not by a 422 afterwards.
     */
    public function test_the_preview_flags_a_relation_that_depends_on_a_draft(): void
    {
        $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON, 'Anna prowadzi zespol.');

        $this->fakeExtraction([['text' => 'Anna Kowalska', 'kind' => 'person', 'context' => 'Anna poznala Boba']]);
        $this->fakeComposer([
            // BOB IS AN ENTRY CARRYING A HANDLE. The composer no longer declares entities at all; the
            // server synthesises one per create-draft, so `N1` is claimed on the entry itself.
            'entries' => [...$this->entries(), [
                'action' => 'create',
                'ref' => 'N1',
                'slug' => 'bob-nowak',
                'title' => 'Bob Nowak',
                'type' => 'person',
                'content' => 'Bob Nowak.',
                'metadata' => [],
            ]],
            'graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'N1', 'type' => 'knows']],
        ]);

        $session = $this->start('Anna poznała Boba Nowaka.');

        $body = $this->getJson("/api/knowledge/draft-sessions/{$session->id}/relations")->assertOk()->json('data');

        $this->assertCount(1, $body['proposed_relations']);
        $this->assertSame('relation', $body['proposed_relations'][0]['kind']);
        $this->assertNull($body['proposed_relations'][0]['id'], 'a preview edge has no row');
        $this->assertSame(['N1'], $body['proposed_relations'][0]['depends_on_draft']);
        $this->assertSame('Anna Kowalska', $body['proposed_relations'][0]['from_title']);

        $this->assertCount(1, $body['proposed_entities']);
        $this->assertTrue($body['proposed_entities'][0]['is_draft']);
    }

    public function test_the_preview_carries_no_proposals_when_there_are_none(): void
    {
        $this->entry('Anna Kowalska', KnowledgeEntryType::PERSON);
        $this->fakeExtraction([['text' => 'Anna Kowalska', 'kind' => 'person', 'context' => 'x']]);
        $this->fakeComposer(['entries' => $this->entries()]);

        $session = $this->start();

        $body = $this->getJson("/api/knowledge/draft-sessions/{$session->id}/relations")->assertOk()->json('data');

        $this->assertSame([], $body['proposed_relations']);
        $this->assertSame([], $body['proposed_entities']);
    }
}
