<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Services\BotDelegationIdentityComposer;
use App\Modules\Disk\Services\FileService;
use App\Modules\Generator\Contracts\SessionAuthorIdentityResolver;
use App\Modules\Generator\Jobs\RunGenerationSessionJob;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Models\Template;
use App\Modules\Generator\Services\GenerationSessionRunManager;
use App\Modules\Generator\Services\SessionIdentityImageStore;
use App\Modules\Variables\Agents\AiTextAgent;
use App\Modules\Variables\Models\AiUsageEvent;
use App\Modules\Variables\Support\AiVoiceContext;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Enums\WorkflowRunStepStatus;
use App\Modules\Workflows\Jobs\WorkflowRunJob;
use App\Modules\Workflows\Jobs\WorkflowRunResumeJob;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowRunManager;
use App\Modules\Workflows\Services\WorkflowStepRunner;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * A BOT IN A WORKFLOW STEP: `generate_content` takes an optional `bot_id`, and the session it creates is
 * DELEGATED to that bot — exactly as a human delegation would, so an automated post gets the same VOICE in
 * its text and the same FACE in its images.
 *
 * THE EDGE IS INVERTED, and that is the whole design. Workflows may not name the module that owns bots
 * (peer modules meet only through a lower layer), so the step orders an identity through the Generator's
 * {@see SessionAuthorIdentityResolver} contract and never learns what an author is. What is pinned here is
 * the BEHAVIOUR that seam has to produce; the wiring itself (bindIf/bind, order-independence, the
 * no-Bot-in-Workflows scan) is pinned by BotModuleBoundaryTest + WorkflowsGeneratorBoundaryTest.
 *
 * NO PROVIDER IS EVER CONTACTED: the one AI seam a `post` recipe uses is `@[ai-text]`, scripted via
 * `AiTextAgent::fake()`, and the creative-direction layer is off (an un-scripted derivation would be a real
 * call). The queue shape mirrors {@see WorkflowGenerateContentStepTest}: a non-sync ambient connection with
 * each job invoked BY HAND, in the order a real deployment would.
 */
class WorkflowGenerateContentBotTest extends TestCase
{
    use RefreshDatabase;

    /** The non-sync connection the worker pretends to run on. */
    private const REAL_CONNECTION = 'database';

    /** A voice fragment nothing else in the fixture could produce — so "did THIS bot speak?" is decidable. */
    private const PERSONA = 'ZAWSZE MOWISZ JAK PIRAT Z LUBLINA';

    /** The approved likeness's bytes — distinctive, so "were THESE bytes frozen?" is decidable. */
    private const LIKENESS = 'LIKENESS-BYTES-PNG';

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

    /** An `@[ai-text]("…")` directive exactly as the editor encodes it. */
    private function aiTextDirective(string $prompt): string
    {
        $payload = json_encode(['v' => 1, 'data' => ['id' => 'ai_1', 'personaId' => null, 'prompt' => $prompt, 'labels' => []]]);

        return '@[ai-text]("' . str_replace('"', '\\"', (string) $payload) . '")';
    }

    /** A `post` template whose body makes one SCRIPTED `@[ai-text]` call, so the text is provably generated. */
    private function template(): Template
    {
        return Template::factory()
            ->content(['body' => ['markdown' => 'Post about ' . \Database\Factories\TemplateFactory::directive('slots.topic') . ' — ' . $this->aiTextDirective('Describe the topic')]])
            ->create(['creator_id' => $this->user->id]);
    }

    /**
     * A bot with a distinctive VOICE, and optionally a configured visual module with an APPROVED likeness
     * whose bytes are {@see LIKENESS} — the two things a delegated session is supposed to inherit.
     *
     * $status is left to the FACTORY's default unless a test names one, and a test that cares about status
     * must name it: an author's status is deliberately not a filter anywhere on this path, so "it happened to
     * be inactive" is not coverage of that (see test_an_inactive_author_delegates_exactly_like_an_active_one).
     */
    private function bot(bool $withLikeness = false, bool $visualEnabled = true, ?string $status = null): Bot
    {
        $factory = Bot::factory();

        if ($withLikeness || $visualEnabled) {
            $factory = $factory->withVisual([], $visualEnabled);
        }

        $factory = match ($status) {
            'active' => $factory->active(),
            'inactive' => $factory->inactive(),
            default => $factory,
        };

        $bot = $factory->create([
            'creator_id' => $this->user->id,
            'name' => 'Kapitan Treść',
            'icon' => 'robot',
            'persona' => self::PERSONA,
        ]);

        if (!$withLikeness) {
            return $bot;
        }

        $file = app(FileService::class)->storeContent(self::LIKENESS, 'likeness.png', 'image/png', $bot);

        $bot->update(['visual' => array_merge((array) $bot->visualIdentity(), [
            'candidates' => [$file->id],
            'canonical_file_id' => $file->id,
        ])]);

        return $bot->fresh();
    }

    /** The step definition, optionally naming an author. */
    private function generateStep(Template $template, ?string $botId = null): array
    {
        return ['type' => 'generate_content', 'key' => 'gen', 'config' => array_filter([
            'template_id' => $template->id,
            'slots' => ['topic' => 'launch day'],
            'bot_id' => $botId,
        ], fn ($value) => $value !== null)];
    }

    private function workflowWith(array $steps): Workflow
    {
        return Workflow::factory()->create([
            'creator_id' => $this->user->id,
            'workspace_id' => $this->workspace->id,
            'steps' => $steps,
        ]);
    }

    /** Start a pending run and execute its FIRST pass on a non-sync ambient connection. */
    private function runFirstPass(Workflow $workflow): WorkflowRun
    {
        $run = WorkflowRun::factory()->pending()->create([
            'workflow_id' => $workflow->id,
            'creator_id' => $this->user->id,
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

    private function resume(WorkflowRun $run, ?string $key = null): void
    {
        (new WorkflowRunResumeJob($run->id, $this->workspace->id, $key ?? (string) $run->waiting_key))->handle(
            app(WorkflowRunManager::class),
            app(WorkflowStepRunner::class),
        );
    }

    /** The session a PARKED run is waiting on — read from the wait record, never guessed by recency. */
    private function sessionOf(WorkflowRun $run): GenerationSession
    {
        $this->assertIsArray($run->waiting_on, 'the run must be parked to have a session');

        return GenerationSession::findOrFail($run->waiting_on['payload']['session_id']);
    }

    /** Build inside $workspace as the ACTIVE shared tenant, so created rows are stamped with its id. */
    private function within(Workspace $workspace, Closure $build): mixed
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

    private function otherWorkspace(): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $workspace->users()->attach($this->user->id);

        return $workspace;
    }

    /**
     * Install a COUNTING decorator over the real identity seam and hand back the recorder. Used to pin the
     * two things no state can show: that the seam is not touched at all when no author is named, and that a
     * RESUME never asks again.
     *
     * The two halves are recorded SEPARATELY on purpose. `calls` is the expensive question (compose this
     * author — the one a run asks) and `probes` is the cheap write-side existence check; folding them into
     * one counter would let a stray composition hide behind a legitimate save-time probe.
     */
    private function spyOnIdentityResolver(): object
    {
        $recorder = new class(app(SessionAuthorIdentityResolver::class)) implements SessionAuthorIdentityResolver
        {
            /** @var array<int, array{0: string, 1: ?string}> identity COMPOSITIONS ordered */
            public array $calls = [];

            /** @var array<int, array{0: string, 1: ?string}> write-side EXISTENCE probes */
            public array $probes = [];

            public function __construct(private SessionAuthorIdentityResolver $inner) {}

            public function identityFor(string $botId, ?string $workspaceId): ?array
            {
                $this->calls[] = [$botId, $workspaceId];

                return $this->inner->identityFor($botId, $workspaceId);
            }

            public function knowsAuthor(string $botId, ?string $workspaceId): bool
            {
                $this->probes[] = [$botId, $workspaceId];

                return $this->inner->knowsAuthor($botId, $workspaceId);
            }
        };

        $this->app->instance(SessionAuthorIdentityResolver::class, $recorder);

        return $recorder;
    }

    /**
     * Break the shared identity COMPOSITION the way infrastructure breaks it — while assembling an author
     * (the step that reads the likeness blob out of Storage). Everything ABOVE the composer stays real, so
     * what a test then observes is genuinely "the identity could not be composed", not a stubbed verdict.
     */
    private function breakIdentityComposition(): void
    {
        $this->app->bind(BotDelegationIdentityComposer::class, fn () => new class extends BotDelegationIdentityComposer
        {
            public function __construct() {}

            public function compose(Bot $bot): array
            {
                throw new RuntimeException('the likeness blob could not be read');
            }
        });

        // The seam is a plain bind (a fresh instance per resolve), but a test may already hold one.
        $this->app->forgetInstance(SessionAuthorIdentityResolver::class);
    }

    /** The workflow-create payload wrapper (author-time cases). */
    private function payload(array $steps): array
    {
        return [
            'name' => 'Generate a post',
            'trigger_type' => 'form_submitted',
            'trigger_config' => ['form_id' => null],
            'steps' => $steps,
        ];
    }

    // ---- 1. the delegated step ---------------------------------------------------

    /**
     * THE FEATURE. A step naming a bot produces a session that is delegated to it — the author snapshot, the
     * frozen voice AND the frozen likeness — and the text that session generates is actually written in that
     * voice.
     *
     * The likeness is asserted through the identity STORE rather than the overlay alone, because
     * `has_character_image: true` with no bytes behind it is precisely the failure that would render a
     * faceless run while claiming a face.
     */
    public function test_a_step_with_a_bot_delegates_the_session_and_generates_in_its_voice(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $bot = $this->bot(withLikeness: true);
        $run = $this->runFirstPass($this->workflowWith([$this->generateStep($this->template(), $bot->id)]));

        $session = $this->sessionOf($run);

        // ---- the overlay --------------------------------------------------------
        $this->assertTrue($session->isDelegated());
        $this->assertSame($bot->id, $session->bot_author_id);
        $this->assertSame(
            ['id' => $bot->id, 'name' => 'Kapitan Treść', 'icon' => 'robot'],
            $session->bot_delegation['author'],
        );
        $this->assertStringContainsString(self::PERSONA, (string) $session->botVoice());

        // The human OWNER of the run's records is untouched — delegation is an author overlay, not a
        // transfer of ownership (the same posture the interactive delegation has).
        $this->assertSame('workflow_run', $session->creator_type);
        $this->assertSame($run->id, $session->creator_id);

        // ---- the frozen look ----------------------------------------------------
        $visual = $session->bot_delegation['visual'];

        $this->assertTrue($visual['enabled']);
        $this->assertTrue($visual['has_character_image']);
        $this->assertSame('A cheerful red-haired illustrator in her late twenties.', $visual['descriptor']);
        $this->assertSame($bot->visualIdentity()['canonical_file_id'], $visual['source_file_id']);
        $this->assertSame(self::LIKENESS, app(SessionIdentityImageStore::class)->forSession($session));

        // ---- the voice reaches the GENERATION worker ----------------------------
        // A separate process with no run context and no auth: the only thing carrying the voice there is
        // the session's own snapshot.
        $seen = [];
        AiTextAgent::fake(function () use (&$seen) {
            $seen[] = app(AiVoiceContext::class)->directive();

            return 'AI OUT';
        });

        $this->runGenerationWorker($session);

        $this->assertNotSame([], $seen, 'the recipe must have made its scripted ai-text call');
        $this->assertStringContainsString(self::PERSONA, (string) $seen[0], 'the generation must render in the bot voice');

        // …and the run still completes normally end to end.
        $this->resume($run);
        $this->assertSame(WorkflowRunState::COMPLETED, $run->fresh()->state);
    }

    // ---- 2. the untouched default ------------------------------------------------

    /**
     * BYTE-FOR-BYTE THE OLD PATH when no author is named: not merely "undelegated", but the seam NEVER
     * ASKED. A step that quietly resolved a null author would be invisible in the result and would still
     * cost a query on every run of every existing workflow.
     */
    public function test_a_step_without_a_bot_never_asks_for_an_identity_and_stays_undelegated(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $spy = $this->spyOnIdentityResolver();

        $run = $this->runFirstPass($this->workflowWith([$this->generateStep($this->template())]));
        $session = $this->sessionOf($run);

        $this->assertSame([], $spy->calls, 'no author named ⇒ the seam must not be consulted at all');
        $this->assertFalse($session->isDelegated());
        $this->assertNull($session->bot_author_id);
        $this->assertNull($session->bot_delegation);
        $this->assertNull(app(SessionIdentityImageStore::class)->forSession($session));

        $this->runGenerationWorker($session);
        $this->resume($run);

        $this->assertSame(WorkflowRunState::COMPLETED, $run->fresh()->state);
    }

    // ---- 3. the author disappeared -----------------------------------------------

    /**
     * A bot DELETED between saving the workflow and running it is a TERMINAL step failure, not a silent
     * downgrade to an anonymous post. The run must not hang either: it fails, records the step, and is not
     * left parked on a session nobody will ever settle.
     *
     * The refusal also happens BEFORE the session is created, so a workflow whose author is gone does not
     * mint a fresh orphan draft on every trigger.
     */
    public function test_a_deleted_bot_fails_the_step_terminally_without_creating_a_session(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $bot = $this->bot();
        $workflow = $this->workflowWith([$this->generateStep($this->template(), $bot->id)]);

        $bot->delete();

        $run = $this->runFirstPass($workflow);

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertSame(__('workflows.steps.generate_content.bot_unavailable'), $run->error);
        $this->assertNull($run->waiting_key, 'the run must not be left parked');
        $this->assertSame(WorkflowRunStepStatus::FAILED, $run->steps()->first()->status);

        $this->assertSame(0, GenerationSession::count(), 'the refusal must come BEFORE the draft session');
        $this->assertSame(0, AiUsageEvent::count(), 'nothing may be billed for a refused run');
        Queue::assertNotPushed(RunGenerationSessionJob::class);
    }

    /**
     * A `bot_id` that is PRESENT but unusable — a number, a list, an object — is an unresolvable author,
     * NOT "no author". Only an absent / null / cleared field means anonymous.
     *
     * The write-side validator refuses all of these, so this is reachable only through a hand-written or
     * imported definition — which is precisely the case where degrading silently would be worst: the
     * definition demonstrably MEANT to name an author, and the run would otherwise publish an anonymous
     * post while looking entirely successful.
     */
    public function test_a_present_but_unusable_bot_id_is_refused_rather_than_silently_anonymous(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        foreach ([12345, ['id' => 'x'], true] as $index => $garbage) {
            $run = $this->runFirstPass($this->workflowWith([
                ['type' => 'generate_content', 'key' => 'gen', 'config' => [
                    'template_id' => $this->template()->id,
                    'slots' => ['topic' => 'launch day'],
                    'bot_id' => $garbage,
                ]],
            ]));

            $this->assertSame(WorkflowRunState::FAILED, $run->state, 'case ' . $index);
            $this->assertSame(__('workflows.steps.generate_content.bot_unavailable'), $run->error, 'case ' . $index);
        }

        $this->assertSame(0, GenerationSession::count());

        // …while the three spellings of "no author" still generate anonymously, as they always have.
        foreach ([null, '', '   '] as $index => $absent) {
            $run = $this->runFirstPass($this->workflowWith([
                ['type' => 'generate_content', 'key' => 'gen', 'config' => [
                    'template_id' => $this->template()->id,
                    'slots' => ['topic' => 'launch day'],
                    'bot_id' => $absent,
                ]],
            ]));

            $this->assertSame(WorkflowRunState::WAITING, $run->state, 'case ' . $index);
            $this->assertFalse($this->sessionOf($run)->isDelegated(), 'case ' . $index);
        }
    }

    // ---- 4. a foreign author ------------------------------------------------------

    /** A bot from another workspace is refused at AUTHORING time, keyed to the exact field. */
    public function test_a_foreign_or_unknown_bot_is_a_granular_422(): void
    {
        $template = $this->template();
        $foreign = $this->within($this->otherWorkspace(), fn () => $this->bot());

        foreach ([$foreign->id, (string) Str::uuid(), 'not-a-uuid'] as $id) {
            $this->postJson('/api/workflows', $this->payload([$this->generateStep($template, $id)]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['steps.0.config.bot_id']);
        }

        // The happy half, so the rule stays a refusal and not a ban: an own-workspace bot saves, and so does
        // a step that names no author at all.
        $this->postJson('/api/workflows', $this->payload([$this->generateStep($template, $this->bot()->id)]))
            ->assertCreated();

        $this->postJson('/api/workflows', $this->payload([$this->generateStep($template)]))
            ->assertCreated();
    }

    /**
     * …and the write-side check is NOT the security boundary on its own. A definition carrying a foreign id
     * anyway (hand-written, imported, or a bot moved after the fact) must still be refused at RUN time —
     * the run-time lookup is workspace-pinned, so the identity simply never resolves.
     */
    public function test_a_foreign_bot_smuggled_into_a_stored_definition_still_fails_the_run(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $foreign = $this->within($this->otherWorkspace(), fn () => $this->bot());

        $run = $this->runFirstPass($this->workflowWith([$this->generateStep($this->template(), $foreign->id)]));

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertSame(__('workflows.steps.generate_content.bot_unavailable'), $run->error);
        $this->assertSame(0, GenerationSession::count());
    }

    // ---- 5. queue tenancy ---------------------------------------------------------

    /**
     * THE QUEUE TENANCY PIN. A run executes where {@see \App\Models\Scopes\WorkspaceScope} is a documented
     * NO-OP, so the workspace must travel EXPLICITLY from the run's own row — an ambient-only lookup would
     * run unconstrained and could lend a FOREIGN bot's voice and face to a workspace's published content.
     *
     * Both halves: the step passes the run's id, and the seam REFUSES rather than widens when it is handed
     * none outside own-database mode.
     */
    public function test_the_identity_is_resolved_with_the_runs_own_workspace_and_a_missing_one_is_refused(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $bot = $this->bot();
        $spy = $this->spyOnIdentityResolver();

        $run = $this->runFirstPass($this->workflowWith([$this->generateStep($this->template(), $bot->id)]));

        $this->assertSame([[$bot->id, $this->workspace->id]], $spy->calls);
        $this->assertTrue($this->sessionOf($run)->isDelegated());

        // The queue posture, exercised directly on the REAL seam (the spy is dropped so the concrete comes
        // back): NO ambient workspace at all.
        $this->app->forgetInstance(SessionAuthorIdentityResolver::class);
        $resolver = app(SessionAuthorIdentityResolver::class);

        app(TenantContext::class)->clear();

        $this->assertNotNull($resolver->identityFor($bot->id, $this->workspace->id), 'an explicit id resolves without an ambient tenant');
        $this->assertNull($resolver->identityFor($bot->id, null), 'shared mode + no workspace ⇒ REFUSED, never widened');
        $this->assertNull($resolver->identityFor($bot->id, (string) Str::uuid()), 'a foreign workspace never resolves');
        $this->assertNull($resolver->identityFor('not-a-uuid', $this->workspace->id), 'a malformed id is dropped before the query');

        // An ACTIVE shared workspace is NOT a substitute for the explicit pin — the ambient scope is a
        // documented no-op the moment the same code runs on a worker, so relying on it would be a boundary
        // that silently disappears exactly where it matters.
        app(TenantContext::class)->set($this->workspace);

        $this->assertNull($resolver->identityFor($bot->id, null), 'an ambient workspace must not stand in for the explicit id');
    }

    // ---- 6. attribution -----------------------------------------------------------

    /**
     * ATTRIBUTION FOLLOWS THE AUTHOR, exactly as it does for an interactive delegation: the spend of a
     * session a bot authored is billed to the BOT, not to the run that started it. Without this the
     * per-actor AI-usage view would attribute every automated bot post to "the workflow", and the cost of a
     * loud bot would be invisible.
     *
     * The undelegated half is asserted in the same test so the two cannot drift into agreeing.
     */
    public function test_the_spend_of_a_delegated_step_session_is_attributed_to_the_bot(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $bot = $this->bot();
        $run = $this->runFirstPass($this->workflowWith([$this->generateStep($this->template(), $bot->id)]));
        $session = $this->sessionOf($run);

        $this->runGenerationWorker($session);

        $events = AiUsageEvent::all();
        $this->assertGreaterThan(0, $events->count(), 'the scripted ai-text call must be metered');

        foreach ($events as $event) {
            $this->assertSame('bot', $event->actor_type);
            $this->assertSame($bot->id, $event->actor_id);
            $this->assertSame($session->id, $event->session_id);
            $this->assertSame($this->workspace->id, $event->workspace_id);
        }

        // The SAME workflow without an author bills the run — the contrast that makes the line above mean
        // something.
        AiUsageEvent::query()->delete();

        $plain = $this->runFirstPass($this->workflowWith([$this->generateStep($this->template())]));
        $this->runGenerationWorker($this->sessionOf($plain));

        $this->assertSame(['workflow_run'], AiUsageEvent::query()->pluck('actor_type')->unique()->all());
    }

    // ---- 7. suspend / resume ------------------------------------------------------

    /**
     * A RESUME COLLECTS; IT DOES NOT RE-AUTHOR. The config replayed at resume still carries `bot_id`, and
     * acting on it would re-open every drift the delegation snapshot exists to close — re-reading a bot that
     * may have changed while the run waited, re-freezing bytes over a session that already rendered, and
     * re-snapshotting `slot_values_before` over the FILLED values so an undo would restore the automation's
     * own fills instead of the empty pre-fill state.
     *
     * Pinned two ways: the seam is not called at all on the resume path, and the stored overlay is
     * byte-identical afterwards. A duplicate delivery of the same resume stays a no-op, as before.
     */
    public function test_a_resume_never_re_resolves_the_author_and_a_duplicate_delivery_is_a_no_op(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        $bot = $this->bot(withLikeness: true);
        $spy = $this->spyOnIdentityResolver();

        $run = $this->runFirstPass($this->workflowWith([$this->generateStep($this->template(), $bot->id)]));

        $session = $this->sessionOf($run);
        $waitingKey = (string) $run->waiting_key;
        $overlayAfterCreate = $session->bot_delegation;

        $this->assertCount(1, $spy->calls, 'the author is resolved exactly ONCE, at create time');

        // The bot is edited WHILE THE RUN WAITS — the classic drift a re-resolve would let in.
        $bot->update(['persona' => 'CAŁKOWICIE INNA PERSONA']);

        $this->runGenerationWorker($session);
        $this->resume($run->fresh());
        $run->refresh();

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertCount(1, $spy->calls, 'the RESUME must not consult the author seam again');
        $this->assertSame($overlayAfterCreate, $session->fresh()->bot_delegation, 'the overlay is frozen at create time');
        $this->assertStringContainsString(self::PERSONA, (string) $session->fresh()->botVoice());

        // A redelivery of the same resume: the run is already settled, so nothing runs twice.
        $stepCount = $run->steps()->count();
        $this->resume($run, $waitingKey);
        $run->refresh();

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertSame($stepCount, $run->steps()->count());
        $this->assertSame(1, GenerationSession::count(), 'no second session — no duplicated work');
        $this->assertCount(1, $spy->calls);
    }

    // ---- 8. the WRITE path asks a different question ------------------------------

    /**
     * THE SAVE MUST NOT LIE. Validating a definition asks only whether the named author EXISTS in this
     * workspace; it must never depend on COMPOSING that author's identity, because composition reads the
     * likeness bytes out of Storage and a storage/database hiccup would then be dressed up as "this bot is
     * not available in this workspace" — an author who cannot save their workflow and is told something
     * that is simply untrue.
     *
     * Probed the way infrastructure actually fails: the shared composition throws while reading the blob.
     * The save must be entirely unaffected, because the write path never asks for a composition at all —
     * pinned positively too (one existence PROBE, zero compositions), so the guarantee survives a future
     * change that merely stops this particular failure from being reachable.
     */
    public function test_an_unreadable_author_identity_never_turns_a_save_into_a_rejected_author(): void
    {
        $template = $this->template();
        $bot = $this->bot(withLikeness: true);

        // Order matters: breaking the composition drops any resolver instance, so the spy is installed after.
        $this->breakIdentityComposition();
        $spy = $this->spyOnIdentityResolver();

        $this->postJson('/api/workflows', $this->payload([$this->generateStep($template, $bot->id)]))
            ->assertCreated();

        $this->assertSame([[$bot->id, $this->workspace->id]], $spy->probes, 'a save asks only whether the author exists');
        $this->assertSame([], $spy->calls, 'a save must never order an identity COMPOSITION');
    }

    /**
     * …and the refusal it DOES make still reads exactly as before: an author this workspace does not know is
     * a granular 422 keyed to the field, with the same localized prose. Pinned by MESSAGE, not just by key,
     * because the whole point of the split above is that this sentence is now only ever said when it is TRUE.
     */
    public function test_an_unknown_author_is_still_refused_with_the_same_prose(): void
    {
        $this->postJson('/api/workflows', $this->payload([
            $this->generateStep($this->template(), (string) Str::uuid()),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'steps.0.config.bot_id' => __('workflows.steps.generate_content.bot_invalid'),
            ]);
    }

    /**
     * THE PROBE'S OWN CONTRACT, exercised directly on the seam. `knowsAuthor` exists so the write path can
     * ask "does this author exist here?" without paying for — or being able to be lied to by — a full
     * composition, so the two halves pinned here are:
     *   (a) it answers TRUE while composition is broken (cheap: no Storage, no identity assembly), and
     *   (b) it applies the IDENTICAL workspace posture `identityFor` does — a foreign workspace, a missing
     *       workspace outside own-database mode, a malformed id and a deleted author are all FALSE, so the
     *       save can never accept an author the run would then refuse.
     */
    public function test_the_write_probe_answers_existence_without_composing_an_identity(): void
    {
        $bot = $this->bot(withLikeness: true);

        $this->breakIdentityComposition();

        $resolver = app(SessionAuthorIdentityResolver::class);

        $this->assertTrue($resolver->knowsAuthor($bot->id, $this->workspace->id), 'the probe must not depend on composition');
        $this->assertNull($resolver->identityFor($bot->id, $this->workspace->id), '…while the RUN-time answer stays fail-closed');

        // The same refusal-not-widen posture as identityFor, asserted with no ambient tenant (the queue shape).
        app(TenantContext::class)->clear();

        $this->assertTrue($resolver->knowsAuthor($bot->id, $this->workspace->id), 'an explicit id resolves without an ambient tenant');
        $this->assertFalse($resolver->knowsAuthor($bot->id, null), 'shared mode + no workspace ⇒ REFUSED, never widened');
        $this->assertFalse($resolver->knowsAuthor($bot->id, (string) Str::uuid()), 'a foreign workspace never resolves');
        $this->assertFalse($resolver->knowsAuthor('not-a-uuid', $this->workspace->id), 'a malformed id is dropped before the query');

        app(TenantContext::class)->set($this->workspace);
        $bot->delete();

        $this->assertFalse($resolver->knowsAuthor($bot->id, $this->workspace->id), 'a deleted author is not known');
    }

    // ---- 9. status is not a filter -------------------------------------------------

    /**
     * AN AUTHOR IS CONFIGURATION, NOT A CAPABILITY: pausing a bot must not silently change (or stop) the
     * content a workflow was told to publish in its name. Both statuses are named EXPLICITLY here — every
     * other fixture in this file happens to be inactive only because that is the factory's default, so a
     * future default flip would retire this coverage without a single test turning red.
     */
    public function test_an_inactive_author_delegates_exactly_like_an_active_one(): void
    {
        Queue::fake([RunGenerationSessionJob::class, WorkflowRunResumeJob::class]);

        foreach (['inactive', 'active'] as $status) {
            $bot = $this->bot(status: $status);

            // The definition SAVES, whatever the status — status is not a write-side filter either.
            $this->postJson('/api/workflows', $this->payload([$this->generateStep($this->template(), $bot->id)]))
                ->assertCreated();

            $run = $this->runFirstPass($this->workflowWith([$this->generateStep($this->template(), $bot->id)]));
            $session = $this->sessionOf($run);

            $this->assertTrue($session->isDelegated(), $status);
            $this->assertSame($bot->id, $session->bot_author_id, $status);
            $this->assertStringContainsString(self::PERSONA, (string) $session->botVoice(), $status);

            $this->runGenerationWorker($session);
            $this->resume($run->fresh());

            $this->assertSame(WorkflowRunState::COMPLETED, $run->fresh()->state, $status);
        }
    }

    // ---- 10. the author survives the editor round trip -----------------------------

    /**
     * THE SEAM NOTHING ELSE PINS. `bot_id` is written by the create request, persisted inside the free-form
     * step `config`, read back out through {@see \App\Modules\Workflows\Http\Resources\WorkflowResource}, and
     * submitted again by an editor that seeds itself from exactly that read. A drop anywhere along that loop
     * is invisible: the workflow keeps running, it just quietly stops being authored — the same silent
     * de-authoring the whole feature exists to prevent.
     *
     * So the loop is walked for real (POST → SHOW → PUT built FROM the read → SHOW) and pinned byte for byte,
     * and the other half is pinned too: an editor that clears the author (omits the key) must actually clear
     * it, not have the previous value survive.
     */
    public function test_the_author_survives_a_read_modify_write_round_trip_and_can_be_cleared(): void
    {
        $template = $this->template();
        $bot = $this->bot();

        $created = $this->postJson('/api/workflows', $this->payload([$this->generateStep($template, $bot->id)]))
            ->assertCreated()
            ->json('data');

        $this->assertSame($bot->id, $created['steps'][0]['config']['bot_id']);

        $read = $this->getJson('/api/workflows/' . $created['id'])->assertOk()->json('data');

        $this->assertSame($bot->id, $read['steps'][0]['config']['bot_id']);

        // The editor's own submit: the payload is BUILT FROM THE READ, not re-typed.
        $resubmitted = $this->putJson('/api/workflows/' . $read['id'], [
            'name' => $read['name'],
            'trigger_type' => $read['trigger_type'],
            'trigger_config' => $read['trigger_config'],
            'steps' => $read['steps'],
        ])->assertOk()->json('data');

        $this->assertSame($read['steps'], $resubmitted['steps'], 'the whole step config must round-trip unchanged');
        $this->assertSame(
            $bot->id,
            $this->getJson('/api/workflows/' . $read['id'])->assertOk()->json('data.steps.0.config.bot_id'),
        );

        // …and clearing the author (the key simply omitted) actually clears it.
        $cleared = $read['steps'];
        unset($cleared[0]['config']['bot_id']);

        $this->putJson('/api/workflows/' . $read['id'], [
            'name' => $read['name'],
            'trigger_type' => $read['trigger_type'],
            'trigger_config' => $read['trigger_config'],
            'steps' => $cleared,
        ])->assertOk();

        $this->assertArrayNotHasKey(
            'bot_id',
            $this->getJson('/api/workflows/' . $read['id'])->assertOk()->json('data.steps.0.config'),
        );
    }
}
