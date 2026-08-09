<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Models\BotAction;
use App\Modules\Bot\Support\BotRunEstimate;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use App\Modules\Variables\Models\AiUsageEvent;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesBotExecutionAgent;
use Tests\TestCase;

/**
 * THE BOT'S OWN AI SPEND, on the ledger and under the cap.
 *
 * Until this existed the bot was the one AI spender on the platform that cost real money and left no
 * trace: the workspace $ cap from R2 sub-stage 4 gated the generator, the workflows, the Disk editor and
 * the knowledge composer, and did not gate the component that runs most often and entirely without a
 * human watching. The gap was invisible in exactly the way that matters — every test green, every screen
 * correct, the number simply absent.
 *
 * So these cases pin the four properties that gap consisted of:
 *   1. a run RECORDS what it really spent, on its own channel, with the bot as the actor;
 *   2. an exhausted budget REFUSES the run BEFORE the provider is reached — and says so on the timeline;
 *   3. with the cap OFF (the shipped default) nothing about a run changes;
 *   4. the projection the gate asks its question with is a projection of the WHOLE run, not one call.
 */
class BotAiMeteringTest extends TestCase
{
    use FakesBotExecutionAgent, RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->workspace->users()->attach($this->owner->id);

        $this->actingAs($this->owner)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);

        // The shipped default: no workspace override + env default 0.0 → the cap is OFF unless a case
        // opts in. Stated explicitly because the suite reads the developer's own .env (see CLAUDE.md).
        config()->set('ai.meter.monthly_cost_cap_default', 0.0);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers ---------------------------------------------------------------

    private function executingBot(): Bot
    {
        return Bot::factory()->executesTasks()->create(['creator_id' => $this->owner->id]);
    }

    private function createBotTask(Bot $bot): string
    {
        return $this->postJson('/api/tasks', [
            'title' => 'Bot task',
            'priority' => 'medium',
            'assignee_type' => 'bot',
            'assignee_id' => $bot->id,
        ])->assertCreated()->json('data.id');
    }

    /** Set the workspace's $ cap and re-prime the tenant context so cap() reads the fresh CENTRAL row. */
    private function setCap(?float $cap): void
    {
        $this->workspace->update(['ai_monthly_cost_cap' => $cap]);
        app(TenantContext::class)->set($this->workspace);
    }

    /** Seed one ledger event this month so the gate's month-to-date $ SUM reflects it. */
    private function seedSpend(float $cost): void
    {
        AiUsageEvent::create(['channel' => 'ai_text', 'total_tokens' => 0, 'estimated_cost' => $cost]);
    }

    // ---- 1. the run is on the ledger -------------------------------------------

    public function test_a_bot_run_records_its_real_provider_tokens_on_its_own_channel(): void
    {
        $bot = $this->executingBot();

        // 1200 + 300 real provider tokens, summed by laravel/ai across every step of the tool loop.
        $this->scriptBotRunReporting([['post_comment', ['text' => 'Working on it.']], ['finish']], 1200, 300);

        $taskId = $this->createBotTask($bot);

        $event = AiUsageEvent::where('channel', BotRunEstimate::CHANNEL)->sole();

        $this->assertSame(1200, $event->prompt_tokens);
        $this->assertSame(300, $event->completion_tokens);
        $this->assertSame(1500, $event->total_tokens);

        // 1500 tokens at the channel's configured $0.005/1k.
        $this->assertEqualsWithDelta(0.0075, (float) $event->estimated_cost, 0.0001);

        // And the run really did the work it was billed for.
        $this->assertEquals(TaskStatus::DONE, Task::find($taskId)->status);
    }

    /**
     * ATTRIBUTION. An autonomous run has no auth() and no workflow run, so without the explicit tag the
     * meter's own precedence chain would file every cent of it under "unattributed" — a ledger that
     * knows the money was spent and cannot say by whom is only half a ledger, and the per-actor screen
     * already knows how to render a bot.
     */
    public function test_the_spend_is_attributed_to_the_bot_not_to_nobody(): void
    {
        $bot = $this->executingBot();

        $this->scriptBotRunReporting([['post_comment', ['text' => 'Hi.']], ['finish']], 100, 50);

        $this->createBotTask($bot);

        $event = AiUsageEvent::where('channel', BotRunEstimate::CHANNEL)->sole();

        $this->assertSame('bot', $event->actor_type);
        $this->assertSame($bot->id, $event->actor_id);
    }

    /**
     * The tag covers the WHOLE run, not just the agent call. A bot reading a bound knowledge base in
     * `rag` mode bills an embedding BEFORE the agent has said a word, and that spend is no less the
     * bot's. Asserted through the actor of every event the run produced rather than by naming the
     * knowledge path, so it stays true whatever else the run learns to spend on.
     */
    public function test_everything_a_run_spends_carries_the_bot_actor(): void
    {
        $bot = $this->executingBot();

        $this->scriptBotRunReporting([['post_comment', ['text' => 'Hi.']], ['finish']], 10, 10);

        $this->createBotTask($bot);

        // Both halves, or the case is vacuous: a run that recorded NOTHING also has no unattributed
        // spend, and would pass an assertion about the second half alone while proving the opposite.
        $this->assertGreaterThan(0, AiUsageEvent::count(), 'the run must have recorded something at all.');
        $this->assertSame(0, AiUsageEvent::whereNull('actor_id')->count(), 'a run must leave no unattributed spend behind it.');
    }

    // ---- 2. an exhausted budget refuses before the provider ---------------------

    public function test_an_exhausted_budget_refuses_the_run_before_the_provider_is_called(): void
    {
        $bot = $this->executingBot();

        $this->setCap(1.00);
        $this->seedSpend(1.50);

        // Scripted so that IF the agent ran, it would comment and finish — the loud failure mode.
        $this->scriptBotRunReporting([['post_comment', ['text' => 'I should never appear.']], ['finish']], 999, 999);

        $taskId = $this->createBotTask($bot);

        // The provider was never reached: no comment, no completion, no ledger row for the run.
        $this->assertDatabaseMissing('comments', ['commentable_id' => $taskId, 'author_type' => 'bot']);
        $this->assertSame(0, AiUsageEvent::where('channel', BotRunEstimate::CHANNEL)->count());
        $this->assertEquals(TaskStatus::TO_DO, Task::find($taskId)->status);

        // The claim was never taken either, so the refusal costs the task none of its five run slots
        // and leaves nothing for the stale-run reaper to find.
        $task = Task::find($taskId);
        $this->assertSame(0, (int) $task->bot_runs_used);
        $this->assertSame('idle', $task->bot_run_state);
    }

    /**
     * A cap must not enforce itself by silence. A bot that simply stops doing anything, with an empty
     * timeline, is indistinguishable from a broken bot — and the person who would fix it (raise the cap)
     * is precisely the person who cannot see the cause.
     */
    public function test_the_refusal_is_visible_on_the_task_timeline(): void
    {
        $bot = $this->executingBot();

        $this->setCap(1.00);
        $this->seedSpend(1.50);
        $this->scriptBotRun([['post_comment', ['text' => 'x']], ['finish']]);

        $taskId = $this->createBotTask($bot);

        $action = BotAction::where('task_id', $taskId)->sole();

        $this->assertSame(BotActionType::ExecutionFailed, $action->type);

        // Asserted against the TRANSLATION, not against English words: the app runs in Polish by
        // default, and a substring assertion here would pass or fail on the ambient locale.
        $this->assertSame(__('bot.budget.run_refused'), (string) $action->error);
        $this->assertNotSame('bot.budget.run_refused', (string) $action->error, 'the key must resolve — both lang files carry it.');
    }

    // ---- 3. with the cap off, nothing changes -----------------------------------

    /**
     * The load-bearing no-op. Metering a component that was never metered must not start REFUSING work
     * for the many workspaces that never set a limit — that would turn a missing number into an outage.
     */
    public function test_with_the_cap_off_a_run_behaves_exactly_as_before(): void
    {
        $bot = $this->executingBot();

        $this->setCap(null);            // no override → env default → 0.0 → gate off
        $this->seedSpend(9999.00);      // and a spend that would blow any cap that WERE set

        $this->scriptBotRunReporting([['post_comment', ['text' => 'Done.']], ['finish']], 100, 25);

        $taskId = $this->createBotTask($bot);

        $this->assertEquals(TaskStatus::DONE, Task::find($taskId)->status);
        $this->assertDatabaseHas('comments', ['commentable_id' => $taskId, 'author_type' => 'bot']);
        $this->assertSame(1, AiUsageEvent::where('channel', BotRunEstimate::CHANNEL)->count());
    }

    /**
     * An explicit UNLIMITED override (0.00) is the same no-op by a different route — the Cap Option B
     * semantics the whole meter is built on, restated here because a bot run is the one spender whose
     * refusal nobody is watching for.
     */
    public function test_an_explicit_unlimited_override_does_not_refuse(): void
    {
        $bot = $this->executingBot();

        $this->setCap(0.00);
        $this->seedSpend(500.00);

        $this->scriptBotRun([['post_comment', ['text' => 'Done.']], ['finish']]);

        $taskId = $this->createBotTask($bot);

        $this->assertEquals(TaskStatus::DONE, Task::find($taskId)->status);
    }

    // ---- 4. the projection describes a LOOP, not a call -------------------------

    /**
     * The projection has to be of the WHOLE run, because the run cannot be stopped halfway: laravel/ai
     * owns the tool loop and returns only when it ends. So a gate that priced one call would wave a
     * workspace with a cent of headroom into a twelve-step run it cannot pay for.
     *
     * Pinned as a RELATION rather than a magic number (an estimate's exact value is tuning, its shape is
     * not): more steps must cost more, and the whole must exceed a single pass over the material.
     */
    public function test_the_run_projection_prices_the_whole_loop(): void
    {
        $context = str_repeat('a', 8000);

        config()->set('ai.bot_run_projected_steps', 1);
        $onePass = BotRunEstimate::forRun($context);

        config()->set('ai.bot_run_projected_steps', 3);
        $threeSteps = BotRunEstimate::forRun($context);

        $this->assertGreaterThan(0.0, $onePass);
        $this->assertGreaterThan($onePass * 2, $threeSteps, 'a step re-sends the conversation; three cost more than three times nothing.');
    }

    /** An unpriced channel projects nothing — exactly as it bills nothing. No accidental refusals. */
    public function test_an_unpriced_channel_projects_zero(): void
    {
        config()->set('ai.meter.pricing.' . BotRunEstimate::CHANNEL . '.per_1k_tokens', 0.0);

        $this->assertSame(0.0, BotRunEstimate::forRun(str_repeat('a', 8000)));
    }
}
