<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Jobs\RunGenerationSessionJob;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Models\Template;
use App\Modules\Generator\Services\GeneratedImageStore;
use App\Modules\Generator\Services\GenerationSessionLifecycleService;
use App\Modules\Generator\Services\GenerationSessionRunManager;
use App\Modules\Tasks\Models\Task;
use App\Modules\Variables\Agents\AiTextAgent;
use App\Modules\Variables\Models\AiUsageEvent;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Enums\WorkflowRunStepStatus;
use App\Modules\Workflows\Jobs\WorkflowRunJob;
use App\Modules\Workflows\Jobs\WorkflowRunResumeJob;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowRunManager;
use App\Modules\Workflows\Services\WorkflowStepRunner;
use App\Modules\Workflows\Steps\GenerateContentStep;
use App\Modules\Workflows\Support\RealQueueConnection;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\FakesBotExecutionAgent;
use Tests\TestCase;

/**
 * The `generate_content` STEP (R2 sub-stage 5, BE-2): a workflow runs a Generator template, PARKS while the
 * generation settles on the real queue, then collects the produced text + images.
 *
 * NO PROVIDER IS EVER CONTACTED. The one AI seam a `post` recipe uses is the `@[ai-text]` directive, and it
 * is SCRIPTED via `AiTextAgent::fake()`; the creative-direction layer is switched OFF (an un-scripted
 * derivation would be a real call), and produced IMAGES are injected as blobs rather than generated.
 *
 * THE QUEUE SHAPE MATTERS AND IS DELIBERATE. Every end-to-end case sets the ambient driver to a NON-sync
 * connection and invokes each job BY HAND, in the order a real deployment would:
 *   1. {@see WorkflowRunJob} — forces `sync` around the step loop; the step must escape it,
 *   2. {@see RunGenerationSessionJob} — a SEPARATE worker, no run context, no auth,
 *   3. {@see WorkflowRunResumeJob} — a fresh process that rebuilds everything from the database.
 * `Queue::fake` is PARTIAL (only those two job classes) so nothing else about dispatch changes.
 */
class WorkflowGenerateContentStepTest extends TestCase
{
    use FakesBotExecutionAgent, RefreshDatabase;

    /** The non-sync connection the worker pretends to run on — the value the escape hatch must publish. */
    private const REAL_CONNECTION = 'database';

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);

        // The direction layer would make an UNSCRIPTED provider call; the run cases here predate it.
        config()->set('generator.direction.enabled', false);

        AiTextAgent::fake(fn (string $prompt) => 'AI OUT');
    }

    protected function tearDown(): void
    {
        Queue::setDefaultDriver('sync');
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- fixtures ---------------------------------------------------------------

    /** An `@[ai-text]("…")` directive exactly as the editor encodes it (JSON then `"`→`\"`). */
    private function aiTextDirective(string $prompt): string
    {
        $payload = json_encode(['v' => 1, 'data' => ['id' => 'ai_1', 'personaId' => null, 'prompt' => $prompt, 'labels' => []]]);

        return '@[ai-text]("' . str_replace('"', '\\"', $payload) . '")';
    }

    /**
     * A `post` template whose body interpolates the `topic` slot AND makes one SCRIPTED `@[ai-text]` call —
     * so the produced content is provably generated (and provably metered) rather than a static string.
     */
    private function template(array $overrides = []): Template
    {
        return Template::factory()
            ->content(['body' => ['markdown' => 'Post about ' . \Database\Factories\TemplateFactory::directive('slots.topic') . ' — ' . $this->aiTextDirective('Describe the topic')]])
            ->create($overrides + ['creator_id' => $this->user->id]);
    }

    /** A template declaring exactly $slots (content unchanged). */
    private function templateWithSlots(array $slots): Template
    {
        return Template::factory()->slots($slots)->create(['creator_id' => $this->user->id]);
    }

    private function textSlot(string $name = 'topic', bool $nullable = false): array
    {
        return ['name' => $name, 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => $nullable, 'array' => false]];
    }

    private function fileSlot(string $name = 'photo'): array
    {
        return ['name' => $name, 'description' => 'Hero image', 'descriptor' => \Database\Factories\TemplateFactory::fileDescriptor()];
    }

    /** An `array<text>` slot — a list the recipe joins; its variable type is MULTI. */
    private function arrayedTextSlot(string $name = 'tags'): array
    {
        return ['name' => $name, 'description' => 'The tags', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => true]];
    }

    /** A LIST-of-files slot — a deferred composite for every automatic filler. */
    private function fileListSlot(string $name = 'gallery', bool $nullable = false): array
    {
        return ['name' => $name, 'description' => 'Gallery', 'descriptor' => ['array' => true, 'nullable' => $nullable]
            + \Database\Factories\TemplateFactory::fileDescriptor()];
    }

    /** An OBJECT slot exactly as the template editor's object-fields sub-editor authors it. */
    private function objectSlot(string $name = 'brief', bool $nullable = false, bool $array = false): array
    {
        return ['name' => $name, 'description' => 'The brief', 'descriptor' => [
            'base' => 'object',
            'nullable' => $nullable,
            'array' => $array,
            'fields' => [
                ['key' => 'title', 'label' => 'title', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]],
            ],
        ]];
    }

    /** The step definition for a template + slot mapping. */
    private function generateStep(Template $template, array $slots, array $extra = []): array
    {
        return ['type' => 'generate_content', 'key' => 'gen', 'config' => [
            'template_id' => $template->id,
            'slots' => $slots,
        ] + $extra];
    }

    /**
     * A workflow that generates and then creates a task from the result: the task's title carries the
     * generated CONTENT and its attachments reference the exported `image_file_ids` — the two outputs a
     * later step is expected to consume.
     */
    private function workflowWith(array $steps): Workflow
    {
        return Workflow::factory()->create([
            'creator_id' => $this->user->id,
            'workspace_id' => $this->workspace->id,
            'steps' => $steps,
        ]);
    }

    private function followUpTaskStep(): array
    {
        return ['type' => 'create_task', 'key' => 'after', 'config' => [
            'title' => 'Post: {{steps.gen.content}}',
            'attachments' => ['kind' => 'variable', 'ref' => ['source' => 'steps', 'path' => 'steps.gen.image_file_ids', 'type' => 'file']],
        ]];
    }

    /** Start a pending run and execute its FIRST pass on a non-sync ambient connection. */
    private function runFirstPass(Workflow $workflow, array $triggerPayload = []): WorkflowRun
    {
        $run = WorkflowRun::factory()->pending()->create([
            'workflow_id' => $workflow->id,
            'creator_id' => $this->user->id,
            'trigger_payload' => $triggerPayload,
        ]);

        Queue::setDefaultDriver(self::REAL_CONNECTION);

        (new WorkflowRunJob($run->id))->handle(app(WorkflowRunManager::class), app(WorkflowStepRunner::class));

        return $run->fresh();
    }

    /** The SEPARATE generation worker: a fresh job, scalars only, no run context. */
    private function runGenerationWorker(GenerationSession $session): void
    {
        (new RunGenerationSessionJob($session->id, $this->workspace->id))->handle(app(GenerationSessionRunManager::class));
    }

    /** The resume pass, correlated to the key the run is parked on. */
    private function resume(WorkflowRun $run): void
    {
        (new WorkflowRunResumeJob($run->id, $this->workspace->id, (string) $run->waiting_key))->handle(
            app(WorkflowRunManager::class),
            app(WorkflowStepRunner::class),
        );
    }

    /** The ONE session in the workspace (for cases that create exactly one). */
    private function theSession(): GenerationSession
    {
        $session = GenerationSession::query()->first();
        $this->assertNotNull($session, 'the step must have created a generation session');

        return $session;
    }

    /** The session a PARKED run is waiting on — read from the wait record, never guessed by recency. */
    private function sessionOf(WorkflowRun $run): GenerationSession
    {
        $this->assertIsArray($run->waiting_on, 'the run must be parked to have a session');

        return GenerationSession::findOrFail($run->waiting_on['payload']['session_id']);
    }

    /**
     * Inject produced images (bytes + results) — the image half without an image provider.
     *
     * THREE image-owning parts on purpose, across TWO shapes: a two-shot `storyboard` (nested per-item
     * images) AND a top-level `image` / `image_plan` part. A single-image fixture could not tell "the export
     * enumerated every produced key" apart from "it exported the one key it happened to look at", so a
     * missed key would have been a silently lost image nobody noticed. The bytes are identical for all three
     * (so callers can still assert content); the ORDER and completeness are asserted through the exported
     * file NAMES, which carry the part key.
     */
    private function injectProducedImage(GenerationSession $session, string $bytes = 'PNG-BYTES'): void
    {
        $store = app(GeneratedImageStore::class);
        $version = fn (string $partKey): int => $store->storeVersion($session->id, $partKey, $bytes);

        $session->update(['results' => (is_array($session->results) ? $session->results : []) + [
            'storyboard' => [
                'kind' => 'storyboard',
                'status' => 'ok',
                'shots' => [
                    ['index' => 0, 'image_status' => 'ok', 'image' => ['version' => $version('storyboard.0')], 'part_key' => 'storyboard.0'],
                    ['index' => 1, 'image_status' => 'ok', 'image' => ['version' => $version('storyboard.1')], 'part_key' => 'storyboard.1'],
                ],
            ],
            'image' => ['kind' => 'image_plan', 'status' => 'ok', 'version' => $version('image')],
        ]]);
    }

    /** The Disk names of the files an export produced, in the order the step returned their ids. */
    private function exportedNames(array $fileIds): array
    {
        return array_map(fn (string $id): string => File::findOrFail($id)->name, $fileIds);
    }

    /** The name {@see \App\Modules\Workflows\Steps\GenerateContentStep} gives one exported part. */
    private function exportedName(string $partKey): string
    {
        return __('workflows.steps.generate_content.image_name') . ' - ' . $partKey . '.png';
    }

    // ---- the end-to-end happy path ----------------------------------------------

    public function test_it_suspends_generates_on_a_real_worker_resumes_and_feeds_the_next_step(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $template = $this->template();
        $run = $this->runFirstPass($this->workflowWith([
            $this->generateStep($template, ['topic' => 'launch day']),
            $this->followUpTaskStep(),
        ]));

        // ---- 1. the run PARKED, and nothing ran inline ---------------------------
        $session = $this->theSession();

        $this->assertSame(WorkflowRunState::WAITING, $run->state);
        $this->assertSame(GenerateContentStep::correlationKey($session->id), $run->waiting_key);
        $this->assertSame(['session_id' => $session->id], $run->waiting_on['payload']);
        $this->assertSame(GenerateContentStep::WAIT_KIND, $run->waiting_on['kind']);
        $this->assertSame(GenerationSessionStatus::Generating, $session->status);

        // THE REAL-QUEUE PROOF: the generation was dispatched onto the connection that was active BEFORE
        // the run job forced `sync`. Without the escape hatch it would have been `sync` — i.e. executed
        // INLINE inside the step, which is exactly what the suspension exists to avoid.
        Queue::assertPushed(
            RunGenerationSessionJob::class,
            fn (RunGenerationSessionJob $job) => $job->sessionId === $session->id
                && $job->connection === self::REAL_CONNECTION,
        );
        $this->assertNull($session->results, 'the generation must NOT have executed inside the step');
        $this->assertSame(0, AiUsageEvent::count(), 'no AI spend may happen before the worker runs');

        // No step row is written for a suspension, and the LATER step did not run.
        $this->assertSame(0, $run->steps()->count());
        $this->assertSame(0, Task::count());

        // ---- 2. the SEPARATE generation worker ----------------------------------
        $this->runGenerationWorker($session);
        $session->refresh();

        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->injectProducedImage($session);

        // The settle listener saw the terminal broadcast and dispatched the correlated resume job.
        Queue::assertPushed(
            WorkflowRunResumeJob::class,
            fn (WorkflowRunResumeJob $job) => $job->runId === $run->id
                && $job->waitingKey === GenerateContentStep::correlationKey($session->id)
                && $job->workspaceId === $this->workspace->id,
        );

        // ---- 3. the resume pass --------------------------------------------------
        $this->resume($run);
        $run->refresh();

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertNull($run->waiting_on);
        $this->assertNull($run->waiting_key);

        $output = $run->context['steps']['gen'];

        $this->assertSame($session->id, $output['session_id']);
        $this->assertNotSame('', $output['content']);
        $this->assertStringContainsString('AI OUT', $output['content']);
        $this->assertSame('ready', $output['status']);
        $this->assertFalse($output['has_failed_parts']);

        // EVERY produced part is exported, in results order — a nested storyboard shot is not "the" image,
        // and a missed key would be an image the run paid for and then silently lost.
        $this->assertCount(3, $output['image_file_ids']);
        $this->assertSame(
            [$this->exportedName('storyboard.0'), $this->exportedName('storyboard.1'), $this->exportedName('image')],
            $this->exportedNames($output['image_file_ids']),
        );

        // ---- 4. the exported image is a REAL Disk file attributed to the RUN -----
        $file = File::findOrFail($output['image_file_ids'][0]);

        $this->assertSame('workflow_run', $file->uploader_type);
        $this->assertSame($run->id, $file->uploader_id);
        $this->assertSame($this->workspace->id, $file->workspace_id);
        $this->assertSame('PNG-BYTES', Storage::get($file->path));

        // ---- 5. the following step consumed both outputs -------------------------
        $task = Task::firstOrFail();
        $this->assertStringContainsString('AI OUT', $task->title);
        $this->assertSame(3, $task->files()->count(), 'the create_task step must attach a COPY of EVERY exported image');
        $this->assertNotSame($file->id, $task->files()->first()->id);

        $steps = $run->steps()->get();
        $this->assertSame(['gen', 'after'], $steps->pluck('key')->all());
        $this->assertSame(WorkflowRunStepStatus::SUCCEEDED, $steps[0]->status);
    }

    public function test_every_ai_spend_of_the_generation_is_attributed_to_the_workflow_run(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $run = $this->runFirstPass($this->workflowWith([
            $this->generateStep($this->template(), ['topic' => 'launch day']),
        ]));

        $session = $this->theSession();
        $this->runGenerationWorker($session);

        // The session was created INSIDE the run, so HasCreator stamped the run on it; the executor reads
        // that back as the meter ACTOR. This is what makes an automated run's spend attributable in the
        // per-actor AI-usage view without the generation worker knowing anything about workflows.
        $this->assertSame('workflow_run', $session->fresh()->creator_type);
        $this->assertSame($run->id, $session->fresh()->creator_id);

        $events = AiUsageEvent::all();
        $this->assertGreaterThan(0, $events->count(), 'the scripted ai-text call must be metered');

        foreach ($events as $event) {
            $this->assertSame('workflow_run', $event->actor_type);
            $this->assertSame($run->id, $event->actor_id);
            $this->assertSame($session->id, $event->session_id);
            $this->assertSame($this->workspace->id, $event->workspace_id);
        }
    }

    // ---- the STRANDED path (the reason the sweep, not the event, is the backstop) ----

    public function test_a_reaper_settled_session_still_resumes_the_run_without_any_broadcast(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $run = $this->runFirstPass($this->workflowWith([
            $this->generateStep($this->template(), ['topic' => 'launch day']),
            $this->followUpTaskStep(),
        ]));

        $session = $this->theSession();
        $this->assertSame(WorkflowRunState::WAITING, $run->state);

        // The generation worker was SIGKILLed: the session is stuck `generating` and its job's failed()
        // hook never fired. The generator's lifecycle reaper sweeps the SHARED database with the tenant
        // context CLEARED — and in that pass the run manager deliberately does NOT broadcast, so the
        // settle listener can never fire. An event-only design would strand this run forever.
        config()->set('generator.session_stale_after', 60);
        GenerationSession::withoutTimestamps(fn () => $session->forceFill(['updated_at' => now()->subHour()])->saveQuietly());

        app(TenantContext::class)->clear();
        $this->assertSame(1, app(GenerationSessionLifecycleService::class)->reapStale());
        app(TenantContext::class)->set($this->workspace);

        $this->assertSame(GenerationSessionStatus::Failed, $session->fresh()->status);
        Queue::assertNotPushed(WorkflowRunResumeJob::class);
        $this->assertSame(WorkflowRunState::WAITING, $run->fresh()->state, 'nothing woke the run — that is the whole point');

        // THE BACKSTOP: the waiting sweep asks the registered resolver, sees a SETTLED (failed) session,
        // and dispatches the correlated resume job.
        $this->assertSame(1, app(WorkflowRunManager::class)->reapWaitingRuns());
        Queue::assertPushed(
            WorkflowRunResumeJob::class,
            fn (WorkflowRunResumeJob $job) => $job->runId === $run->id,
        );

        $this->resume($run->fresh());
        $run->refresh();

        // A settled-FAILED generation is a terminal step failure, recorded like any other.
        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertStringContainsString('failed', $run->error);
        $this->assertSame(WorkflowRunStepStatus::FAILED, $run->steps()->first()->status);
        $this->assertSame(0, Task::count(), 'the later step must not run after a failed generation');
    }

    public function test_a_deleted_session_is_gone_not_pending_and_fails_the_run(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $run = $this->runFirstPass($this->workflowWith([
            $this->generateStep($this->template(), ['topic' => 'launch day']),
        ]));

        $this->theSession()->delete();

        // The sweep's resolver reports GONE, which fails the run immediately rather than waiting the
        // wait-timeout out and then reporting a misleading "timed out".
        $this->assertSame(1, app(WorkflowRunManager::class)->reapWaitingRuns());
        $this->assertSame(WorkflowRunState::FAILED, $run->fresh()->state);
        $this->assertSame(__('workflows.runs.wait_gone'), $run->fresh()->error);
    }

    /**
     * TENANCY IS NOT AMBIENT. The waiting sweep dispatches the resume from a CLEARED context, so QueueTenancy
     * stamps nothing and the job's own workspace lookup is the ONLY thing that re-establishes the tenant. If
     * that lookup can fail SILENTLY, the whole resume — the run read, the session read, and the Disk write
     * that creates real user-visible files — proceeds under whatever context happens to be ambient in the
     * worker. So an unresolvable workspace id is a HARD STOP: nothing is claimed, nothing is written, and the
     * run stays parked for the reaper.
     */
    public function test_a_resume_whose_workspace_cannot_be_resolved_leaves_the_run_parked(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $run = $this->runFirstPass($this->workflowWith([
            $this->generateStep($this->template(), ['topic' => 'launch day']),
            $this->followUpTaskStep(),
        ]));

        $session = $this->sessionOf($run);
        $this->runGenerationWorker($session);
        $this->injectProducedImage($session->fresh());

        // The context is deliberately LEFT SET (a long-running worker's ambient tenant) — the point is that
        // the job must not fall back to it.
        (new WorkflowRunResumeJob($run->id, (string) Str::uuid(), (string) $run->waiting_key))->handle(
            app(WorkflowRunManager::class),
            app(WorkflowStepRunner::class),
        );

        $run->refresh();

        $this->assertSame(WorkflowRunState::WAITING, $run->state, 'the run must still be parked');
        $this->assertSame(GenerateContentStep::correlationKey($session->id), $run->waiting_key);
        $this->assertSame(0, $run->steps()->count());
        $this->assertSame(0, File::count(), 'no Disk file may be written outside a resolved tenant');
        $this->assertSame(0, Task::count());

        // …and the honest delivery still finishes it, so this is a stop, not a strand.
        $this->resume($run);

        $this->assertSame(WorkflowRunState::COMPLETED, $run->fresh()->state);
    }

    public function test_a_still_generating_session_re_suspends_instead_of_duplicating_work(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $run = $this->runFirstPass($this->workflowWith([
            $this->generateStep($this->template(), ['topic' => 'launch day']),
            $this->followUpTaskStep(),
        ]));

        $session = $this->theSession();
        $waitBefore = $run->waiting_on;

        // A spurious/early resume (a redelivery, a manual sweep) while the generation is still running.
        $this->resume($run);
        $run->refresh();

        $this->assertSame(WorkflowRunState::WAITING, $run->state, 're-suspended on the same leg');
        $this->assertSame($waitBefore['payload'], $run->waiting_on['payload']);
        $this->assertSame(GenerateContentStep::correlationKey($session->id), $run->waiting_key);
        $this->assertSame(0, $run->steps()->count());
        $this->assertSame(1, GenerationSession::count(), 'no second session — no duplicated work');
        $this->assertSame(0, Task::count());

        // …and it still completes normally once the generation really settles.
        $this->runGenerationWorker($session);
        $this->resume($run->fresh());

        $this->assertSame(WorkflowRunState::COMPLETED, $run->fresh()->state);
        $this->assertSame(1, GenerationSession::count());
    }

    /**
     * THE INLINE-GENERATION TRAP. The step dispatches onto {@see RealQueueConnection::current()}; a NULL
     * there is NOT "the default connection" — `claimAndDispatch` falls back to `config('queue.default')`,
     * which both run jobs have MUTATED to `sync` around the step loop. So a null hatch inside a run pass
     * would run the whole generation INLINE, inside this step, and the suspension (and the settle event
     * ordering that depends on it) would be defeated. Unreachable today because both run jobs guarantee a
     * published value — which is exactly why it is pinned: it must fail LOUDLY, before any spend, if that
     * ever stops being true.
     */
    public function test_a_missing_real_queue_hatch_under_a_sync_default_refuses_before_any_spend(): void
    {
        Queue::fake([RunGenerationSessionJob::class]);

        $template = $this->template();
        $run = WorkflowRun::factory()->pending()->create([
            'workflow_id' => $this->workflowWith([])->id,
            'creator_id' => $this->user->id,
        ]);

        app(RealQueueConnection::class)->clear();
        Queue::setDefaultDriver('sync');

        try {
            app(GenerateContentStep::class)->run(
                ['template_id' => $template->id, 'slots' => ['topic' => 'launch day']],
                $run,
                [],
            );

            $this->fail('the step must refuse to start a generation it would run inline');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('queue', $e->getMessage());
        }

        $this->assertSame(0, GenerationSession::count(), 'the refusal must come BEFORE the draft session');
        $this->assertSame(0, AiUsageEvent::count());
        Queue::assertNotPushed(RunGenerationSessionJob::class);
    }

    // ---- the budget gate --------------------------------------------------------

    public function test_an_over_cap_workspace_fails_the_step_with_a_localized_message_and_bills_nothing(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $this->workspace->update(['ai_monthly_cost_cap' => 1.00]);
        app(TenantContext::class)->set($this->workspace);
        AiUsageEvent::create([
            'channel' => 'ai_text',
            'prompt_tokens' => 150,
            'completion_tokens' => 0,
            'total_tokens' => 150,
            'estimated_cost' => 1.50,
        ]);

        $run = $this->runFirstPass($this->workflowWith([
            $this->generateStep($this->template(), ['topic' => 'launch day']),
            $this->followUpTaskStep(),
        ]));

        $this->assertSame(WorkflowRunState::FAILED, $run->state);

        // The refusal is legible OUTSIDE http: a non-empty, localized, non-secret message — not the empty
        // string a render()-only exception would have produced.
        $this->assertNotSame('', (string) $run->error);
        $this->assertSame(__('generator.sessions.ai_budget_exceeded'), $run->error);

        // Gate BEFORE spend: the session was never claimed, nothing was queued, nothing new was billed.
        $this->assertSame(GenerationSessionStatus::Draft, $this->theSession()->status);
        Queue::assertNotPushed(RunGenerationSessionJob::class);
        $this->assertSame(1, AiUsageEvent::count());

        // The later step is skipped, exactly like any other step failure.
        $this->assertSame(0, Task::count());
        $this->assertSame(WorkflowRunStepStatus::FAILED, $run->steps()->first()->status);
    }

    // ---- slot resolution --------------------------------------------------------

    public function test_a_file_slot_resolves_from_a_trigger_field_and_a_foreign_file_does_not(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $template = $this->templateWithSlots([$this->textSlot(), $this->fileSlot()]);
        $file = File::factory()->image()->inFolder()->create(['uploader_id' => $this->user->id]);

        $steps = [$this->generateStep($template, [
            'topic' => 'launch day',
            'photo' => ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'trigger.fields.attachment', 'type' => 'file']],
        ])];

        $payload = fn (File $f) => ['fields' => ['attachment' => [[
            'id' => $f->id, 'name' => $f->name, 'mime_type' => $f->mime_type, 'size' => $f->size,
        ]]]];

        $run = $this->runFirstPass($this->workflowWith($steps), $payload($file));

        $this->assertSame(WorkflowRunState::WAITING, $run->state);
        // What is PERSISTED is the SERVER's own snapshot of the resolved row, never the payload's map.
        $this->assertSame($file->id, $this->sessionOf($run)->slot_values['photo']['id']);

        // A file from ANOTHER workspace never resolves — the fill drops it, so the required slot stays
        // unfilled and the step hard-fails instead of generating against a foreign asset.
        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $other->users()->attach($this->user->id);
        app(TenantContext::class)->set($other);
        $foreign = File::factory()->image()->inFolder()->create(['uploader_id' => $this->user->id]);
        app(TenantContext::class)->set($this->workspace);

        $foreignRun = $this->runFirstPass($this->workflowWith($steps), $payload($foreign));

        $this->assertSame(WorkflowRunState::FAILED, $foreignRun->state);
        $this->assertStringContainsString('photo', (string) $foreignRun->error);
    }

    public function test_an_unmapped_required_slot_hard_fails_naming_the_slot(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        foreach ([[], ['topic' => '']] as $index => $slots) {
            $run = $this->runFirstPass($this->workflowWith([
                $this->generateStep($this->template(), $slots),
                $this->followUpTaskStep(),
            ]));

            $this->assertSame(WorkflowRunState::FAILED, $run->state, 'case ' . $index);
            $this->assertStringContainsString('topic', (string) $run->error, 'case ' . $index);
            $this->assertSame(0, Task::count(), 'case ' . $index);
        }

        Queue::assertNotPushed(RunGenerationSessionJob::class);
    }

    public function test_template_drift_after_authoring_hard_fails_instead_of_generating_a_half_filled_recipe(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $template = $this->templateWithSlots([$this->textSlot()]);
        $workflow = $this->workflowWith([$this->generateStep($template, ['topic' => 'launch day'])]);

        // The author added a REQUIRED slot to the recipe long after the step was written.
        $template->update(['slots' => [$this->textSlot(), $this->textSlot('audience')]]);

        $run = $this->runFirstPass($workflow);

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertStringContainsString('audience', (string) $run->error);
        Queue::assertNotPushed(RunGenerationSessionJob::class);
    }

    public function test_a_missing_or_foreign_template_hard_fails_the_step(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $other->users()->attach($this->user->id);
        app(TenantContext::class)->set($other);
        $foreign = $this->template();
        app(TenantContext::class)->set($this->workspace);

        foreach ([(string) Str::uuid(), $foreign->id] as $id) {
            $run = $this->runFirstPass($this->workflowWith([
                ['type' => 'generate_content', 'key' => 'gen', 'config' => ['template_id' => $id, 'slots' => []]],
            ]));

            $this->assertSame(WorkflowRunState::FAILED, $run->state);
            $this->assertStringContainsString('no longer exists', (string) $run->error);
        }

        $this->assertSame(0, GenerationSession::count(), 'a template that does not resolve must never create a session');
    }

    // ---- the export folder ------------------------------------------------------

    public function test_the_images_land_in_the_configured_folder_and_degrade_to_the_root_when_it_is_gone(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $folder = Folder::factory()->create(['creator_id' => $this->user->id]);

        $run = $this->runFirstPass($this->workflowWith([
            $this->generateStep($this->template(), ['topic' => 'launch day'], ['folder_id' => $folder->id]),
        ]));

        $session = $this->sessionOf($run);
        $this->runGenerationWorker($session);
        $this->injectProducedImage($session->fresh());

        $this->resume($run->fresh());
        $run->refresh();

        $file = File::findOrFail($run->context['steps']['gen']['image_file_ids'][0]);
        $this->assertSame($folder->id, $file->fileable_id);

        // A folder DELETED while the run waited must not throw a 404 out of a queued step and discard
        // content the run already paid to generate — the export degrades to the Disk root.
        $second = $this->runFirstPass($this->workflowWith([
            $this->generateStep($this->template(), ['topic' => 'launch day'], ['folder_id' => $folder->id]),
        ]));

        $secondSession = $this->sessionOf($second);
        $this->runGenerationWorker($secondSession);
        $this->injectProducedImage($secondSession->fresh(), 'SECOND-BYTES');
        $folder->delete();

        $this->resume($second->fresh());
        $second->refresh();

        $this->assertSame(WorkflowRunState::COMPLETED, $second->state);
        $rootFile = File::findOrFail($second->context['steps']['gen']['image_file_ids'][0]);
        $this->assertNull($rootFile->fileable_id);
        $this->assertSame('SECOND-BYTES', Storage::get($rootFile->path));
    }

    /**
     * The THIRD image-owning shape (`scene_plan.<i>`, the back-compat sibling of `storyboard.<i>`) is
     * exported too — and the NESTED per-item failure arm of `has_failed_parts` is the one that actually
     * happens in production (a whole part failing is rarer than one shot's image failing), so it is pinned
     * here: the part is `ok` at the top level and the flag is still true.
     */
    public function test_a_scene_plan_exports_its_images_and_one_failed_scene_still_flags_the_run(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $run = $this->runFirstPass($this->workflowWith([
            $this->generateStep($this->template(), ['topic' => 'launch day']),
        ]));

        $session = $this->sessionOf($run);
        $this->runGenerationWorker($session);
        $session->refresh();

        $version = app(GeneratedImageStore::class)->storeVersion($session->id, 'scene_plan.0', 'SCENE-BYTES');
        $session->update(['results' => (array) $session->results + [
            'scene_plan' => [
                'kind' => 'scene_plan',
                'status' => 'ok',
                'scenes' => [
                    ['index' => 0, 'image_status' => 'ok', 'image' => ['version' => $version]],
                    ['index' => 1, 'image_status' => 'failed', 'error' => 'the image provider refused'],
                ],
            ],
        ]]);

        $this->resume($run->fresh());
        $run->refresh();

        $output = $run->context['steps']['gen'];

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertSame([$this->exportedName('scene_plan.0')], $this->exportedNames($output['image_file_ids']));
        $this->assertSame('SCENE-BYTES', Storage::get(File::findOrFail($output['image_file_ids'][0])->path));
        $this->assertTrue($output['has_failed_parts'], 'a per-item image failure is a failed part too');
    }

    public function test_a_partially_failed_run_is_reported_through_has_failed_parts(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $run = $this->runFirstPass($this->workflowWith([
            $this->generateStep($this->template(), ['topic' => 'launch day']),
        ]));

        $session = $this->sessionOf($run);
        $this->runGenerationWorker($session);

        $session->refresh();
        $session->update(['results' => (array) $session->results + ['image' => ['kind' => 'image_plan', 'status' => 'failed', 'error' => 'x']]]);

        $this->resume($run->fresh());
        $run->refresh();

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertTrue($run->context['steps']['gen']['has_failed_parts']);
        $this->assertSame([], $run->context['steps']['gen']['image_file_ids']);
    }

    // ---- author-time validation --------------------------------------------------

    private function payload(array $steps): array
    {
        return [
            'name' => 'Generate a post',
            'trigger_type' => 'form_submitted',
            'trigger_config' => ['form_id' => null],
            'steps' => $steps,
        ];
    }

    public function test_the_step_saves_with_a_valid_template_and_slot_mapping(): void
    {
        $template = $this->templateWithSlots([$this->textSlot(), $this->textSlot('audience', nullable: true)]);

        $this->postJson('/api/workflows', $this->payload([
            $this->generateStep($template, ['topic' => 'launch day']),
        ]))->assertCreated();
    }

    public function test_a_missing_or_foreign_template_id_is_a_422(): void
    {
        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $other->users()->attach($this->user->id);
        app(TenantContext::class)->set($other);
        $foreign = $this->template();
        app(TenantContext::class)->set($this->workspace);

        $this->postJson('/api/workflows', $this->payload([
            ['type' => 'generate_content', 'key' => 'gen', 'config' => ['slots' => []]],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.template_id']);

        $this->postJson('/api/workflows', $this->payload([
            ['type' => 'generate_content', 'key' => 'gen', 'config' => ['template_id' => $foreign->id, 'slots' => []]],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.template_id']);
    }

    public function test_an_unmapped_required_slot_is_a_granular_422_keyed_to_the_slot(): void
    {
        $template = $this->templateWithSlots([$this->textSlot(), $this->textSlot('audience')]);

        $this->postJson('/api/workflows', $this->payload([
            $this->generateStep($template, ['topic' => 'launch day']),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.0.config.slots.audience'])
            ->assertJsonMissingValidationErrors(['steps.0.config.slots.topic']);
    }

    public function test_an_undeclared_slot_name_is_a_granular_422(): void
    {
        $template = $this->templateWithSlots([$this->textSlot()]);

        $this->postJson('/api/workflows', $this->payload([
            $this->generateStep($template, ['topic' => 'x', 'topik' => 'typo']),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.0.config.slots.topik']);
    }

    public function test_a_foreign_config_key_and_a_foreign_folder_are_rejected(): void
    {
        $template = $this->templateWithSlots([$this->textSlot()]);

        $this->postJson('/api/workflows', $this->payload([
            $this->generateStep($template, ['topic' => 'x'], ['guidelines' => 'nope']),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.guidelines']);

        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $other->users()->attach($this->user->id);
        app(TenantContext::class)->set($other);
        $foreignFolder = Folder::factory()->create(['creator_id' => $this->user->id]);
        app(TenantContext::class)->set($this->workspace);

        $this->postJson('/api/workflows', $this->payload([
            $this->generateStep($template, ['topic' => 'x'], ['folder_id' => $foreignFolder->id]),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.folder_id']);
    }

    /**
     * COMPOSITE SLOTS ARE REFUSED AT AUTHORING TIME.
     *
     * The step resolves each mapped value at the SLOT's own type, and the shared resolver has no `object`
     * coercion at all — an object value (bare or `{kind:'literal'}`) resolves to NULL. So a template whose
     * recipe needs an object simply cannot be driven by a workflow, and without this rule the author got one
     * of two bad outcomes: a REQUIRED object slot saved 422-free and then hard-failed EVERY run (an orphan
     * draft each time), while a NULLABLE one silently discarded the mapped value and generated with an empty
     * slot. The same holds for the shapes no automatic filler may write at all — `array<object>` and
     * `array<file>` — which the scope policy drops as out-of-scope with the same two outcomes.
     *
     * The refusal is granular (`steps.<i>.config.slots.<name>`) so the editor highlights the exact row, and a
     * REQUIRED composite is an error whether it is mapped or not: the template is undriveable either way.
     */
    public function test_a_composite_slot_a_workflow_cannot_supply_is_a_granular_422(): void
    {
        $cases = [
            // [slot, mapping, the slot name the error must be keyed to]
            'required object, unmapped' => [$this->objectSlot(), [], 'brief'],
            'required object, mapped' => [$this->objectSlot(), ['brief' => ['title' => 'x']], 'brief'],
            'nullable object, mapped bare' => [$this->objectSlot('brief', nullable: true), ['brief' => ['title' => 'x']], 'brief'],
            'nullable object, mapped union' => [
                $this->objectSlot('brief', nullable: true),
                ['brief' => ['kind' => 'literal', 'value' => ['title' => 'x']]],
                'brief',
            ],
            'array<object>, unmapped' => [$this->objectSlot('briefs', array: true), [], 'briefs'],
            'array<object>, mapped' => [$this->objectSlot('briefs', array: true), ['briefs' => [['title' => 'x']]], 'briefs'],
            'array<file>, unmapped' => [$this->fileListSlot(), [], 'gallery'],
            'array<file>, mapped' => [$this->fileListSlot(), ['gallery' => [(string) Str::uuid()]], 'gallery'],
        ];

        foreach ($cases as $label => [$slot, $mapping, $name]) {
            $template = $this->templateWithSlots([$slot]);

            $response = $this->postJson('/api/workflows', $this->payload([$this->generateStep($template, $mapping)]));
            $response->assertUnprocessable();

            $this->assertArrayHasKey(
                'steps.0.config.slots.' . $name,
                (array) $response->json('errors'),
                $label . ': the refusal must be keyed to the slot itself',
            );
        }
    }

    /**
     * The other half of the rule, so the refusal stays a REFUSAL and not a ban: a NULLABLE composite the
     * author never maps is fine (the recipe just renders that slot empty, which is what nullable means), and
     * a plain SCALAR `file` slot — the owner-approved D4 path, which genuinely works — still saves.
     */
    public function test_an_unmapped_nullable_composite_and_a_plain_file_slot_still_save(): void
    {
        $nullableComposite = $this->templateWithSlots([$this->textSlot(), $this->objectSlot('brief', nullable: true)]);

        $this->postJson('/api/workflows', $this->payload([
            $this->generateStep($nullableComposite, ['topic' => 'launch day']),
        ]))->assertCreated();

        $withFile = $this->templateWithSlots([$this->textSlot(), $this->fileSlot()]);

        $this->postJson('/api/workflows', $this->payload([
            $this->generateStep($withFile, ['topic' => 'launch day', 'photo' => (string) Str::uuid()]),
        ]))->assertCreated();
    }

    /**
     * AN ARRAYED SLOT ACCEPTS ITS ELEMENT TERMINAL — the fix for an unavoidable dead end.
     *
     * An `array<text>` slot types as MULTI, and NO operation produces a `multi` from a scalar (only the
     * array ops do, and those need an array INPUT). Demanding MULTI therefore made every non-identity
     * pipeline over a scalar variable unreachable: the picker offered a text variable for the slot, the
     * author added `text_uppercase`, and the save 422'd with nothing the author could do about it. The empty
     * pipeline was always accepted, so the element terminal was the only thing missing — and the runtime
     * already wraps it (`VariableResolver::coerce`: MULTI wraps a scalar into a one-element list).
     *
     * The widening is EXACTLY one terminal wide: a pipeline ending in some OTHER base is still a 422.
     */
    public function test_an_arrayed_slot_accepts_a_pipeline_that_ends_in_its_element_type(): void
    {
        $template = $this->templateWithSlots([$this->arrayedTextSlot()]);
        $ref = ['source' => 'trigger', 'path' => 'trigger.fields.topic', 'type' => 'text'];

        $mapped = fn (array $pipeline) => $this->generateStep($template, [
            'tags' => ['kind' => 'variable', 'ref' => $ref, 'pipeline' => $pipeline],
        ]);

        // A text-producing pipeline over a TEXT variable: the terminal is the slot's ELEMENT type.
        $this->postJson('/api/workflows', $this->payload([$mapped([['op' => 'text_uppercase']])]))->assertCreated();

        // The identity pipeline the FE relies on keeps saving, in both shapes.
        $this->postJson('/api/workflows', $this->payload([$mapped([])]))->assertCreated();
        $this->postJson('/api/workflows', $this->payload([
            $this->generateStep($template, ['tags' => ['kind' => 'variable', 'ref' => $ref]]),
        ]))->assertCreated();

        // Any OTHER terminal is still refused — `text_length` produces a number, not a text/multi.
        $this->postJson('/api/workflows', $this->payload([$mapped([['op' => 'text_length']])]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.0.config.slots.tags.pipeline']);
    }

    /** The SCALAR half is untouched: a text slot still demands a text terminal. */
    public function test_a_scalar_slot_still_refuses_a_pipeline_that_ends_in_another_type(): void
    {
        $template = $this->templateWithSlots([$this->textSlot()]);

        $this->postJson('/api/workflows', $this->payload([
            $this->generateStep($template, ['topic' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'trigger.fields.topic', 'type' => 'text'],
                'pipeline' => [['op' => 'text_length']],
            ]]),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.0.config.slots.topic.pipeline']);
    }

    public function test_at_most_two_generate_content_steps_are_allowed(): void
    {
        $template = $this->templateWithSlots([$this->textSlot()]);
        $step = fn (string $key) => ['type' => 'generate_content', 'key' => $key, 'config' => [
            'template_id' => $template->id,
            'slots' => ['topic' => 'x'],
        ]];

        $this->postJson('/api/workflows', $this->payload([$step('a'), $step('b')]))->assertCreated();

        $this->postJson('/api/workflows', $this->payload([$step('a'), $step('b'), $step('c')]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.2.type']);
    }
}
