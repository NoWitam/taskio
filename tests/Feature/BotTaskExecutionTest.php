<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Approvals\Enums\ApproverType;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Models\BotAction;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesBotExecutionAgent;
use Tests\TestCase;

/**
 * B4: interactive bot task execution. The run is driven through the scripted-tools seam
 * (ScriptedBotExecutionAgent) so multi-step tool sequences run deterministically without
 * a provider. QUEUE_CONNECTION=sync runs the dispatched job in-process with QueueTenancy.
 */
class BotTaskExecutionTest extends TestCase
{
    use FakesBotExecutionAgent, RefreshDatabase;

    private function executingBot(User $owner): Bot
    {
        return Bot::factory()->executesTasks()->create(['creator_id' => $owner->id]);
    }

    private function createBotTask(Bot $bot, array $overrides = []): string
    {
        return $this->postJson('/api/tasks', array_merge([
            'title' => 'Bot task',
            'priority' => 'medium',
            'assignee_type' => 'bot',
            'assignee_id' => $bot->id,
        ], $overrides))->assertCreated()->json('data.id');
    }

    public function test_multi_step_run_comment_fill_form_finish(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);
        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);

        $this->scriptBotRun([
            ['post_comment', ['text' => 'Working on it.']],
            ['fill_form', ['answers' => ['q1' => 'answer one']]],
            ['finish'],
        ]);

        $taskId = $this->createBotTask($bot, ['form_id' => $form->id]);
        $task = Task::find($taskId);

        // No approval pipeline on this task, so finish lands in DONE (nothing would ever
        // review an in_test task here) — see test_finish_with_an_approval_pipeline_*.
        $this->assertEquals(TaskStatus::DONE, $task->status);
        $this->assertDatabaseHas('comments', ['commentable_id' => $taskId, 'author_type' => 'bot']);
        $this->assertDatabaseHas('form_submissions', ['submittable_id' => $taskId, 'form_id' => $form->id]);

        // Actions recorded in order.
        $types = BotAction::where('task_id', $taskId)->orderBy('created_at')->pluck('type')->all();
        $this->assertSame([
            BotActionType::TaskStarted,
            BotActionType::Commented,
            BotActionType::FormFilled,
            BotActionType::MarkedDone,
        ], $types);
    }

    public function test_no_form_run_comment_then_finish(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);

        $this->scriptBotRun([
            ['post_comment', ['text' => 'Done by the bot.']],
            ['finish'],
        ]);

        $taskId = $this->createBotTask($bot);
        $task = Task::find($taskId);

        $this->assertEquals(TaskStatus::DONE, $task->status);
        $this->assertDatabaseHas('comments', ['commentable_id' => $taskId, 'author_type' => 'bot']);

        $types = BotAction::where('task_id', $taskId)->pluck('type')->all();
        $this->assertContains(BotActionType::Commented, $types);
        $this->assertContains(BotActionType::MarkedDone, $types);
        $this->assertNotContains(BotActionType::FormFilled, $types);
    }

    public function test_finish_without_filling_attached_form_is_blocked(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);
        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);

        // The agent tries to finish before filling the form — finish fails (tool-error),
        // so the task does NOT advance at all (neither in_test nor done).
        $this->scriptBotRun([
            ['post_comment', ['text' => 'skipping the form']],
            ['finish'],
        ]);

        $taskId = $this->createBotTask($bot, ['form_id' => $form->id]);

        $this->assertEquals(TaskStatus::IN_PROGRESS, Task::find($taskId)->status);
        $this->assertEmpty(
            BotAction::where('task_id', $taskId)
                ->whereIn('type', [BotActionType::SubmittedToTest, BotActionType::MarkedDone])
                ->get()
        );
    }

    public function test_ask_and_wait_ends_run_and_waits_for_human(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);

        $this->scriptBotRun([
            ['ask_and_wait', ['question' => 'Jaki ton mam przyjąć?']],
            // finish is scripted but must NOT run — ask_and_wait ended the run.
            ['finish'],
        ]);

        $taskId = $this->createBotTask($bot);
        $task = Task::find($taskId);

        // Run ended cleanly: task in_progress, waiting for a human.
        $this->assertEquals(TaskStatus::IN_PROGRESS, $task->status);
        $this->assertTrue($task->isBotWaiting());
        $this->assertDatabaseHas('comments', ['commentable_id' => $taskId, 'author_type' => 'bot']);
        $this->assertDatabaseHas('bot_actions', [
            'task_id' => $taskId,
            'type' => BotActionType::QuestionAsked->value,
        ]);
        // finish did not fire (neither terminal outcome was recorded).
        $this->assertEmpty(
            BotAction::where('task_id', $taskId)
                ->whereIn('type', [BotActionType::SubmittedToTest, BotActionType::MarkedDone])
                ->get()
        );

        $this->assertJsonPathWaiting($taskId, true);
    }

    public function test_bot_comment_does_not_resume_but_human_comment_does(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);

        // First run: ask and wait.
        $this->scriptBotRun([['ask_and_wait', ['question' => 'Pytanie?']]]);
        $taskId = $this->createBotTask($bot);
        $this->assertTrue(Task::find($taskId)->isBotWaiting());

        $runsAfterAsk = BotAction::where('task_id', $taskId)->where('type', BotActionType::TaskStarted)->count();

        // A BOT comment must NOT resume (author-type loop guard).
        app(\App\Modules\Comments\Services\CommentService::class)->create(
            Task::find($taskId),
            \App\Modules\Comments\DTOs\CommentDTO::fromBot($bot, 'still thinking'),
        );
        $this->assertSame(
            $runsAfterAsk,
            BotAction::where('task_id', $taskId)->where('type', BotActionType::TaskStarted)->count(),
            'A bot comment must not resume the run.'
        );

        // A HUMAN comment resumes: the second run finishes the task.
        $this->scriptBotRun([['post_comment', ['text' => 'Got it.']], ['finish']]);
        $this->postJson("/api/tasks/{$taskId}/comments", ['content' => 'Użyj formalnego tonu.'])
            ->assertCreated();

        $task = Task::find($taskId);
        // DONE, not IN_TEST: this task carries no approval pipeline.
        $this->assertEquals(TaskStatus::DONE, $task->status);
        $this->assertFalse($task->isBotWaiting());
        $this->assertDatabaseHas('bot_actions', [
            'task_id' => $taskId,
            'type' => BotActionType::Resumed->value,
        ]);
        $this->assertSame(
            2,
            BotAction::where('task_id', $taskId)->where('type', BotActionType::TaskStarted)->count(),
            'The human reply must trigger exactly one more run.'
        );
    }

    public function test_reject_triggers_a_bot_revision_run_with_rejection_reasons(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);
        $approver = User::factory()->create();

        $pipeline = ApprovalPipeline::factory()->create(['creator_id' => $owner->id]);
        $pipeline->stages()->create([
            'name' => 'Human review',
            'approver_type' => ApproverType::User,
            'approver_id' => $approver->id,
            'order' => 1,
        ]);

        // Initial run: comment + finish -> in_test -> pending approval.
        $this->scriptBotRun([['post_comment', ['text' => 'v1']], ['finish']]);
        $taskId = $this->createBotTask($bot, ['approval_pipeline_id' => $pipeline->id]);

        $process = \App\Modules\Approvals\Models\ApprovalProcess::where('approvable_id', $taskId)
            ->latest('id')->firstOrFail();

        // Capture the revision run's context: the rejection note must be present.
        $capturedContext = null;
        $this->app->bind(\App\Modules\Bot\Agents\BotTaskExecutionAgent::class, function ($app, $args) use (&$capturedContext) {
            $capturedContext = $args['context'];

            // Standalone double (not a subclass — the real prompt() has a strict return type).
            return new class
            {
                public function prompt(...$args): mixed
                {
                    return null; // revision run does nothing further; we only assert context
                }
            };
        });

        // Reject with a note -> task restored to the bot -> revision run dispatched.
        app(\App\Modules\Approvals\Services\ApprovalService::class)
            ->decide($process, ApprovalProcessStatus::Rejected, 'Popraw ton wypowiedzi.');

        $this->assertNotNull($capturedContext);
        $this->assertStringContainsString('Popraw ton wypowiedzi.', $capturedContext);
        $this->assertDatabaseHas('bot_actions', [
            'task_id' => $taskId,
            'type' => BotActionType::RevisionStarted->value,
        ]);
    }

    public function test_run_cap_hands_over_to_human(): void
    {
        config(['ai.max_runs_per_task' => 2]);

        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);

        // Run 1: ask and wait (used = 1, waiting).
        $this->scriptBotRun([['ask_and_wait', ['question' => 'q1']]]);
        $taskId = $this->createBotTask($bot);
        $this->assertTrue(Task::find($taskId)->isBotWaiting());

        // Run 2 (human reply): ask and wait again (used = 2, waiting, at cap).
        $this->scriptBotRun([['ask_and_wait', ['question' => 'q2']]]);
        $this->postJson("/api/tasks/{$taskId}/comments", ['content' => 'reply 1'])->assertCreated();
        $this->assertSame(2, Task::find($taskId)->bot_runs_used);

        // Run 3 (human reply): cap reached -> hand over, no run, no advance.
        $this->postJson("/api/tasks/{$taskId}/comments", ['content' => 'reply 2'])->assertCreated();

        $this->assertSame(2, Task::find($taskId)->bot_runs_used, 'Run counter must not exceed the cap.');
        $this->assertDatabaseHas('bot_actions', [
            'task_id' => $taskId,
            'type' => BotActionType::HandedOver->value,
        ]);
        $this->assertEquals(TaskStatus::IN_PROGRESS, Task::find($taskId)->status);
    }

    public function test_concurrency_guard_prevents_second_run_while_running(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);

        // Create the task WITHOUT triggering a run (assign later), then force the state
        // to running and confirm a dispatch is a no-op.
        $task = Task::factory()->create([
            'creator_id' => $owner->id,
            'assignee_type' => 'bot',
            'assignee_id' => $bot->id,
            'status' => TaskStatus::TO_DO,
            'bot_run_state' => 'running',
        ]);

        $this->scriptBotRun([['post_comment', ['text' => 'should not run']], ['finish']]);

        app(\App\Modules\Bot\Services\BotTaskExecutionService::class)->maybeDispatch($task);

        $this->assertDatabaseCount('bot_actions', 0);
        $this->assertEquals(TaskStatus::TO_DO, $task->fresh()->status);
    }

    /**
     * The RUNTIME guard, independent of the write-time validation: a task that somehow
     * ends up on a non-executing bot (assigned before the module was switched off, or
     * created outside the HTTP request path) never starts a run. The HTTP layer refuses
     * such an assignment outright — see
     * test_task_cannot_be_assigned_to_a_bot_that_cannot_execute.
     */
    public function test_non_executing_bot_does_not_dispatch(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = Bot::factory()->active()->create(['creator_id' => $owner->id]); // task_execution disabled

        $task = Task::factory()->create([
            'creator_id' => $owner->id,
            'assignee_type' => 'bot',
            'assignee_id' => $bot->id,
            'status' => TaskStatus::TO_DO,
        ]);

        $this->scriptBotRun([['post_comment', ['text' => 'nope']], ['finish']]);

        app(\App\Modules\Bot\Services\BotTaskExecutionService::class)->maybeDispatch($task);

        $this->assertEquals(TaskStatus::TO_DO, $task->fresh()->status);
        $this->assertDatabaseCount('bot_actions', 0);
    }

    public function test_failure_records_execution_failed_and_does_not_advance(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);

        $this->failBotRun();

        $taskId = $this->createBotTask($bot);
        $task = Task::find($taskId);

        $this->assertEquals(TaskStatus::IN_PROGRESS, $task->status);
        $this->assertSame('idle', $task->bot_run_state);
        $this->assertDatabaseHas('bot_actions', [
            'task_id' => $taskId,
            'type' => BotActionType::ExecutionFailed->value,
        ]);
    }

    public function test_bot_actions_endpoints_return_history_with_payload(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);

        $this->scriptBotRun([['ask_and_wait', ['question' => 'Pytanie do timeline?']]]);
        $taskId = $this->createBotTask($bot);

        $this->getJson("/api/bots/{$bot->id}/actions")
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'bot_id', 'task_id', 'type', 'payload', 'status']]]);

        $response = $this->getJson("/api/tasks/{$taskId}/bot-actions")->assertOk();
        $question = collect($response->json('data'))
            ->firstWhere('type', BotActionType::QuestionAsked->value);
        $this->assertSame('Pytanie do timeline?', $question['payload']['question']);
    }

    public function test_bot_can_be_executor_and_named_approver(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);

        $pipeline = ApprovalPipeline::factory()->create(['creator_id' => $owner->id]);
        $pipeline->stages()->create([
            'name' => 'AI Review',
            'icon' => 'archive',
            'description' => null,
            'approver_type' => ApproverType::Ai,
            'approver_id' => null,
            'order' => 1,
        ]);

        $this->scriptBotRun([['post_comment', ['text' => 'Self-reviewed.']], ['finish']]);
        $this->fakeApprovalEvaluation([
            'decision' => ApprovalProcessStatus::Approved->value,
            'note' => 'ok',
        ]);

        $taskId = $this->createBotTask($bot, ['approval_pipeline_id' => $pipeline->id]);

        $this->assertDatabaseHas('comments', ['commentable_id' => $taskId, 'author_type' => 'bot']);
        $this->assertContains(
            BotActionType::SubmittedToTest,
            BotAction::where('task_id', $taskId)->pluck('type')->all()
        );
    }

    /**
     * The PROVIDER-facing shape of fill_form: `answers` arrives as a JSON object STRING
     * (a free-form object cannot be declared under OpenAI strict mode — see
     * FillFormTool::schema()), and must land as the decoded answer map. Malformed JSON
     * raises an instructive tool-error instead of persisting garbage.
     */
    public function test_fill_form_accepts_answers_as_a_json_string(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);
        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);

        $this->scriptBotRun([
            ['fill_form', ['answers' => '{"q1":"z JSON-a","q2":["a","b"]}']],
            ['finish'],
        ]);

        $taskId = $this->createBotTask($bot, ['form_id' => $form->id]);

        $submission = FormSubmission::where('submittable_id', $taskId)->firstOrFail();

        $this->assertSame('z JSON-a', $submission->data['q1'] ?? null);
        $this->assertSame(['a', 'b'], $submission->data['q2'] ?? null);
        $this->assertEquals(TaskStatus::DONE, Task::find($taskId)->status);
    }

    /**
     * Where finish LANDS depends on whether anything will review the work. With no approval
     * pipeline attached, in_test would mean waiting for a review nobody performs, so the
     * task goes straight to done and the run records `marked_done`.
     */
    public function test_finish_without_an_approval_pipeline_marks_the_task_done(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);

        $this->scriptBotRun([['finish', ['summary' => 'Gotowe.']]]);

        $taskId = $this->createBotTask($bot);

        $this->assertEquals(TaskStatus::DONE, Task::find($taskId)->status);

        $done = BotAction::where('task_id', $taskId)
            ->where('type', BotActionType::MarkedDone)
            ->firstOrFail();

        // The optional finish summary rides along on the action (visible in the timeline).
        $this->assertSame('Gotowe.', $done->payload['summary'] ?? null);

        $this->assertDatabaseMissing('bot_actions', [
            'task_id' => $taskId,
            'type' => BotActionType::SubmittedToTest->value,
        ]);
        // Nothing to review means no approval process was invented either.
        $this->assertDatabaseCount('approval_processes', 0);
    }

    /**
     * The counterpart: an attached pipeline is exactly what makes in_test meaningful, so
     * finish stops there, starts the process and hands the task to the first approver.
     */
    public function test_finish_with_an_approval_pipeline_stops_at_in_test(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $approver = User::factory()->create();
        $bot = $this->executingBot($owner);

        $pipeline = ApprovalPipeline::factory()->create(['creator_id' => $owner->id]);
        $pipeline->stages()->create([
            'name' => 'Review',
            'approver_type' => ApproverType::User,
            'approver_id' => $approver->id,
            'order' => 1,
        ]);

        $this->scriptBotRun([['finish']]);

        $taskId = $this->createBotTask($bot, ['approval_pipeline_id' => $pipeline->id]);
        $task = Task::find($taskId);

        $this->assertEquals(TaskStatus::IN_TEST, $task->status);
        $this->assertSame('user', $task->assignee_type);
        $this->assertSame($approver->id, $task->assignee_id);
        $this->assertDatabaseHas('approval_processes', ['approvable_id' => $taskId]);

        $types = BotAction::where('task_id', $taskId)->pluck('type')->all();
        $this->assertContains(BotActionType::SubmittedToTest, $types);
        $this->assertNotContains(BotActionType::MarkedDone, $types);
    }

    /**
     * A bot that cannot execute tasks is REFUSED as an assignee (422) instead of being
     * accepted into a task that would never run: dispatch() would silently decline the
     * claim and the task would sit in to_do with nothing recorded to explain it.
     */
    public function test_task_cannot_be_assigned_to_a_bot_that_cannot_execute(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // Active, but the task-execution module was never enabled.
        $bot = Bot::factory()->active()->create(['creator_id' => $owner->id]);

        $this->postJson('/api/tasks', [
            'title' => 'Bot task',
            'priority' => 'medium',
            'assignee_type' => 'bot',
            'assignee_id' => $bot->id,
        ])->assertStatus(422)->assertJsonValidationErrors('assignee_id');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_inactive_bot_is_refused_even_with_the_module_enabled(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $bot = Bot::factory()->create([
            'creator_id' => $owner->id,
            'task_execution' => ['enabled' => true, 'tools' => []],
        ]); // factory default status = inactive

        $this->postJson('/api/tasks', [
            'title' => 'Bot task',
            'priority' => 'medium',
            'assignee_type' => 'bot',
            'assignee_id' => $bot->id,
        ])->assertStatus(422)->assertJsonValidationErrors('assignee_id');
    }

    /**
     * The guard fires on a CHANGE of assignee only. A task already held by a bot whose
     * module is switched off later must stay editable — otherwise deactivating one bot
     * would freeze every task assigned to it.
     */
    public function test_existing_bot_assignment_stays_editable_after_the_bot_is_disabled(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);

        $this->scriptBotRun([['finish']]);
        $taskId = $this->createBotTask($bot);

        // The bot loses its task-execution module afterwards.
        $bot->update(['task_execution' => ['enabled' => false, 'tools' => []]]);

        $this->putJson("/api/tasks/{$taskId}", [
            'title' => 'Renamed, same assignee',
            'priority' => 'high',
            'assignee_type' => 'bot',
            'assignee_id' => $bot->id,
        ])->assertOk();

        $this->assertSame('Renamed, same assignee', Task::find($taskId)->title);

        // Moving the task onto ANOTHER disabled bot is still refused.
        $other = Bot::factory()->active()->create(['creator_id' => $owner->id]);

        $this->putJson("/api/tasks/{$taskId}", [
            'title' => 'Renamed, same assignee',
            'priority' => 'high',
            'assignee_type' => 'bot',
            'assignee_id' => $other->id,
        ])->assertStatus(422)->assertJsonValidationErrors('assignee_id');
    }

    private function assertJsonPathWaiting(string $taskId, bool $expected): void
    {
        $this->getJson("/api/tasks/{$taskId}")
            ->assertOk()
            ->assertJsonPath('data.bot_waiting', $expected);
    }
}
