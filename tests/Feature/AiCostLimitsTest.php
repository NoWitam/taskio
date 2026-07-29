<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Enums\DiskAiEditStatus;
use App\Modules\Disk\Services\ImageAiService;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\GenerationSessionExecutor;
use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Exceptions\AiBudgetExceededException;
use App\Modules\Variables\Models\AiUsageEvent;
use App\Modules\Variables\Services\AiUsageService;
use App\Modules\Variables\Support\LedgerMeteredAiCall;
use App\Modules\Variables\Support\MeterContext;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowRunContext;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Support\Meter\MeterActorResolver;
use App\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use ImagickPixel;
use Laravel\Ai\Image;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * R2 sub-stage 4 — AI COST LIMITS. Pins every INVARIANT of the $-first cap + per-channel price model +
 * polymorphic actor attribution + usage/cap endpoints, all on a SCRIPTED meter (no real provider):
 * cap resolution, byte-preserving-when-off, the $-gate, channel-aware estimateCost, own-DB central cap
 * read, actor at each entry point + survives purge + read-resolved names, the summary shape, the Resource
 * excluding PII, authz, and fail-open.
 */
class AiCostLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Owner Olga']);
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);

        // Default env cap OFF (byte-preserving default) unless a test opts in.
        config()->set('ai.meter.monthly_cost_cap_default', 0.0);

        // Creative-direction layer OFF: the session-run cases here count metered EVENTS, and a derivation
        // would add one (plus be a REAL provider call, since this class scripts no director). That the
        // derivation itself is metered, session-tagged and gated before spend is pinned in
        // CreativeDirectionTest.
        config()->set('generator.direction.enabled', false);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        app(MeterContext::class)->clearActor();

        parent::tearDown();
    }

    private function usage(): AiUsageService
    {
        return app(AiUsageService::class);
    }

    private function setCap(?float $cap): void
    {
        $this->workspace->update(['ai_monthly_cost_cap' => $cap]);
        app(TenantContext::class)->set($this->workspace);
    }

    private function seedEvent(string $channel, float $cost, int $tokens = 0, ?string $actorType = null, ?string $actorId = null): AiUsageEvent
    {
        return AiUsageEvent::create([
            'channel' => $channel,
            'total_tokens' => $tokens,
            'estimated_cost' => $cost,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
        ]);
    }

    private function textResponse(int $prompt, int $completion): TextResponse
    {
        return new TextResponse('ok', new Usage(promptTokens: $prompt, completionTokens: $completion), new Meta);
    }

    /** Raw PNG bytes of a solid $w×$h image (a valid base for the image chain to probe/store). */
    private function png(int $w = 6, int $h = 6): string
    {
        $image = new Imagick;
        $image->newImage($w, $h, new ImagickPixel('rgb(100,150,200)'), 'png');
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    /** A DELEGATED session's overlay columns (bot author + snapshotted voice), keyed to $botId. */
    private function delegationOverlay(string $botId): array
    {
        return [
            'bot_author_id' => $botId,
            'bot_delegation' => ['voice' => 'v', 'author' => ['id' => $botId, 'name' => 'Scribe', 'icon' => 'robot']],
        ];
    }

    // ---- Cap resolution --------------------------------------------------------------

    public function test_cap_null_override_inherits_the_env_default(): void
    {
        config()->set('ai.meter.monthly_cost_cap_default', 5.0);
        $this->setCap(null);

        $this->assertSame(5.0, $this->usage()->cap());
        $this->assertSame('default', $this->usage()->summary()['cap_source']);
    }

    public function test_cap_positive_override_wins_over_the_env_default(): void
    {
        config()->set('ai.meter.monthly_cost_cap_default', 5.0);
        $this->setCap(12.50);

        $this->assertSame(12.5, $this->usage()->cap());
        $this->assertSame('workspace', $this->usage()->summary()['cap_source']);
    }

    public function test_cap_zero_override_is_explicit_unlimited(): void
    {
        config()->set('ai.meter.monthly_cost_cap_default', 5.0);
        $this->setCap(0.0);

        $this->assertSame(0.0, $this->usage()->cap());
        $this->assertSame('unlimited', $this->usage()->summary()['cap_source']);
    }

    // ---- Own-database central cap read -----------------------------------------------

    public function test_cap_is_read_from_the_central_workspace_row_in_own_database_mode(): void
    {
        // An own-database workspace still holds its cap on the CENTRAL row (TenantContext::workspace()).
        // cap() is a PURE model read (no query), so it is correct even though the ledger would route to
        // the tenant connection — the load-bearing own-DB property.
        $own = Workspace::factory()->create([
            'owner_id' => $this->user->id,
            'db_mode' => WorkspaceDbMode::Own,
            'db_database' => 'tenant_test',
            'ai_monthly_cost_cap' => 7.00,
        ]);

        app(TenantContext::class)->set($own);

        $this->assertTrue(app(TenantContext::class)->isOwn());
        $this->assertSame(7.0, $this->usage()->cap());
    }

    // ---- Byte-preserving when uncapped -----------------------------------------------

    public function test_gate_is_byte_preserving_when_the_effective_cap_is_off(): void
    {
        // Default: no workspace override + env default 0.0 → effective cap 0 → gate OFF, exactly as
        // before sub-stage 4. Even a month wildly over any conceivable cost must NOT block.
        $this->setCap(null);
        $this->seedEvent('ai_text', 9999.0, 999999);

        $meter = app(MeteredAiCall::class);
        $ran = false;

        $result = $meter->meter('ai_text', function () use (&$ran) {
            $ran = true;

            return $this->textResponse(5, 5);
        });

        $this->assertTrue($ran, 'the closure MUST run when the cap is off (byte-preserving)');
        $this->assertInstanceOf(TextResponse::class, $result);
    }

    // ---- The $-gate ------------------------------------------------------------------

    public function test_gate_blocks_over_the_workspace_dollar_cap_even_when_env_default_is_zero(): void
    {
        config()->set('ai.meter.monthly_cost_cap_default', 0.0); // env off — the workspace override alone gates
        $this->setCap(1.00);
        $this->seedEvent('ai_text', 1.50);

        $ran = false;

        try {
            app(MeteredAiCall::class)->meter('ai_text', function () use (&$ran) {
                $ran = true;

                return 'x';
            });
            $this->fail('expected AiBudgetExceededException');
        } catch (AiBudgetExceededException $e) {
            $this->assertSame('ai_text', $e->channel);
        }

        $this->assertFalse($ran, 'the provider closure must not run over the $ cap (gate before spend)');
    }

    public function test_gate_routes_through_cap_the_single_source_of_truth(): void
    {
        // Under cap → allowed; then push the workspace cap below the used $ → blocked. Proves the gate
        // reads AiUsageService::cap() (not a stale config), so a cap change takes effect immediately.
        $this->setCap(10.00);
        $this->seedEvent('ai_text', 4.00);

        $this->assertNull($this->attemptSpend()); // 4.00 < 10.00 → allowed (no exception)

        $this->setCap(3.00); // now 4.00 >= 3.00
        $this->assertInstanceOf(AiBudgetExceededException::class, $this->attemptSpend());
    }

    /** Try one metered spend; returns the thrown budget exception, or null when it was allowed. */
    private function attemptSpend(): ?AiBudgetExceededException
    {
        try {
            app(MeteredAiCall::class)->assertWithinBudget('ai_text');

            return null;
        } catch (AiBudgetExceededException $e) {
            return $e;
        }
    }

    // ---- Channel-aware estimateCost --------------------------------------------------

    public function test_ai_text_cost_is_per_token(): void
    {
        config()->set('ai.meter.pricing.ai_text.per_1k_tokens', 0.005);

        app(MeteredAiCall::class)->meter('ai_text', fn () => $this->textResponse(1500, 500)); // 2000 tokens

        $event = AiUsageEvent::where('channel', 'ai_text')->sole();
        $this->assertSame(2000, $event->total_tokens);
        $this->assertSame(0.01, (float) $event->estimated_cost); // 2000/1000 * 0.005
    }

    public function test_image_edit_cost_is_per_call_not_per_token(): void
    {
        config()->set('ai.meter.pricing.ai_image_edit.per_call', 0.17);
        config()->set('ai.meter.unit_cost.ai_image_edit', 4000);

        app(MeteredAiCall::class)->meter('ai_image_edit', fn () => ['image' => 'BYTES']);

        $event = AiUsageEvent::where('channel', 'ai_image_edit')->sole();
        $this->assertSame(4000, $event->total_tokens); // token stand-in unchanged (secondary display)
        $this->assertSame(0.17, (float) $event->estimated_cost); // flat per-call, independent of tokens
    }

    public function test_image_generate_cost_is_per_call(): void
    {
        config()->set('ai.meter.pricing.ai_image_generate.per_call', 0.19);

        app(MeteredAiCall::class)->meter('ai_image_generate', fn () => ['image' => 'BYTES']);

        $this->assertSame(0.19, (float) AiUsageEvent::where('channel', 'ai_image_generate')->sole()->estimated_cost);
    }

    // ---- Actor attribution -----------------------------------------------------------

    public function test_actor_defaults_to_the_authenticated_user(): void
    {
        app(MeteredAiCall::class)->meter('ai_text', fn () => $this->textResponse(1, 1));

        $event = AiUsageEvent::sole();
        $this->assertSame('user', $event->actor_type);
        $this->assertSame($this->user->id, $event->actor_id);
    }

    public function test_actor_resolves_to_the_active_workflow_run(): void
    {
        $run = WorkflowRun::factory()->create();
        app(WorkflowRunContext::class)->set($run);

        try {
            app(MeteredAiCall::class)->meter('ai_text', fn () => $this->textResponse(1, 1));
        } finally {
            app(WorkflowRunContext::class)->clear();
        }

        $event = AiUsageEvent::sole();
        $this->assertSame('workflow_run', $event->actor_type);
        $this->assertSame($run->id, $event->actor_id);
    }

    public function test_explicit_meter_context_actor_wins_over_run_and_auth(): void
    {
        // The queued session run (owner/bot) sets an EXPLICIT actor; it must beat both the active run and
        // auth() — the head of the precedence chain (mirrors HasCreator).
        $botId = (string) Str::uuid();
        $run = WorkflowRun::factory()->create();
        app(WorkflowRunContext::class)->set($run);
        app(MeterContext::class)->setActor('bot', $botId);

        try {
            app(MeteredAiCall::class)->meter('ai_text', fn () => $this->textResponse(1, 1));
        } finally {
            app(MeterContext::class)->clearActor();
            app(WorkflowRunContext::class)->clear();
        }

        $event = AiUsageEvent::sole();
        $this->assertSame('bot', $event->actor_type);
        $this->assertSame($botId, $event->actor_id);
    }

    public function test_meter_actor_resolver_defaults_id_only_tag_to_user(): void
    {
        // An id tagged with a null type (the Disk worker path) resolves to the user alias.
        [$type, $id] = app(MeterActorResolver::class)->resolve(null, 'u-1');

        $this->assertSame('user', $type);
        $this->assertSame('u-1', $id);
    }

    public function test_session_meter_actor_is_owner_when_undelegated_and_bot_when_delegated(): void
    {
        $undelegated = GenerationSession::factory()->make(['creator_id' => 'owner-1', 'creator_type' => 'user']);
        $this->assertSame(['user', 'owner-1'], $undelegated->meterActor());

        $delegated = GenerationSession::factory()->make([
            'creator_id' => 'owner-1',
            'creator_type' => 'user',
            'bot_author_id' => 'bot-9',
            'bot_delegation' => ['voice' => 'v', 'author' => ['id' => 'bot-9']],
        ]);
        $this->assertSame(['bot', 'bot-9'], $delegated->meterActor());
    }

    public function test_actor_survives_a_session_purge(): void
    {
        // No FK: an event tagged with a session + actor keeps both after the session is gone.
        $event = AiUsageEvent::create([
            'channel' => 'ai_text',
            'total_tokens' => 10,
            'estimated_cost' => 0.05,
            'session_id' => (string) Str::uuid(),
            'actor_type' => 'user',
            'actor_id' => $this->user->id,
        ]);

        // Nothing references the session row; deleting the concept never touches the ledger.
        $this->assertDatabaseHas('ai_usage_events', [
            'id' => $event->id,
            'actor_type' => 'user',
            'actor_id' => $this->user->id,
        ]);
    }

    public function test_disk_worker_attributes_the_spend_to_the_dispatching_user(): void
    {
        // The Disk worker has no auth(); the acting user id threaded through the job is tagged explicitly.
        $service = app(\App\Modules\Disk\Services\ImageAiService::class);
        \Illuminate\Support\Facades\Storage::fake();

        $edit = \App\Modules\Disk\Models\DiskAiEdit::create([
            'status' => \App\Modules\Disk\Enums\DiskAiEditStatus::Queued,
            'prompt' => 'Remove the background',
            'input_image_path' => 'disk-ai/' . $this->workspace->id . '/x/image.png',
        ]);
        \Illuminate\Support\Facades\Storage::put($edit->input_image_path, 'PNGBYTES');

        // Scripted client — no real provider.
        $spy = new class extends \App\Modules\Disk\Services\OpenAiImageEditClient
        {
            public function edit(string $image, string $prompt, ?string $mask = null): array
            {
                return ['image' => 'RESULT', 'mime' => 'image/png'];
            }
        };
        $this->app->instance(\App\Modules\Disk\Services\OpenAiImageEditClient::class, $spy);
        // Rebuild the service so it takes the spy client.
        $service = app(\App\Modules\Disk\Services\ImageAiService::class);

        $service->process($edit->id, $this->user->id);

        $event = AiUsageEvent::where('channel', 'ai_image_edit')->sole();
        $this->assertSame('user', $event->actor_type);
        $this->assertSame($this->user->id, $event->actor_id);
    }

    // ---- Summary shape ---------------------------------------------------------------

    public function test_summary_reports_dollars_channels_and_actors_with_resolved_names(): void
    {
        config()->set('ai.meter.warn_ratio', 0.8);
        $this->setCap(10.00);

        $alice = User::factory()->create(['name' => 'Alice']);

        $this->seedEvent('ai_text', 3.00, 6000, 'user', $alice->id);
        $this->seedEvent('ai_image_edit', 5.00, 4000, 'user', $alice->id);
        $this->seedEvent('ai_image_generate', 1.00, 4000, null, null); // unattributed

        $summary = $this->usage()->summary();

        $this->assertSame('USD', $summary['currency']);
        $this->assertTrue($summary['estimated']);
        $this->assertSame(9.0, $summary['cost_used']);
        $this->assertSame(10.0, $summary['cost_cap']);
        $this->assertSame(1.0, $summary['cost_remaining']);
        $this->assertSame('workspace', $summary['cap_source']);
        $this->assertTrue($summary['warn_reached']); // 9.0 >= 10 * 0.8
        $this->assertFalse($summary['blocked']);      // 9.0 < 10
        $this->assertSame(14000, $summary['tokens_used']);

        // per_channel — sorted by $ desc.
        $channels = array_column($summary['per_channel'], 'channel');
        $this->assertSame(['ai_image_edit', 'ai_text', 'ai_image_generate'], $channels);

        // per_actor — Alice's two rows collapse into one $8 entry with her resolved name.
        $alicerow = collect($summary['per_actor'])->firstWhere('actor_id', $alice->id);
        $this->assertNotNull($alicerow);
        $this->assertSame('Alice', $alicerow['display_name']);
        $this->assertSame(8.0, $alicerow['cost']);
    }

    public function test_summary_blocked_is_true_at_or_over_cap(): void
    {
        $this->setCap(2.00);
        $this->seedEvent('ai_text', 2.00);

        $summary = $this->usage()->summary();
        $this->assertTrue($summary['blocked']);
        $this->assertSame(0.0, $summary['cost_remaining']);
    }

    public function test_summary_per_actor_folds_the_tail_into_an_others_bucket(): void
    {
        // Seven distinct actors → top 5 + an "others" bucket for the remaining two.
        for ($i = 1; $i <= 7; $i++) {
            $this->seedEvent('ai_text', (float) $i, 100, 'user', (string) Str::uuid());
        }

        $perActor = $this->usage()->summary()['per_actor'];
        $this->assertCount(6, $perActor); // 5 named + 1 others

        $others = collect($perActor)->firstWhere('actor_type', 'others');
        $this->assertNotNull($others);
        $this->assertSame(3.0, $others['cost']); // the two smallest: $1 + $2
        $this->assertNull($others['actor_id']);
    }

    // ---- Fail-open -------------------------------------------------------------------

    public function test_gate_fails_open_on_a_ledger_read_error(): void
    {
        $this->setCap(1.00); // gate ON

        $throwing = new class(app(TenantContext::class)) extends AiUsageService
        {
            public function cap(): float
            {
                return 1.00; // gate active
            }

            public function currentMonthCost(): float
            {
                throw new \RuntimeException('ledger read exploded');
            }
        };

        $meter = new LedgerMeteredAiCall(
            app(TenantContext::class),
            app(MeterContext::class),
            $throwing,
            app(MeterActorResolver::class),
        );

        $ran = false;
        $meter->meter('ai_text', function () use (&$ran) {
            $ran = true;

            return $this->textResponse(1, 1);
        });

        $this->assertTrue($ran, 'a ledger read error must fail OPEN (treated as within budget)');
    }

    // ---- Endpoints: authz + Resource -------------------------------------------------

    public function test_member_can_read_the_usage_summary(): void
    {
        $this->setCap(4.00);
        $this->seedEvent('ai_text', 1.00, 2000, 'user', $this->user->id);

        $this->getJson('/api/workspaces/' . $this->workspace->id . '/ai-usage')
            ->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.cost_cap', 4)
            ->assertJsonPath('data.cost_used', 1)
            ->assertJsonPath('data.cap_source', 'workspace')
            ->assertJsonPath('data.can_manage', true);
    }

    public function test_summary_resource_exposes_no_meta_or_prompt(): void
    {
        $this->seedEvent('ai_text', 1.00, 2000, 'user', $this->user->id);

        $data = $this->getJson('/api/workspaces/' . $this->workspace->id . '/ai-usage')
            ->assertOk()
            ->json('data');

        $flat = json_encode($data);
        $this->assertStringNotContainsString('meta', $flat);
        $this->assertStringNotContainsString('prompt', $flat);
        $this->assertArrayNotHasKey('meta', $data);

        // Every per_actor entry carries only aggregate + resolved-name keys.
        foreach ($data['per_actor'] as $row) {
            $this->assertSame(
                ['actor_type', 'actor_id', 'display_name', 'icon', 'cost', 'tokens'],
                array_keys($row),
            );
        }
    }

    public function test_non_member_cannot_read_the_summary(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->withHeader('X-Workspace-Id', $this->workspace->id)
            ->getJson('/api/workspaces/' . $this->workspace->id . '/ai-usage')
            ->assertForbidden();
    }

    public function test_owner_can_set_the_cap(): void
    {
        $this->patchJson('/api/workspaces/' . $this->workspace->id . '/ai-usage/cap', [
            'monthly_cost_cap' => 25.50,
        ])
            ->assertOk()
            ->assertJsonPath('data.cost_cap', 25.5)
            ->assertJsonPath('data.cap_source', 'workspace');

        $this->assertSame('25.50', $this->workspace->fresh()->ai_monthly_cost_cap);
    }

    public function test_setting_the_cap_to_null_clears_the_override(): void
    {
        $this->setCap(9.00);

        $this->patchJson('/api/workspaces/' . $this->workspace->id . '/ai-usage/cap', [
            'monthly_cost_cap' => null,
        ])->assertOk()->assertJsonPath('data.cap_source', 'unlimited');

        $this->assertNull($this->workspace->fresh()->ai_monthly_cost_cap);
    }

    public function test_negative_cap_is_rejected(): void
    {
        $this->patchJson('/api/workspaces/' . $this->workspace->id . '/ai-usage/cap', [
            'monthly_cost_cap' => -1,
        ])->assertStatus(422)->assertJsonValidationErrors('monthly_cost_cap');
    }

    public function test_non_owner_member_cannot_set_the_cap(): void
    {
        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id);

        $this->actingAs($member)->withHeader('X-Workspace-Id', $this->workspace->id)
            ->patchJson('/api/workspaces/' . $this->workspace->id . '/ai-usage/cap', [
                'monthly_cost_cap' => 5.00,
            ])->assertForbidden();
    }

    public function test_cross_workspace_cap_write_is_rejected(): void
    {
        $otherOwner = User::factory()->create();
        $other = Workspace::factory()->create(['owner_id' => $otherOwner->id]);
        $other->users()->attach($otherOwner->id);

        // Acting as this workspace's owner against ANOTHER workspace → not its owner → forbidden.
        $this->patchJson('/api/workspaces/' . $other->id . '/ai-usage/cap', [
            'monthly_cost_cap' => 5.00,
        ])->assertForbidden();
    }

    // ---- Actor scope: no leak / correct inheritance across a run ----------------------

    public function test_a_delegated_run_clears_the_actor_so_a_later_untagged_spend_is_not_the_stale_bot(): void
    {
        // A DELEGATED session tags the meter with its BOT actor for the WHOLE run (the executor sets it once
        // and clears it in a finally). A SEPARATE untagged spend right after — in the SAME process, over the
        // singleton MeterContext — must NOT inherit the stale bot: it resolves through the normal chain (here
        // the authed user). Removing the executor's clearActor() turns this RED (the second event carries bot).
        $botId = (string) Str::uuid();
        $session = GenerationSession::factory()->create(
            ['creator_id' => $this->user->id] + $this->delegationOverlay($botId),
        );

        // The delegated run: the default recipe is a plain-variable body (no metered spend), but execute()
        // still sets + clears the bot actor around the whole scope.
        app(GenerationSessionExecutor::class)->execute($session);

        // A later untagged metered spend with no explicit actor set.
        app(MeteredAiCall::class)->meter('ai_text', fn () => $this->textResponse(1, 1));

        $event = AiUsageEvent::where('channel', 'ai_text')->sole();
        $this->assertNotSame('bot', $event->actor_type, 'the stale bot actor must not leak into a later spend');
        $this->assertSame('user', $event->actor_type); // the authed user, via the default resolution
        $this->assertSame($this->user->id, $event->actor_id);
    }

    public function test_a_nested_image_spend_in_a_delegated_run_carries_the_bot_actor(): void
    {
        Storage::fake();
        Image::fake([base64_encode($this->png(6, 6))]); // the scripted text→image base (no real provider)

        $botId = (string) Str::uuid();
        $session = GenerationSession::factory()
            ->snapshot('post_with_image', [
                'body' => ['markdown' => 'Body'], // plain text, no ai-text spend
                'image' => ['base' => ['kind' => 'ai_generate', 'prompt' => 'A poster'], 'filters' => []],
            ], [], [])
            ->create(['creator_id' => $this->user->id] + $this->delegationOverlay($botId));

        app(GenerationSessionExecutor::class)->execute($session);

        // The ai_image_generate spend recorded INSIDE the run scope carries the session's BOT actor — the
        // executor sets the actor ONCE around the whole scope (text AND image), so the nested image inherits it.
        $event = AiUsageEvent::where('channel', 'ai_image_generate')->sole();
        $this->assertSame('bot', $event->actor_type);
        $this->assertSame($botId, $event->actor_id);
    }

    // ---- Request-path byte-preservation when the cap is off --------------------------

    public function test_disk_dispatch_pre_flight_is_a_no_op_when_the_cap_is_off(): void
    {
        Queue::fake();
        Storage::fake();

        // Cap OFF (setUp default) even with a month wildly over any conceivable cost: the REQUEST-path
        // pre-flight (ImageAiService::dispatch → assertWithinBudget) must NOT 429. Distinct from the
        // meter-level byte-off gate test — this pins the dispatch entry point stays byte-preserving.
        $this->seedEvent('ai_image_edit', 9999.0);

        $edit = app(ImageAiService::class)->dispatch(
            UploadedFile::fake()->create('canvas.png', 1, 'image/png'),
            'Remove the background',
        );

        // Reaching here (no 429 abort) with a Queued row proves the pre-flight passed.
        $this->assertSame(DiskAiEditStatus::Queued, $edit->status);
    }

    // ---- Own-database monthly $ SUM on the tenant connection --------------------------

    public function test_current_month_cost_sums_the_tenant_ledger_in_own_database_mode(): void
    {
        // An own-database workspace's monthly $ SUM must read the TENANT ledger (the events routed to the
        // dedicated connection), not central — complements the own-DB cap-READ test (a pure model read).
        $dbFile = tempnam(sys_get_temp_dir(), 'tenant_') . '.sqlite';
        touch($dbFile);

        $own = Workspace::factory()->create([
            'owner_id' => $this->user->id,
            'db_mode' => WorkspaceDbMode::Own,
            'db_driver' => 'sqlite',
            'db_database' => $dbFile,
        ]);

        app(TenantManager::class)->configure($own);
        $this->createTenantLedgerTable();

        app(TenantContext::class)->set($own);

        try {
            $this->assertTrue(app(TenantContext::class)->isOwn());

            // Two spends written UNDER own-DB tenancy land on the TENANT connection (TenantAware routing).
            $this->seedEvent('ai_text', 2.50);
            $this->seedEvent('ai_image_generate', 1.25);

            // The monthly $ SUM reflects the tenant-written events, and central holds none.
            $this->assertSame(3.75, $this->usage()->currentMonthCost());
            $this->assertSame(2, DB::connection(TenantManager::CONNECTION)->table('ai_usage_events')->count());
            $this->assertSame(0, DB::connection(config('database.default'))->table('ai_usage_events')->count());
        } finally {
            app(TenantContext::class)->clear();
            @unlink($dbFile);
        }
    }

    /** Mirror the tenant `ai_usage_events` schema (central minus workspace_id) on the tenant connection. */
    private function createTenantLedgerTable(): void
    {
        Schema::connection(TenantManager::CONNECTION)->create('ai_usage_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('channel');
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('estimated_cost', 10, 4)->default(0);
            $table->uuid('session_id')->nullable();
            $table->string('actor_type')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }
}
