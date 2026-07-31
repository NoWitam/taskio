<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Models\Bot;
use App\Modules\Variables\Agents\AiTextAgent;
use App\Modules\Variables\Contracts\AuthorVoiceResolver;
use App\Modules\Variables\Enums\AiPersona;
use App\Modules\Variables\Support\AiVoiceContext;
use App\Modules\Workflows\Enums\WorkflowRunOrigin;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Jobs\WorkflowRunJob;
use App\Modules\Workflows\Jobs\WorkflowRunResumeJob;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowRunContext;
use App\Modules\Workflows\Services\WorkflowRunManager;
use App\Modules\Workflows\Services\WorkflowStepFactory;
use App\Modules\Workflows\Services\WorkflowStepRunner;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\FakesBotExecutionAgent;
use Tests\Support\FakeStepFactory;
use Tests\Support\FakeSuspendableStep;
use Tests\TestCase;
use Throwable;

/**
 * PER-BLOCK `@[ai-text]` AUTHORS in WORKFLOW STEPS. A workflow has no recipe snapshot to freeze voices
 * into — a run always executes the workflow AS IT IS NOW — so the authors named in the step configs are
 * resolved LIVE, once per pass, at the top of the run scope.
 *
 * What is pinned here:
 *   - a step config's author actually colors the text that step generates;
 *   - the TENANT boundary holds from the RUN's own workspace, with a queued run's no-op ambient scope;
 *   - a RESUMED pass resolves again (nothing about voices is persisted or replayed) — the property that
 *     makes live resolution the right choice for a run that parks;
 *   - the map is SAVED/RESTORED, not cleared: a re-triggered CHILD run executing in-process must not
 *     strip the PARENT's authors from the steps it has still to run.
 *
 * BOUNDARY: Workflows reaches authors ONLY through the Variables contract; that the module names no Bot
 * class is pinned by WorkflowsGeneratorBoundaryTest.
 */
class WorkflowStepAuthorVoiceTest extends TestCase
{
    use FakesBotExecutionAgent, RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user);
        app(TenantContext::class)->set($this->workspace);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers ---------------------------------------------------------------

    /** An `@[ai-text]("…")` directive exactly as the editor encodes it, optionally naming an AUTHOR. */
    private function aiText(string $prompt, ?string $authorId = null, string $id = 'ai_1'): string
    {
        $payload = json_encode(['v' => 1, 'data' => [
            'id' => $id,
            'personaId' => null,
            'authorId' => $authorId,
            'prompt' => $prompt,
            'labels' => [],
        ]]);

        return '@[ai-text]("' . str_replace('"', '\\"', (string) $payload) . '")';
    }

    private function bot(array $overrides = []): Bot
    {
        return Bot::factory()->create($overrides + ['creator_id' => $this->user->id, 'icon' => 'robot']);
    }

    /** Build inside $workspace as the ACTIVE shared tenant, so created rows are stamped with its id. */
    private function within(Workspace $workspace, callable $build)
    {
        $context = app(TenantContext::class);
        $previous = $context->workspace();
        $context->set($workspace);

        try {
            return $build();
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }

    /** @param array<int, array{type: string, key: string, config: array}> $steps */
    private function workflowWith(array $steps): Workflow
    {
        return Workflow::factory()->create(['creator_id' => $this->user->id, 'steps' => $steps]);
    }

    /** A create_task step whose TITLE is an ai-text block (optionally authored). */
    private function aiTitleStep(string $key, ?string $authorId, string $prompt = 'write a task title'): array
    {
        return [
            'type' => WorkflowStepType::CREATE_TASK->value,
            'key' => $key,
            'config' => ['title' => $this->aiText($prompt, $authorId)],
        ];
    }

    private function start(Workflow $workflow): WorkflowRun
    {
        return app(WorkflowRunManager::class)->start($workflow, WorkflowRunOrigin::EVENT, ['source' => 'test']);
    }

    // ---- the voice reaches the step ---------------------------------------------

    public function test_a_step_configs_author_colors_the_text_that_step_generates(): void
    {
        $bot = $this->bot(['persona' => 'THE STEP AUTHOR PERSONA']);

        AiTextAgent::fake(fn () => 'AI TITLE');

        $run = $this->start($this->workflowWith([$this->aiTitleStep('make_task', $bot->id)]))->fresh();

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertDatabaseHas('tasks', ['title' => 'AI TITLE']);

        AiTextAgent::assertPrompted(
            fn ($prompt): bool => str_contains((string) $prompt->agent->instructions(), 'THE STEP AUTHOR PERSONA'),
        );
    }

    /**
     * THE isolation pin. A run executes with no active workspace on a real worker, so only the run's OWN
     * `workspace_id`, passed explicitly, stands between a step config and a foreign bot's authored voice.
     * The step still generates — a refused author costs the TONE, never the text (fail-SAFE).
     */
    public function test_a_foreign_workspace_bot_never_lends_its_voice_to_a_step(): void
    {
        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $foreign = $this->within($other, fn () => $this->bot(['persona' => 'FOREIGN PERSONA']));

        AiTextAgent::fake(fn () => 'AI TITLE');

        $run = $this->start($this->workflowWith([$this->aiTitleStep('make_task', $foreign->id)]))->fresh();

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertDatabaseHas('tasks', ['title' => 'AI TITLE']);

        AiTextAgent::assertPrompted(function ($prompt): bool {
            $instructions = (string) $prompt->agent->instructions();

            return !str_contains($instructions, 'FOREIGN PERSONA')
                && str_contains($instructions, AiPersona::NEUTRAL->styleInstruction());
        });
    }

    /** A step naming NO author is untouched: the persona line, exactly as before the feature. */
    public function test_a_step_without_an_author_is_unchanged(): void
    {
        AiTextAgent::fake(fn () => 'AI TITLE');

        $this->start($this->workflowWith([$this->aiTitleStep('make_task', null)]));

        AiTextAgent::assertPrompted(
            fn ($prompt): bool => str_contains((string) $prompt->agent->instructions(), AiPersona::NEUTRAL->styleInstruction()),
        );
    }

    // ---- suspend / resume --------------------------------------------------------

    /**
     * A run may park and come back in a DIFFERENT process, days later. Because voices are resolved at the
     * top of every pass, the resumed pass rebuilds them from the database like everything else it rebuilds
     * — nothing had to be persisted into `waiting_on`, and a bot edited during the wait simply applies from
     * the resume onward (the same deliberate semantic constants and the type map already have).
     */
    public function test_a_resumed_pass_resolves_the_step_author_voice_again(): void
    {
        $fake = new FakeSuspendableStep;
        $this->app->instance(WorkflowStepFactory::class, new FakeStepFactory($fake));

        $bot = $this->bot(['persona' => 'THE AFTER-THE-WAIT PERSONA']);

        AiTextAgent::fake(fn () => 'AI TITLE');

        $workflow = $this->workflowWith([
            ['type' => FakeSuspendableStep::TYPE, 'key' => 'wait', 'config' => ['echo' => 'hello']],
            $this->aiTitleStep('after', $bot->id),
        ]);

        $run = $this->start($workflow)->fresh();
        $this->assertSame(WorkflowRunState::WAITING, $run->state);

        // Nothing about voices rides in the wait record — there is nothing to replay.
        $this->assertArrayNotHasKey('author_voices', $run->waiting_on);

        (new WorkflowRunResumeJob($run->id, ''))->handle(
            app(WorkflowRunManager::class),
            app(WorkflowStepRunner::class),
        );

        $this->assertSame(WorkflowRunState::COMPLETED, $run->fresh()->state);
        $this->assertDatabaseHas('tasks', ['title' => 'AI TITLE']);

        AiTextAgent::assertPrompted(
            fn ($prompt): bool => str_contains((string) $prompt->agent->instructions(), 'THE AFTER-THE-WAIT PERSONA'),
        );
    }

    // ---- re-entrancy: a nested child run --------------------------------------------

    /**
     * SAVE/RESTORE, not set/clear — the same defect class the queue escape hatch and the ai-text budget
     * already closed here. Under the sync override a re-triggered CHILD run executes IN-PROCESS inside a
     * step, so the runner can be nested inside itself. An unconditional clear() in the finally would strip
     * the PARENT's author voices, and every step the parent had still to run would silently lose its voice.
     *
     * Probe without the restore: the parent's post-nesting step generates with NO author voice at all.
     */
    public function test_a_nested_child_run_leaves_the_parents_author_voices_intact(): void
    {
        $fake = new FakeSuspendableStep(suspendOnRun: false);
        $this->app->instance(WorkflowStepFactory::class, new FakeStepFactory($fake));

        $parentBot = $this->bot(['persona' => 'PARENT PERSONA']);
        $childBot = $this->bot(['persona' => 'CHILD PERSONA']);

        $child = $this->workflowWith([$this->aiTitleStep('child', $childBot->id, 'child title')]);
        $childRun = WorkflowRun::factory()->pending()->create(['workflow_id' => $child->id]);

        $parent = $this->workflowWith([
            ['type' => FakeSuspendableStep::TYPE, 'key' => 'nest', 'config' => []],
            $this->aiTitleStep('after_nesting', $parentBot->id, 'parent title'),
        ]);
        $parentRun = WorkflowRun::factory()->pending()->create(['workflow_id' => $parent->id]);

        // Record the AMBIENT author map at every ai-text call, in order: the child's own, then the
        // parent's — which proves the parent got its map BACK after the nested run returned.
        $seen = [];
        AiTextAgent::fake(function () use (&$seen) {
            $seen[] = app(AiVoiceContext::class)->authorVoices();

            return 'AI TITLE';
        });

        // The parent's first step triggers the child run inline (what the sync override makes of any
        // re-trigger), and only THEN does the parent go on to its own authored step.
        $fake->beforeRun = function () use ($childRun): void {
            (new WorkflowRunJob($childRun->id))->handle(app(WorkflowRunManager::class), app(WorkflowStepRunner::class));
        };

        (new WorkflowRunJob($parentRun->id))->handle(app(WorkflowRunManager::class), app(WorkflowStepRunner::class));

        $this->assertSame(WorkflowRunState::COMPLETED, $childRun->fresh()->state);
        $this->assertSame(WorkflowRunState::COMPLETED, $parentRun->fresh()->state);

        $this->assertCount(2, $seen, 'both the child and the parent generated a title');
        $this->assertSame([$childBot->id], array_keys($seen[0]), 'the child run sees only its OWN authors');
        $this->assertSame([$parentBot->id], array_keys($seen[1]), 'the parent must get its map back after nesting');

        // And the outermost frame leaves nothing published behind it.
        $this->assertSame([], app(AiVoiceContext::class)->authorVoices());
    }

    // ---- a broken lookup must not escape the run scope ----------------------------

    /**
     * THE process-leak pin. The voice lookup is the ONLY database read in the run prologue, and everything
     * the run scope publishes — the run context (which stamps HasCreator, ADR-0015), the ai-text budget, the
     * author map — is unwound in ONE `finally`. A throwable escaping the lookup from OUTSIDE that `try` would
     * skip the whole unwind and leave a DEAD run published PROCESS-WIDE, so every later job the worker picks
     * up would stamp its rows with it and (through MeterActorResolver) charge it for every later AI spend.
     *
     * The contract says voicesFor() NEVER throws, so a violation must cost the pass its TONE and nothing
     * else: the run still completes, and the process is left clean.
     */
    public function test_a_throwing_author_lookup_neither_kills_the_run_nor_leaks_it_process_wide(): void
    {
        $this->app->instance(AuthorVoiceResolver::class, new class implements AuthorVoiceResolver
        {
            public function voicesFor(array $authorIds, ?string $workspaceId): array
            {
                // What a severed connection / deadlock / missing tenant table actually looks like here.
                throw new RuntimeException('author voice lookup exploded');
            }
        });

        $bot = $this->bot(['persona' => 'THE STEP AUTHOR PERSONA']);

        AiTextAgent::fake(fn () => 'AI TITLE');

        $thrown = null;
        $run = null;

        try {
            $run = $this->start($this->workflowWith([$this->aiTitleStep('make_task', $bot->id)]));
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertNull(
            app(WorkflowRunContext::class)->current(),
            'a failed voice lookup must never leave the run published for every later job in the worker',
        );

        $this->assertNull($thrown, 'the lookup is fail-SAFE: it may cost the TONE, never the run');
        $this->assertSame(WorkflowRunState::COMPLETED, $run?->fresh()->state);
        $this->assertDatabaseHas('tasks', ['title' => 'AI TITLE']);

        // And the pass fell back to the persona line, i.e. it lost exactly the tone and nothing else.
        AiTextAgent::assertPrompted(function ($prompt): bool {
            $instructions = (string) $prompt->agent->instructions();

            return !str_contains($instructions, 'THE STEP AUTHOR PERSONA')
                && str_contains($instructions, AiPersona::NEUTRAL->styleInstruction());
        });
    }

    /** The restore is unconditional: an OUTER map published by something else survives a whole run. */
    public function test_a_run_restores_whatever_author_map_was_published_around_it(): void
    {
        AiTextAgent::fake(fn () => 'AI TITLE');

        $voice = app(AiVoiceContext::class);
        $voice->setAuthorVoices(['outer-author' => 'AN OUTER VOICE']);

        try {
            $this->start($this->workflowWith([$this->aiTitleStep('make_task', null)]));

            $this->assertSame(['outer-author' => 'AN OUTER VOICE'], $voice->authorVoices());
        } finally {
            $voice->clear();
        }
    }
}
