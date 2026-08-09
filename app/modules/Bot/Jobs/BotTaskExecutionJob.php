<?php

namespace App\Modules\Bot\Jobs;

use App\Modules\Bot\Agents\BotTaskExecutionAgent;
use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Bot\Enums\BotRunTrigger;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Services\BotActionService;
use App\Modules\Bot\Services\BotKnowledgeReader;
use App\Modules\Bot\Services\BotTaskContextBuilder;
use App\Modules\Bot\Services\BotTaskInteractionService;
use App\Modules\Bot\Services\BotTaskRunManager;
use App\Modules\Bot\Support\BotRunEstimate;
use App\Modules\Comments\Services\CommentService;
use App\Modules\Forms\Services\FormSubmissionService;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\TaskService;
use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Support\MeterContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * One INTERACTIVE run of a bot against a task (B4). Tenancy is carried across the queue
 * boundary by QueueTenancy (mirrors ProcessAiApprovalJob).
 *
 * The run is already CLAIMED (task.bot_run_state = running, bot_runs_used incremented)
 * before this job is dispatched (see BotTaskRunManager) — so the job just executes and
 * finalizes. The agent READS its context and ACTS through tools (post_comment,
 * fill_form, ask_and_wait, finish). A run ends when the agent calls a terminal tool or
 * simply stops:
 *   - finish   -> task submitted to in_test (approval attached) or moved to done (none),
 *     run-state released to idle,
 *   - ask_and_wait -> task stays in_progress, run-state set to waiting (a human reply
 *     resumes it),
 *   - otherwise -> run-state released to idle (task stays in_progress).
 *
 * On failure: record execution_failed, release the run-state to idle, do NOT advance.
 *
 * METER WIRING: everything a run spends is attributed to the BOT and billed to the `ai_bot_task`
 * channel — see {@see meteredPrompt()} for the per-run/per-step decision and {@see BotRunEstimate} for
 * what the gate projects. Until this existed a bot was the one AI spender on the platform that cost
 * real money and left no trace in the ledger, which also meant the workspace $ cap did not apply to the
 * component that runs most often and entirely without a human watching.
 */
class BotTaskExecutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public Bot $bot,
        public Task $task,
        public BotRunTrigger $trigger = BotRunTrigger::Initial,
    ) {}

    public function handle(
        TaskService $taskService,
        CommentService $commentService,
        FormSubmissionService $submissionService,
        BotActionService $actions,
        BotTaskContextBuilder $contextBuilder,
        BotTaskRunManager $runManager,
        BotKnowledgeReader $knowledgeReader,
        MeteredAiCall $meter,
        MeterContext $meterContext,
    ): void {
        $bot = $this->bot;
        $task = $this->task->fresh();

        if ($task === null) {
            return;
        }

        // Record how this run began (initial / resumed / revision_started) + move the
        // task into progress. task_started carries the run number for the timeline.
        $actions->record($bot, $task, BotActionType::TaskStarted, [
            'run' => $task->bot_runs_used,
            'trigger' => $this->trigger->value,
        ]);

        if ($this->trigger === BotRunTrigger::Resume) {
            $actions->record($bot, $task, BotActionType::Resumed, ['run' => $task->bot_runs_used]);
        } elseif ($this->trigger === BotRunTrigger::Revision) {
            $actions->record($bot, $task, BotActionType::RevisionStarted, ['run' => $task->bot_runs_used]);
        }

        $taskService->botStart($task);

        $interaction = new BotTaskInteractionService(
            $bot, $task, $commentService, $submissionService, $taskService, $actions,
        );

        // ACTOR (R2 sub-stage 4's escape hatch): an autonomous run has no auth() and no workflow run, so
        // the meter would attribute everything it spends to nobody. Tag the bot EXPLICITLY for the WHOLE
        // run — the knowledge read below bills an embedding before the agent has said a word — and clear
        // it in the finally so the tag can never outlive the run on a reused worker process.
        $meterContext->setActor($bot->getMorphClass(), (string) $bot->getKey());

        try {
            // The bound knowledge base, read LIVE for this run. It is the one part of the context
            // that can bill the workspace (one embedding, in `rag` mode only) — and it never fails:
            // every refusal degrades inside the Knowledge module to the free inline compilation.
            // A bot with no binding gets null and the builder keeps using the bot's own module.
            $knowledge = $knowledgeReader->read($bot, $task);

            if ($knowledge !== null) {
                // WHAT the bot saw, at WHICH revision. The base moves; this run does not, and
                // without the receipt an answer given today cannot be explained tomorrow.
                $actions->record($bot, $task, BotActionType::KnowledgeRead, $knowledge->auditPayload());
            }

            $context = $contextBuilder->build($task, $bot, $knowledge);

            // Resolve through the container (named args) so the agent is overridable in
            // tests (see ScriptedBotExecutionAgent). In production this builds the real
            // interactive agent.
            $agent = app()->makeWith(BotTaskExecutionAgent::class, [
                'bot' => $bot,
                'task' => $task,
                'context' => $context,
                'interaction' => $interaction,
            ]);

            $this->meteredPrompt($meter, $agent, $context);

            $runManager->release($task, $interaction->outcome());
        } catch (\Throwable $e) {
            Log::error("Bot task execution failed for task {$task->id}: {$e->getMessage()}");
            $actions->record($bot, $task, BotActionType::ExecutionFailed, status: 'failed', error: $e->getMessage());
            $runManager->release($task, null);
        } finally {
            $meterContext->clearActor();
        }
    }

    /**
     * Run the agent through the cost meter: GATE first, then spend, then record.
     *
     * PER RUN, NOT PER STEP — and that is a finding, not a preference. laravel/ai owns the tool loop:
     * one `prompt()` call runs every step internally and returns only when the loop ends, so there is no
     * seam to meter a step at without forking the package's gateway. What makes this acceptable is that
     * the returned response's `usage` is the SUM over every step (the gateway combines them), so the
     * ledger row carries the run's REAL total tokens — per-run recording loses no accuracy about the
     * money, only about the moment: the workspace learns the cost when the run ends, not while it runs.
     *
     * The gate therefore has to ask its question ONCE, up front, about the WHOLE run — which is exactly
     * what a projection is for ({@see BotRunEstimate}). Without one, a workspace with a cent of headroom
     * would be waved into a twelve-step run it cannot pay for and could not be stopped halfway through.
     * The residual risk is bounded and accepted: a run whose real cost exceeds the projection can end
     * slightly over cap. The next run is refused; the ledger stays honest about what happened.
     *
     * `meter()` re-checks the budget itself before invoking the closure. That second check is the
     * authoritative gate-before-spend (this one is a projection, and a projection must never be the only
     * thing standing between a provider and an over-cap workspace).
     */
    private function meteredPrompt(MeteredAiCall $meter, mixed $agent, string $context): void
    {
        $meter->assertWithinBudget(BotRunEstimate::CHANNEL, BotRunEstimate::forRun($context));

        $meter->meter(BotRunEstimate::CHANNEL, fn () => $agent->prompt(
            prompt: 'Zajmij się zadaniem. Użyj narzędzi. Zakończ przez finish lub ask_and_wait.',
            provider: config('ai.provider'),
            model: config('ai.model'),
        ));
    }

    /**
     * Last-resort claim release (S1). handle()'s try/catch covers agent/tool throwables,
     * but a job timeout or an exception escaping before/around handle() would otherwise
     * strand the task in `running` forever — and claim() only matches idle/waiting, so
     * no future run could ever recover it. $tries = 1, so this fires on first failure.
     * (A hard SIGKILL/OOM still bypasses this — do not enable an async worker without
     * a stale-claim reaper.)
     */
    public function failed(\Throwable $e): void
    {
        $task = $this->task->fresh();

        if ($task === null) {
            return;
        }

        Log::error("Bot task execution job failed for task {$task->id}: {$e->getMessage()}");

        app(BotActionService::class)->record(
            $this->bot, $task, BotActionType::ExecutionFailed, status: 'failed', error: $e->getMessage(),
        );

        app(BotTaskRunManager::class)->release($task, null);
    }
}
