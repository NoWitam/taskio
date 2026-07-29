<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Jobs\RunGenerationSessionJob;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\GenerationSessionExecutor;
use App\Modules\Generator\Services\GenerationSessionRunManager;
use App\Modules\Variables\Agents\AiTextAgent;
use App\Modules\Variables\Models\AiUsageEvent;
use App\Modules\Variables\Services\AiTextGenerationService;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Database\Factories\TemplateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The GENERATE flow of a session (R2 sub-stage 2b): the claim + async dispatch, and the worker run that
 * renders TEXT parts LIVE through the real `@[ai-text]` generator (budgeted + metered), defers image/scene
 * parts, is fail-soft per part, reads ONLY the snapshot, and tags every ai-text spend with the session id.
 *
 * Setup mirrors AiCostMeterTest: a real workspace + active tenancy, so the meter (the real
 * LedgerMeteredAiCall bound in the Variables provider) records rows the session-tagging assertion reads.
 */
class GenerationSessionGenerateTest extends TestCase
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

        // Creative-direction layer OFF by DEFAULT here: most cases pin run behavior that PREDATES it, and an
        // un-scripted derivation would be a REAL provider call. The cases that exercise the layer turn it on
        // and script CreativeDirectionAgent explicitly (see the "creative direction" section below).
        config()->set('generator.direction.enabled', false);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers ---------------------------------------------------------------

    /** An `@[ai-text]("…")` directive exactly as the editor encodes it (JSON then `"`→`\"`). */
    private function aiText(string $prompt): string
    {
        return $this->aiTextWithId('ai_1', $prompt);
    }

    /** Same as aiText but with an explicit directive id — for embedding SEVERAL in one body. */
    private function aiTextWithId(string $id, string $prompt): string
    {
        $payload = json_encode(['v' => 1, 'data' => ['id' => $id, 'personaId' => null, 'prompt' => $prompt, 'labels' => []]]);

        return '@[ai-text]("' . str_replace('"', '\\"', $payload) . '")';
    }

    /** A `@[variable]` directive whose pipeline ends in the opt-in assert_present HARD failure. */
    private function assertPresentDirective(string $id): string
    {
        $payload = json_encode(['v' => 1, 'data' => [
            'id' => $id,
            'type' => 'text',
            'pipeline' => [['stepId' => 's1', 'operationId' => 'assert_present', 'args' => [], 'outputType' => 'text']],
        ]]);

        return '@[variable]("' . str_replace('"', '\\"', $payload) . '")';
    }

    /** The single text slot every recipe here declares. */
    private function topicSlot(): array
    {
        return ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]];
    }

    private function seedLedger(int $totalTokens, string $channel = 'ai_text', float $estimatedCost = 0.0): void
    {
        AiUsageEvent::create([
            'channel' => $channel,
            'prompt_tokens' => $totalTokens,
            'completion_tokens' => 0,
            'total_tokens' => $totalTokens,
            'estimated_cost' => $estimatedCost,
        ]);
    }

    /** Run the worker job for $session under active tenancy (as the queue would). */
    private function runJob(GenerationSession $session): void
    {
        (new RunGenerationSessionJob($session->id, $this->workspace->id))->handle(app(GenerationSessionRunManager::class));
    }

    // ---- claim + dispatch (request path) --------------------------------------

    public function test_generate_endpoint_claims_the_session_and_queues_the_run(): void
    {
        Queue::fake();
        $session = GenerationSession::factory()->create(['creator_id' => $this->user->id]);

        $this->postJson("/api/generator/sessions/{$session->id}/generate")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'generating');

        $this->assertSame(GenerationSessionStatus::Generating, $session->fresh()->status);
        Queue::assertPushed(
            RunGenerationSessionJob::class,
            fn (RunGenerationSessionJob $job) => $job->sessionId === $session->id && $job->workspaceId === $this->workspace->id,
        );
    }

    public function test_generate_endpoint_returns_409_when_already_generating(): void
    {
        Queue::fake();
        $session = GenerationSession::factory()->status(GenerationSessionStatus::Generating)->create(['creator_id' => $this->user->id]);

        $this->postJson("/api/generator/sessions/{$session->id}/generate")->assertStatus(409);

        Queue::assertNothingPushed();
    }

    // ---- the worker run (the engine) ------------------------------------------

    public function test_run_renders_text_live_executes_image_and_tags_the_meter_with_the_session(): void
    {
        AiTextAgent::fake(fn (string $prompt) => 'AI OUT');

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot(
                'post_with_image',
                [
                    'body' => ['markdown' => 'Intro ' . $this->aiText('Describe the topic')],
                    'image' => ['base' => null, 'filters' => []],
                ],
                [$this->topicSlot()],
                ['topic' => 'Widgets'],
            )
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);

        // Text part rendered LIVE through the real @[ai-text] generator.
        $this->assertSame('ok', $session->results['body']['status']);
        $this->assertSame('text_body', $session->results['body']['kind']);
        $this->assertSame('Intro AI OUT', $session->results['body']['text']);

        // Image part now EXECUTES (R2 sub-stage 2c). This snapshot's null base fails SOFT with a localized,
        // non-secret message; the run itself still completes ready (a dedicated ok-image run is asserted in
        // ImageChainSessionRunTest).
        $this->assertSame('failed', $session->results['image']['status']);
        $this->assertSame('image_plan', $session->results['image']['kind']);
        $this->assertSame(__('generator.sessions.image_base_unavailable'), $session->results['image']['error']);

        // THE meter proof: the ai-text spend was recorded AND tagged with THIS session (MeterContext).
        $event = AiUsageEvent::where('session_id', $session->id)->where('channel', 'ai_text')->first();
        $this->assertNotNull($event, 'the ai-text spend must be recorded and tagged with the session id');
        $this->assertSame($this->workspace->id, $event->workspace_id);
    }

    public function test_with_the_direction_layer_off_the_ai_text_prompt_is_the_resolved_prompt_and_nothing_else(): void
    {
        // KILL-SWITCH BYTE-IDENTITY, pinned in the suite that owns the ai-text run path (the layer's own
        // behavior lives in CreativeDirectionTest): with `generator.direction.enabled=false` the composed
        // USER message is EXACTLY the resolved `@[ai-text]` prompt — no wrapper, no prefix, no section.
        $captured = [];
        AiTextAgent::fake(function (string $prompt) use (&$captured) {
            $captured[] = $prompt;

            return 'AI OUT';
        });

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('post', ['body' => ['markdown' => 'Intro ' . $this->aiText('Describe the topic')]], [$this->topicSlot()], ['topic' => 'Widgets'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $this->assertSame(['Describe the topic'], $captured);
        $this->assertSame('Intro AI OUT', $session->fresh()->results['body']['text']);
        // ...and exactly ONE metered call: nothing was derived.
        $this->assertSame(1, AiUsageEvent::where('channel', 'ai_text')->where('session_id', $session->id)->count());
    }

    public function test_over_cap_text_fails_closed_to_empty_but_the_run_completes_ready(): void
    {
        // R2 sub-stage 4: the gate is $-based. A workspace $ cap already exceeded by prior spend blocks
        // the next ai-text spend before it hits the provider.
        $this->workspace->update(['ai_monthly_cost_cap' => 1.00]);
        app(TenantContext::class)->set($this->workspace);
        $this->seedLedger(150, 'ai_text', 1.50);

        $hit = false;
        AiTextAgent::fake(function () use (&$hit) {
            $hit = true;

            return 'SHOULD NOT RUN';
        });

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('post', ['body' => ['markdown' => $this->aiText('write a post')]], [$this->topicSlot()], ['topic' => 'X'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        // The gate fired BEFORE spend → the ai-text resolved to '' (fail-closed) → the text part is a
        // valid empty `ok`, and the run still completes ready.
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('ok', $session->results['body']['status']);
        $this->assertSame('', $session->results['body']['text']);
        $this->assertFalse($hit, 'the provider must not be hit over cap (gate before spend)');
        // No NEW session-tagged spend recorded (the gate fired before the closure + before record).
        $this->assertSame(0, AiUsageEvent::where('session_id', $session->id)->count());
    }

    public function test_a_broken_reference_in_one_part_fails_soft_and_the_run_still_completes_ready(): void
    {
        // The body carries an assert_present over a slot that was never filled → a HARD failure the
        // resolver re-raises; the image part executes but fails soft (null base); the run still ends ready.
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot(
                'post_with_image',
                [
                    'body' => ['markdown' => $this->assertPresentDirective('slots.topic')],
                    'image' => ['base' => null, 'filters' => []],
                ],
                [$this->topicSlot()],
                [], // topic deliberately unfilled → assert_present hard-fails
            )
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);

        // The broken part failed SOFT with a LOCALIZED, NON-SECRET message (never the prompt/body).
        $this->assertSame('failed', $session->results['body']['status']);
        $this->assertSame(__('generator.sessions.part_failed'), $session->results['body']['error']);
        $this->assertArrayNotHasKey('text', $session->results['body']);
        $this->assertStringNotContainsString('assert_present', $session->results['body']['error']);

        // Every OTHER part still ran (the image part executed and failed soft on its null base).
        $this->assertSame('failed', $session->results['image']['status']);
    }

    public function test_run_reads_the_snapshot_not_the_live_template(): void
    {
        // A real template the session is cut from — a plain-variable body (no AI needed).
        $template = \App\Modules\Generator\Models\Template::factory()->create([
            'creator_id' => $this->user->id,
            'content_type' => 'post',
            'slots' => [$this->topicSlot()],
            'content' => ['body' => ['markdown' => 'Hello ' . TemplateFactory::directive('slots.topic')]],
        ]);

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('post', $template->content, $template->slots, ['topic' => 'World'])
            ->create(['creator_id' => $this->user->id, 'template_id' => $template->id]);

        // Mutate the live template AFTER the snapshot was taken.
        $template->update(['content' => ['body' => ['markdown' => 'GOODBYE ' . TemplateFactory::directive('slots.topic')]]]);

        $this->runJob($session);

        // The render used the SNAPSHOT ("Hello"), not the live template ("GOODBYE").
        $this->assertSame('Hello World', $session->fresh()->results['body']['text']);
    }

    public function test_run_is_idempotent_on_an_already_terminal_session(): void
    {
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->create([
                'creator_id' => $this->user->id,
                'results' => ['body' => ['kind' => 'text_body', 'status' => 'ok', 'text' => 'PRIOR']],
            ]);

        $this->runJob($session);

        // A terminal session is never re-run (a stale/duplicate delivery must not clobber the result).
        $this->assertSame('PRIOR', $session->fresh()->results['body']['text']);
    }

    public function test_a_whole_run_throw_marks_the_session_failed_via_the_failed_hook(): void
    {
        // A throwing executor stands in for an infra fault escaping the fail-soft per-part loop.
        $throwing = new class extends GenerationSessionExecutor
        {
            public function __construct() {}

            public function execute(GenerationSession $session): array
            {
                throw new \RuntimeException('boom');
            }
        };
        $this->app->instance(GenerationSessionExecutor::class, $throwing);

        $session = GenerationSession::factory()->status(GenerationSessionStatus::Generating)->create(['creator_id' => $this->user->id]);

        $job = new RunGenerationSessionJob($session->id, $this->workspace->id);

        try {
            $job->handle(app(GenerationSessionRunManager::class));
            $this->fail('expected the run to throw');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        // The failed() hook marks the session failed (mirrors the Disk edit's failed() path).
        $job->failed(new \RuntimeException('boom'));

        $this->assertSame(GenerationSessionStatus::Failed, $session->fresh()->status);
    }

    // ---- hardening (2b review follow-ups) -------------------------------------

    public function test_meter_session_tag_clears_after_the_run_so_a_later_spend_is_untagged(): void
    {
        AiTextAgent::fake(fn (string $prompt) => 'AI OUT');

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('post', ['body' => ['markdown' => $this->aiText('write a post')]], [$this->topicSlot()], ['topic' => 'X'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        // The run's spend was tagged with THIS session (the executor set the MeterContext) — so nothing
        // is left UNTAGGED yet.
        $this->assertSame(0, AiUsageEvent::whereNull('session_id')->where('channel', 'ai_text')->count());

        // A SECOND ai-text spend with NO session set: a standalone resolve straight through the shared
        // generator (any metered ai-text path outside a session run).
        app(AiTextGenerationService::class)->generate('a standalone prompt', null, 100);

        // It must be recorded UNTAGGED — proof the session tag did not leak past execute()'s finally.
        // Remove GenerationSessionExecutor::execute()'s `finally` clearSession() and this goes RED: the
        // standalone spend would still carry the prior run's session id, leaving the null count at 0.
        $this->assertSame(1, AiUsageEvent::whereNull('session_id')->where('channel', 'ai_text')->count());
    }

    public function test_per_session_call_budget_stops_calling_the_provider_past_the_cap(): void
    {
        config()->set('generator.ai_text_max_calls_per_session', 2);

        $calls = 0;
        AiTextAgent::fake(function () use (&$calls) {
            $calls++;

            return 'OUT';
        });

        // THREE inline @[ai-text] blocks in ONE body — one more than the cap of 2 (distinct ids so the
        // resolver treats each as its own directive).
        $markdown = 'A ' . $this->aiTextWithId('ai_1', 'one')
            . ' B ' . $this->aiTextWithId('ai_2', 'two')
            . ' C ' . $this->aiTextWithId('ai_3', 'three');

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('post', ['body' => ['markdown' => $markdown]], [$this->topicSlot()], ['topic' => 'X'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        // The provider was invoked at most the cap — never the 3rd (over-budget) time.
        $this->assertSame(2, $calls);

        // The first two directives resolved LIVE ('OUT'); the over-budget 3rd fell to '' (fail-closed),
        // and the run still completes ready.
        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('ok', $session->results['body']['status']);
        $this->assertSame('A OUT B OUT C ', $session->results['body']['text']);
    }

    public function test_fail_is_a_no_op_on_an_already_terminal_session(): void
    {
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->create([
                'creator_id' => $this->user->id,
                'results' => ['body' => ['kind' => 'text_body', 'status' => 'ok', 'text' => 'DONE']],
            ]);

        // A late reaper / job redelivery calling fail() must never flip a finished run (isTerminal guard).
        app(GenerationSessionRunManager::class)->fail($session->id);

        $fresh = $session->fresh();
        $this->assertSame(GenerationSessionStatus::Ready, $fresh->status);
        $this->assertSame('DONE', $fresh->results['body']['text']);
    }

    public function test_run_on_a_non_generating_session_is_a_no_op(): void
    {
        // A stray/unclaimed dispatch onto a still-`draft` row must NOT run it (it never won the claim).
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Draft)
            ->snapshot(
                'post',
                ['body' => ['markdown' => 'Hello ' . TemplateFactory::directive('slots.topic')]],
                [$this->topicSlot()],
                ['topic' => 'World'],
            )
            ->create(['creator_id' => $this->user->id]);

        app(GenerationSessionRunManager::class)->run($session->id);

        $fresh = $session->fresh();
        $this->assertSame(GenerationSessionStatus::Draft, $fresh->status);
        $this->assertNull($fresh->results);
    }
}
