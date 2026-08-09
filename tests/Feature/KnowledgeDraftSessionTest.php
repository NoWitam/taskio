<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Agents\KnowledgeDraftAgent;
use App\Modules\Knowledge\Enums\KnowledgeDraftSessionStatus;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Jobs\GenerateKnowledgeDraftsJob;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Services\KnowledgeDraftService;
use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Exceptions\AiBudgetExceededException;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Facades\Agent;
use Tests\TestCase;

/**
 * B11a — the AI COMPOSER: a drafting session, the laundering of what the model returns, and the
 * invisibility that makes unreviewed machine output safe to store as ordinary entries.
 *
 * The invisibility tests are the load-bearing ones. Drafts are real `knowledge_entries` rows, which is
 * what buys them revisions, slugs, metadata validation and an atomic accept — and the entire price of
 * that decision is that every other read in the product must stop seeing them. That is paid by one
 * global scope, so these pin the surfaces where a leak would matter most.
 */
class KnowledgeDraftSessionTest extends TestCase
{
    use RefreshDatabase;

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

        $this->base = KnowledgeBase::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Baza',
            'language' => 'pl',
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers -------------------------------------------------------------------

    /** Script the composer's reply. NEVER a real provider — the agent's own fake gateway serves it. */
    private function fakeComposer(string $reply): void
    {
        KnowledgeDraftAgent::fake(fn () => $reply);
    }

    private function reply(array $entries): string
    {
        return json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE);
    }

    private function start(array $payload = []): KnowledgeDraftSession
    {
        $id = $this->postJson("/api/knowledge/bases/{$this->base->id}/draft-sessions", array_merge([
            'source_text' => 'Zwroty przyjmujemy w 14 dni. Reklamacje rozpatrujemy w 30 dni.',
        ], $payload))->assertCreated()->json('data.id');

        return KnowledgeDraftSession::query()->findOrFail($id);
    }

    /** @return array<int, KnowledgeEntry> */
    private function draftsOf(KnowledgeDraftSession $session): array
    {
        return $session->drafts()->get()->all();
    }

    // ---- the run ---------------------------------------------------------------------

    public function test_a_session_generates_drafts_from_raw_material(): void
    {
        $this->fakeComposer($this->reply([
            ['slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Przyjmujemy w 14 dni.', 'metadata' => []],
            ['slug' => 'reklamacje', 'title' => 'Reklamacje', 'content' => 'Rozpatrujemy w 30 dni.', 'metadata' => []],
        ]));

        $session = $this->start();

        $this->assertSame(KnowledgeDraftSessionStatus::READY, $session->refresh()->status);

        $drafts = $this->draftsOf($session);
        $this->assertCount(2, $drafts);
        $this->assertSame(['Zwroty', 'Reklamacje'], array_map(fn ($d) => $d->title, $drafts));

        // PROPOSED — finished work awaiting a human's blessing, which is what that status means.
        $this->assertSame(KnowledgeEntryStatus::PROPOSED, $drafts[0]->status);
        $this->assertSame((string) $session->id, (string) $drafts[0]->draft_session_id);
    }

    /** The generation is the diff's baseline, so every draft must arrive with a revision. */
    public function test_each_generation_and_refinement_appends_a_revision(): void
    {
        $this->fakeComposer($this->reply([
            ['slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Wersja pierwsza.', 'metadata' => []],
        ]));

        $session = $this->start();

        foreach (['Skroc.', 'Dodaj przyklad.'] as $index => $instruction) {
            $this->fakeComposer($this->reply([
                ['slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Wersja ' . ($index + 2) . '.', 'metadata' => []],
            ]));

            $this->postJson("/api/knowledge/draft-sessions/{$session->id}/refine", [
                'instruction' => $instruction,
            ])->assertOk();
        }

        $draft = $this->draftsOf($session)[0];

        $this->assertSame('Wersja 3.', $draft->content);
        $this->assertSame(3, $draft->revisions()->count(), 'generation + two refinements = three baselines');
    }

    /** A refinement matches BY SLUG, so a surviving entry keeps its row — and therefore its history. */
    public function test_a_refinement_updates_creates_and_removes_by_slug(): void
    {
        $this->fakeComposer($this->reply([
            ['slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Pierwsza.', 'metadata' => []],
            ['slug' => 'stary', 'title' => 'Stary', 'content' => 'Do usuniecia.', 'metadata' => []],
        ]));

        $session = $this->start();
        $kept = $this->draftsOf($session)[0];

        $this->fakeComposer($this->reply([
            ['slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Poprawiona.', 'metadata' => []],
            ['slug' => 'nowy', 'title' => 'Nowy', 'content' => 'Dodany.', 'metadata' => []],
        ]));

        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/refine", ['instruction' => 'Zmien.'])
            ->assertOk();

        $slugs = array_map(fn ($d) => $d->slug, $this->draftsOf($session));

        $this->assertSame(['zwroty', 'nowy'], $slugs);
        $this->assertSame((string) $kept->id, (string) $this->draftsOf($session)[0]->id, 'the kept draft keeps its row');
        $this->assertSame('Poprawiona.', $kept->fresh()->content);
    }

    // ---- invisibility ------------------------------------------------------------------

    /**
     * THE property the whole design rests on. A draft is a real entry row, so every read in the product
     * would otherwise start returning unreviewed machine output — including the context a BOT quotes to
     * a customer as fact.
     */
    public function test_drafts_are_invisible_to_every_ordinary_read(): void
    {
        $real = KnowledgeEntry::factory()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
            'title' => 'Prawdziwy wpis',
            'status' => KnowledgeEntryStatus::APPROVED,
        ]);

        $this->fakeComposer($this->reply([
            ['slug' => 'szkic', 'title' => 'Szkic', 'content' => 'Tresc szkicu.', 'metadata' => []],
        ]));

        $session = $this->start();
        $draft = $this->draftsOf($session)[0];

        // The entry LIST.
        $listed = $this->getJson("/api/knowledge/bases/{$this->base->id}/entries")->assertOk()->json('data');
        $this->assertSame([(string) $real->id], array_column($listed, 'id'));

        // The entry itself, by id.
        $this->getJson("/api/knowledge/entries/{$draft->id}")->assertNotFound();

        // SEARCH.
        $hits = $this->getJson("/api/knowledge/bases/{$this->base->id}/search?q=Szkic")->assertOk()->json('data');
        $this->assertNotContains((string) $draft->id, array_column($hits, 'id'));

        // The plain model query — the one every future feature will reach for.
        $this->assertNull(KnowledgeEntry::query()->find($draft->id));
        $this->assertSame(1, KnowledgeEntry::query()->where('knowledge_base_id', $this->base->id)->count());

        // The TRASH.
        $trashed = $this->getJson("/api/knowledge/bases/{$this->base->id}/entries?trashed=1")->assertOk()->json('data');
        $this->assertSame([], $trashed);
    }

    /** The bot's context compiler reads approved entries — and must never compile an unapproved draft. */
    public function test_the_bot_context_compiler_never_sees_a_draft(): void
    {
        $this->fakeComposer($this->reply([
            ['slug' => 'szkic', 'title' => 'Szkic', 'content' => 'Tresc szkicu ktora nie moze wyciec.', 'metadata' => []],
        ]));

        $session = $this->start();

        // Drafts are `proposed`, so an approved-only compiler would skip them anyway; force the
        // stronger case — a draft that IS approved must still be invisible.
        $draft = $this->draftsOf($session)[0];
        $draft->forceFill(['status' => KnowledgeEntryStatus::APPROVED])->save();

        $binding = \App\Modules\Knowledge\Models\KnowledgeBinding::factory()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
            'mode' => \App\Modules\Knowledge\Enums\KnowledgeBindingMode::INLINE,
        ]);

        $compiled = app(\App\Modules\Knowledge\Services\KnowledgeCompiler::class)
            ->compileForBinding($binding, 8000);

        $this->assertNull($compiled, 'a base holding only drafts compiles to nothing');
    }

    /** A draft costs NOTHING: no chunks, no embeddings, no edges. */
    public function test_drafts_are_never_indexed_or_linked(): void
    {
        $this->fakeComposer($this->reply([
            ['slug' => 'zwroty', 'title' => 'Zwroty', 'content' => str_repeat('Tresc o zwrotach. ', 60), 'metadata' => []],
        ]));

        $session = $this->start();
        $draft = $this->draftsOf($session)[0];

        $this->assertSame(0, KnowledgeEntryChunk::query()->where('knowledge_entry_id', $draft->id)->count());
        $this->assertSame(0, KnowledgeLink::query()->where('from_entry_id', $draft->id)->count());
    }

    // ---- accepting ----------------------------------------------------------------------

    /** Accept is ONE column write — and it is what finally queues the entry for indexing. */
    public function test_accepting_publishes_the_chosen_drafts_and_leaves_the_rest(): void
    {
        $this->fakeComposer($this->reply([
            ['slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Przyjmujemy w 14 dni.', 'metadata' => []],
            ['slug' => 'reklamacje', 'title' => 'Reklamacje', 'content' => 'W 30 dni.', 'metadata' => []],
        ]));

        $session = $this->start();
        [$first, $second] = $this->draftsOf($session);

        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [(string) $first->id],
            'status' => 'approved',
        ])->assertOk();

        $published = KnowledgeEntry::query()->find($first->id);
        $this->assertNotNull($published, 'an accepted draft is an ordinary entry');
        $this->assertNull($published->draft_session_id);
        $this->assertSame(KnowledgeEntryStatus::APPROVED, $published->status);

        // ...and the entry is now indexable, which a draft never was.
        $this->assertGreaterThan(0, KnowledgeEntryChunk::query()->where('knowledge_entry_id', $first->id)->count());

        // The one left behind is still a draft, still invisible.
        $this->assertNull(KnowledgeEntry::query()->find($second->id));
    }

    public function test_accepting_as_draft_keeps_it_editable_rather_than_authoritative(): void
    {
        $this->fakeComposer($this->reply([
            ['slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Tresc.', 'metadata' => []],
        ]));

        $session = $this->start();

        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [(string) $this->draftsOf($session)[0]->id],
            'status' => 'draft',
        ])->assertOk();

        $this->assertSame(KnowledgeEntryStatus::DRAFT, KnowledgeEntry::query()->firstOrFail()->status);
    }

    public function test_accept_refuses_a_status_a_reviewer_cannot_mean(): void
    {
        $this->fakeComposer($this->reply([
            ['slug' => 'z', 'title' => 'Z', 'content' => 'T.', 'metadata' => []],
        ]));

        $session = $this->start();

        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [(string) $this->draftsOf($session)[0]->id],
            'status' => 'archived',
        ])->assertJsonValidationErrors('status');
    }

    /** A draft from ANOTHER session cannot be published through this one. */
    public function test_accept_ignores_an_entry_id_from_another_session(): void
    {
        $this->fakeComposer($this->reply([['slug' => 'a', 'title' => 'A', 'content' => 'T.', 'metadata' => []]]));
        $first = $this->start();

        $this->fakeComposer($this->reply([['slug' => 'b', 'title' => 'B', 'content' => 'T.', 'metadata' => []]]));
        $second = $this->start();

        $foreign = $this->draftsOf($second)[0];

        $this->postJson("/api/knowledge/draft-sessions/{$first->id}/accept", [
            'entry_ids' => [(string) $foreign->id],
            'status' => 'approved',
        ])->assertOk();

        $this->assertNotNull($foreign->fresh()->draft_session_id, 'it is still a draft of its own session');
    }

    // ---- abandoning ------------------------------------------------------------------------

    public function test_abandoning_a_session_destroys_its_drafts(): void
    {
        $this->fakeComposer($this->reply([
            ['slug' => 'a', 'title' => 'A', 'content' => 'T.', 'metadata' => []],
            ['slug' => 'b', 'title' => 'B', 'content' => 'T.', 'metadata' => []],
        ]));

        $session = $this->start();

        $this->deleteJson("/api/knowledge/draft-sessions/{$session->id}")->assertNoContent();

        $this->assertSame(0, KnowledgeDraftSession::query()->count());
        $this->assertSame(0, KnowledgeEntry::query()->withDrafts()->withTrashed()->count());
    }

    public function test_a_single_draft_can_be_rejected(): void
    {
        $this->fakeComposer($this->reply([
            ['slug' => 'a', 'title' => 'A', 'content' => 'T.', 'metadata' => []],
            ['slug' => 'b', 'title' => 'B', 'content' => 'T.', 'metadata' => []],
        ]));

        $session = $this->start();
        $draft = $this->draftsOf($session)[0];

        $this->deleteJson("/api/knowledge/entries/{$draft->id}/draft")->assertNoContent();

        $this->assertCount(1, $this->draftsOf($session));
    }

    // ---- the budget gate --------------------------------------------------------------------

    /** Gate BEFORE claim: a refused request must leave the session exactly as it was. */
    public function test_an_over_cap_workspace_is_refused_with_429_and_no_session(): void
    {
        Queue::fake();

        $this->app->instance(MeteredAiCall::class, new RefusingComposerMeter);

        $this->postJson("/api/knowledge/bases/{$this->base->id}/draft-sessions", [
            'source_text' => 'Cokolwiek.',
        ])
            ->assertStatus(429)
            ->assertJsonPath('code', 'ai_budget_exceeded');

        $this->assertSame(0, KnowledgeDraftSession::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_compose_availability_reports_the_refusal_before_the_form_is_rendered(): void
    {
        $this->app->instance(MeteredAiCall::class, new RefusingComposerMeter);

        $this->getJson("/api/knowledge/bases/{$this->base->id}/compose-availability")
            ->assertOk()
            ->assertJsonPath('can_compose', false)
            ->assertJsonPath('reason', 'ai_budget_exceeded')
            ->assertJsonStructure(['budget' => ['cost_used', 'cost_cap', 'period'], 'limits' => ['source_max_chars']]);
    }

    public function test_the_module_kill_switch_stops_the_composer(): void
    {
        config()->set('knowledge.index.enabled', false);

        $this->getJson("/api/knowledge/bases/{$this->base->id}/compose-availability")
            ->assertOk()
            ->assertJsonPath('can_compose', false)
            ->assertJsonPath('reason', 'disabled');

        $this->postJson("/api/knowledge/bases/{$this->base->id}/draft-sessions", ['source_text' => 'X.'])
            ->assertStatus(429);
    }

    // ---- claiming ------------------------------------------------------------------------------

    /** Two clicks race in the database; exactly one run is queued. */
    public function test_a_second_refine_while_generating_does_not_queue_a_second_run(): void
    {
        $this->fakeComposer($this->reply([['slug' => 'a', 'title' => 'A', 'content' => 'T.', 'metadata' => []]]));
        $session = $this->start();

        Queue::fake();

        $session->forceFill([
            'status' => KnowledgeDraftSessionStatus::GENERATING,
            'claimed_at' => now(),
        ])->save();

        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/refine", ['instruction' => 'Zmien.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'generating');

        Queue::assertNothingPushed();
    }

    // ---- tenancy ---------------------------------------------------------------------------------

    public function test_another_workspaces_session_is_not_found(): void
    {
        $otherOwner = User::factory()->create();
        $other = Workspace::factory()->create(['owner_id' => $otherOwner->id]);
        $foreignBase = KnowledgeBase::factory()->create(['workspace_id' => $other->id]);
        $foreign = KnowledgeDraftSession::factory()->create([
            'workspace_id' => $other->id,
            'knowledge_base_id' => $foreignBase->id,
        ]);

        $this->getJson("/api/knowledge/draft-sessions/{$foreign->id}")->assertNotFound();
        $this->deleteJson("/api/knowledge/draft-sessions/{$foreign->id}")->assertNotFound();
    }

    // ---- the reaper ----------------------------------------------------------------------------

    public function test_abandoned_sessions_are_purged_by_the_reaper(): void
    {
        $this->fakeComposer($this->reply([['slug' => 'a', 'title' => 'A', 'content' => 'T.', 'metadata' => []]]));
        $session = $this->start();

        $this->travel((int) config('knowledge.drafting.abandon_after_days') + 1)->days();

        $this->artisan('knowledge:reap-draft-sessions')->assertSuccessful();

        $this->assertSame(0, KnowledgeDraftSession::query()->count());
        $this->assertSame(0, KnowledgeEntry::query()->withDrafts()->withTrashed()->count());
    }

    public function test_a_session_still_being_worked_on_is_not_reaped(): void
    {
        $this->fakeComposer($this->reply([['slug' => 'a', 'title' => 'A', 'content' => 'T.', 'metadata' => []]]));
        $session = $this->start();

        $this->travel(2)->days();

        $this->artisan('knowledge:reap-draft-sessions')->assertSuccessful();

        $this->assertNotNull($session->fresh());
    }

    // ---- the job ---------------------------------------------------------------------------------

    public function test_the_run_is_queued_with_scalars_only(): void
    {
        Queue::fake();

        $this->postJson("/api/knowledge/bases/{$this->base->id}/draft-sessions", [
            'source_text' => 'Zwroty w 14 dni.',
        ])->assertCreated();

        Queue::assertPushed(GenerateKnowledgeDraftsJob::class, function (GenerateKnowledgeDraftsJob $job): bool {
            return is_string($job->sessionId)
                && $job->workspaceId === (string) $this->workspace->id;
        });
    }

    public function test_a_failed_run_leaves_a_reason_and_no_drafts(): void
    {
        // Prose instead of JSON — the commonest way a prompt-and-parse seam fails.
        $this->fakeComposer('Oczywiscie! Oto wpisy, ktore przygotowalem dla Ciebie:');

        $session = $this->start();

        $this->assertSame(KnowledgeDraftSessionStatus::FAILED, $session->refresh()->status);
        $this->assertSame(KnowledgeDraftService::FAILURE_UNPARSEABLE, $session->failure_reason);
        $this->assertSame([], $this->draftsOf($session));
        $this->assertNull($session->claimed_at, 'a settled session holds no claim');
    }

    /** `failed` is a resting state, not a dead end: retrying is the same action as refining. */
    public function test_a_failed_session_can_be_retried(): void
    {
        $this->fakeComposer('nie-json');
        $session = $this->start();

        $this->fakeComposer($this->reply([['slug' => 'a', 'title' => 'A', 'content' => 'T.', 'metadata' => []]]));

        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/refine", ['instruction' => 'Sprobuj ponownie.'])
            ->assertOk();

        $this->assertSame(KnowledgeDraftSessionStatus::READY, $session->refresh()->status);
        $this->assertCount(1, $this->draftsOf($session));
    }
}

/** A workspace already over its cap: the gate refuses before anything is claimed or spent. */
class RefusingComposerMeter implements MeteredAiCall
{
    public function meter(string $channel, callable $call): mixed
    {
        throw new AiBudgetExceededException($channel, 12.5, 10.0);
    }

    public function assertWithinBudget(string $channel, float $projectedCost = 0.0): void
    {
        throw new AiBudgetExceededException($channel, 12.5, 10.0);
    }
}
