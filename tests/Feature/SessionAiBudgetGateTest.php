<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Agents\BotSlotFillAgent;
use App\Modules\Bot\Models\Bot;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Jobs\RunGenerationSessionJob;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Variables\Models\AiUsageEvent;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The PRE-RUN AI-budget gate (R2 sub-stage 4 completion): every session RUN entry point — whole generate,
 * per-part regenerate, per-part refine, and delegate with `auto_generate:true` — must, BEFORE claiming +
 * dispatching, refuse a workspace ALREADY at/over its effective calendar-month AI $ cap with an HTTP 429 +
 * `{code: 'ai_budget_exceeded'}` (the exact shape the FE's `isBudgetError` recognizes), and must NOT claim a
 * run (status unchanged, no job dispatched). When the cap is OFF (the default) the gate is byte-preserving:
 * the SAME endpoints behave exactly as today (claimed, 202). All spend is SCRIPTED via the ledger — no real
 * provider is ever called (the gate refuses before the run, so the worker never runs).
 *
 * Setup mirrors GenerationSessionGenerateTest: a real workspace + active tenancy so AiUsageService reads the
 * seeded ledger rows the gate sums.
 */
class SessionAiBudgetGateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);

        // Byte-preserving default: no override + env default 0.0 → effective cap OFF unless a test opts in.
        config()->set('ai.meter.monthly_cost_cap_default', 0.0);

        // Creative-direction layer OFF: these cases pin the pre-run budget gate, which predates it, and an
        // un-scripted derivation would be a REAL provider call. That the derivation itself is gated before
        // spend is pinned in CreativeDirectionTest.
        config()->set('generator.direction.enabled', false);

        Queue::fake();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers ---------------------------------------------------------------

    /** Set the workspace's $ cap and re-prime the tenant context so cap() reads the fresh CENTRAL row. */
    private function setCap(?float $cap): void
    {
        $this->workspace->update(['ai_monthly_cost_cap' => $cap]);
        app(TenantContext::class)->set($this->workspace);
    }

    /** Seed one ledger event this month so the gate's month-to-date $ SUM reflects it. */
    private function seedSpend(float $cost, string $channel = 'ai_text'): void
    {
        AiUsageEvent::create([
            'channel' => $channel,
            'total_tokens' => 0,
            'estimated_cost' => $cost,
        ]);
    }

    /** Push the active workspace already OVER its $ cap ($1.50 spent against a $1.00 cap). */
    private function pushOverCap(): void
    {
        $this->setCap(1.00);
        $this->seedSpend(1.50);
    }

    private function topicSlot(): array
    {
        return ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]];
    }

    /** A draft session ready to whole-generate. */
    private function draftSession(): GenerationSession
    {
        return GenerationSession::factory()->create(['creator_id' => $this->user->id]);
    }

    /** A ready `post` session whose body part has a current text — refinable + regenerable. */
    private function readyTextSession(): GenerationSession
    {
        return GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot('post', ['body' => ['markdown' => 'Body']], [$this->topicSlot()], ['topic' => 'X'])
            ->create([
                'creator_id' => $this->user->id,
                'results' => ['body' => ['kind' => 'text_body', 'status' => 'ok', 'text' => 'CURRENT TEXT', 'version' => 1]],
            ]);
    }

    /** A draft session with a fillable slot — the delegation target. */
    private function delegableSession(): GenerationSession
    {
        return GenerationSession::factory()
            ->snapshot('post', ['body' => ['markdown' => 'Write a post about topic.']], [$this->topicSlot()], [])
            ->create(['creator_id' => $this->user->id]);
    }

    private function bot(): Bot
    {
        return Bot::factory()->create(['creator_id' => $this->user->id, 'icon' => 'robot']);
    }

    /** Assert a response is the exact pre-run budget refusal contract: 429 + code + a non-empty message. */
    private function assertBudget429(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertStatus(429)
            ->assertJsonPath('code', 'ai_budget_exceeded')
            ->assertJsonPath('message', __('generator.sessions.ai_budget_exceeded'));
    }

    // ---- OVER cap → 429 + code, no claim, no job -------------------------------

    public function test_generate_over_cap_is_429_and_does_not_claim_a_run(): void
    {
        $this->pushOverCap();
        $session = $this->draftSession();

        $this->assertBudget429($this->postJson("/api/generator/sessions/{$session->id}/generate"));

        $this->assertSame(GenerationSessionStatus::Draft, $session->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_regenerate_over_cap_is_429_and_does_not_claim_a_run(): void
    {
        $this->pushOverCap();
        $session = $this->readyTextSession();

        $this->assertBudget429($this->postJson("/api/generator/sessions/{$session->id}/parts/body/regenerate"));

        $this->assertSame(GenerationSessionStatus::Ready, $session->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_refine_over_cap_is_429_and_does_not_claim_a_run(): void
    {
        $this->pushOverCap();
        $session = $this->readyTextSession();

        $this->assertBudget429(
            $this->postJson("/api/generator/sessions/{$session->id}/parts/body/refine", ['instruction' => 'shorten it']),
        );

        $this->assertSame(GenerationSessionStatus::Ready, $session->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_delegate_auto_generate_over_cap_is_429_and_does_not_claim_a_run(): void
    {
        BotSlotFillAgent::fake(fn () => '{}'); // no autonomous fill; the gate refuses before any run
        $this->pushOverCap();

        $bot = $this->bot();
        $session = $this->delegableSession();

        $this->assertBudget429(
            $this->postJson("/api/bots/{$bot->id}/sessions/{$session->id}/delegate", ['auto_generate' => true]),
        );

        $this->assertSame(GenerationSessionStatus::Draft, $session->fresh()->status);
        Queue::assertNothingPushed();
    }

    // ---- Cap OFF (default) → byte-identical to today: claimed, 202 -------------

    public function test_generate_with_the_cap_off_is_byte_preserving_and_claims_the_run(): void
    {
        // Cap OFF (setUp default) even with a month wildly over any conceivable cost: the gate is a NO-OP.
        $this->seedSpend(9999.0);
        $session = $this->draftSession();

        $this->postJson("/api/generator/sessions/{$session->id}/generate")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'generating');

        $this->assertSame(GenerationSessionStatus::Generating, $session->fresh()->status);
        Queue::assertPushed(RunGenerationSessionJob::class, fn ($job) => $job->sessionId === $session->id);
    }

    public function test_regenerate_and_refine_with_the_cap_off_are_byte_preserving(): void
    {
        $this->seedSpend(9999.0); // cap OFF → ignored

        $regen = $this->readyTextSession();
        $this->postJson("/api/generator/sessions/{$regen->id}/parts/body/regenerate")->assertStatus(202);
        $this->assertSame(GenerationSessionStatus::Generating, $regen->fresh()->status);

        $refine = $this->readyTextSession();
        $this->postJson("/api/generator/sessions/{$refine->id}/parts/body/refine", ['instruction' => 'shorten it'])
            ->assertStatus(202);
        $this->assertSame(GenerationSessionStatus::Generating, $refine->fresh()->status);

        Queue::assertPushed(RunGenerationSessionJob::class, 2);
    }

    public function test_delegate_auto_generate_with_the_cap_off_claims_the_run(): void
    {
        BotSlotFillAgent::fake(fn () => json_encode(['topic' => 'x']));
        $this->seedSpend(9999.0); // cap OFF → ignored

        $bot = $this->bot();
        $session = $this->delegableSession();

        $this->postJson("/api/bots/{$bot->id}/sessions/{$session->id}/delegate", ['auto_generate' => true])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'generating');

        $this->assertSame(GenerationSessionStatus::Generating, $session->fresh()->status);
        Queue::assertPushed(RunGenerationSessionJob::class, fn ($job) => $job->sessionId === $session->id);
    }

    // ---- UNDER cap → 202 as normal --------------------------------------------

    public function test_generate_under_cap_claims_the_run(): void
    {
        $this->setCap(10.00);
        $this->seedSpend(4.00); // 4.00 < 10.00 → allowed

        $session = $this->draftSession();

        $this->postJson("/api/generator/sessions/{$session->id}/generate")->assertStatus(202);

        $this->assertSame(GenerationSessionStatus::Generating, $session->fresh()->status);
        Queue::assertPushed(RunGenerationSessionJob::class, fn ($job) => $job->sessionId === $session->id);
    }
}
