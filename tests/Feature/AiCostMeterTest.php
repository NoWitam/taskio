<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Enums\DiskAiEditStatus;
use App\Modules\Disk\Jobs\EditDiskImageJob;
use App\Modules\Disk\Models\DiskAiEdit;
use App\Modules\Disk\Services\ImageAiService;
use App\Modules\Disk\Services\OpenAiImageEditClient;
use App\Modules\Variables\Agents\AiTextAgent;
use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Exceptions\AiBudgetExceededException;
use App\Modules\Variables\Models\AiUsageEvent;
use App\Modules\Variables\Services\AiTextGenerationService;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * The AI cost METER on the D7 {@see MeteredAiCall} seam (R2 sub-stage 2a). Proves the load-bearing
 * safety property — GATE BEFORE SPEND: an over-cap workspace's provider closure never runs — plus the
 * ledger recording (real text tokens vs a config image unit), the fail-closed ai-text path, the Disk
 * dispatch 429, the cap=0 disable, and the no-workspace no-op. The real LedgerMeteredAiCall is bound in
 * the Variables provider, so app(MeteredAiCall::class) is the meter under test.
 */
class AiCostMeterTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /** Seed the ledger with a prior spend (tokens + the $ figure the R2 sub-stage 4 gate now sums). */
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

    /** Set this workspace's monthly $ cap (the gate's effective budget) and keep the active instance fresh. */
    private function setCostCap(float $cap): void
    {
        $this->workspace->update(['ai_monthly_cost_cap' => $cap]);
        app(TenantContext::class)->set($this->workspace);
    }

    private function textResponse(int $prompt, int $completion): TextResponse
    {
        return new TextResponse('ok', new Usage(promptTokens: $prompt, completionTokens: $completion), new Meta);
    }

    // ---- Gate before spend (the load-bearing property) -------------------------------

    public function test_meter_gates_before_the_closure_runs_when_over_cap(): void
    {
        $this->setCostCap(1.00);
        $this->seedLedger(150, 'ai_text', 1.50);

        $meter = app(MeteredAiCall::class);
        $ran = false;

        try {
            $meter->meter('ai_text', function () use (&$ran) {
                $ran = true;

                return 'x';
            });
            $this->fail('expected AiBudgetExceededException');
        } catch (AiBudgetExceededException $e) {
            $this->assertSame('ai_text', $e->channel);
        }

        // THE proof: the provider closure never ran, and no new spend was recorded.
        $this->assertFalse($ran, 'the provider closure must NOT run when over cap (gate before spend)');
        $this->assertSame(1, AiUsageEvent::count());
    }

    // ---- Recording -------------------------------------------------------------------

    public function test_meter_records_tokens_from_a_text_response_usage(): void
    {
        $meter = app(MeteredAiCall::class);
        $response = $this->textResponse(30, 12);

        $result = $meter->meter('ai_text', fn () => $response);

        $this->assertSame($response, $result); // transparent to the result type
        $event = AiUsageEvent::where('channel', 'ai_text')->sole();
        $this->assertSame(30, $event->prompt_tokens);
        $this->assertSame(12, $event->completion_tokens);
        $this->assertSame(42, $event->total_tokens);
        $this->assertSame($this->workspace->id, $event->workspace_id);
        $this->assertNull($event->session_id);
    }

    public function test_meter_records_the_config_unit_for_an_opaque_image_result(): void
    {
        config()->set('ai.meter.unit_cost.ai_image_edit', 4000);

        $meter = app(MeteredAiCall::class);
        $result = $meter->meter('ai_image_edit', fn () => ['image' => 'BYTES', 'mime' => 'image/png']);

        $this->assertSame(['image' => 'BYTES', 'mime' => 'image/png'], $result);
        $event = AiUsageEvent::where('channel', 'ai_image_edit')->sole();
        $this->assertSame(0, $event->prompt_tokens);
        $this->assertSame(4000, $event->total_tokens);
    }

    /**
     * An EMBEDDINGS response carries no `->usage` — embeddings have no completion half, so laravel/ai
     * reports one flat `->tokens` int. The meter must read the REAL figure rather than falling through
     * to the opaque-result unit stand-in, because unlike an image edit the true cost IS known here, and
     * a flat stand-in would misprice every knowledge indexing run by orders of magnitude.
     */
    public function test_meter_records_real_tokens_from_an_embeddings_response(): void
    {
        // A stand-in exists for this channel; the point is that it is NOT what gets recorded.
        config()->set('ai.meter.unit_cost.ai_embedding', 4000);

        $response = new EmbeddingsResponse([[0.1, 0.2], [0.3, 0.4]], 137, new Meta);

        $result = app(MeteredAiCall::class)->meter('ai_embedding', fn () => $response);

        $this->assertSame($response, $result); // still transparent to the result type

        $event = AiUsageEvent::where('channel', 'ai_embedding')->sole();
        $this->assertSame(137, $event->total_tokens, 'the provider figure, not the flat unit');
        $this->assertSame(137, $event->prompt_tokens, 'an embedding is all input');
        $this->assertSame(0, $event->completion_tokens);
        $this->assertNotSame(4000, $event->total_tokens);
    }

    /**
     * BYTE-PRESERVING guard for the branch above. A text response also exposes a `tokens` property in
     * some package versions, so the `->usage` branch must keep winning — otherwise adding embeddings
     * support would silently re-price every existing ai_text spend.
     */
    public function test_a_text_response_still_reports_its_usage_halves(): void
    {
        app(MeteredAiCall::class)->meter('ai_text', fn () => $this->textResponse(11, 7));

        $event = AiUsageEvent::where('channel', 'ai_text')->sole();
        $this->assertSame(11, $event->prompt_tokens);
        $this->assertSame(7, $event->completion_tokens);
        $this->assertSame(18, $event->total_tokens);
    }

    public function test_cap_zero_disables_the_gate_but_still_records(): void
    {
        // No workspace override + env default 0.0 → effective cap 0 → gate OFF (byte-preserving default).
        $this->seedLedger(999999, 'ai_text', 9999.0); // far over any cap — but the gate is disabled

        $meter = app(MeteredAiCall::class);
        $ran = false;

        $result = $meter->meter('ai_text', function () use (&$ran) {
            $ran = true;

            return $this->textResponse(5, 5);
        });

        $this->assertTrue($ran);
        $this->assertInstanceOf(TextResponse::class, $result);
        $this->assertSame(2, AiUsageEvent::count()); // the seed + this recorded spend
    }

    public function test_recording_no_ops_when_no_workspace_is_active(): void
    {
        app(TenantContext::class)->clear();

        $meter = app(MeteredAiCall::class);
        $result = $meter->meter('ai_text', fn () => $this->textResponse(3, 3));

        $this->assertInstanceOf(TextResponse::class, $result);
        $this->assertSame(0, AiUsageEvent::withoutGlobalScopes()->count());
    }

    // ---- The two real spenders -------------------------------------------------------

    public function test_over_cap_ai_text_fails_closed_without_hitting_the_provider(): void
    {
        $this->setCostCap(1.00);
        $this->seedLedger(150, 'ai_text', 1.50);

        $hit = false;
        AiTextAgent::fake(function () use (&$hit) {
            $hit = true;

            return 'SHOULD NOT RUN';
        });

        $out = app(AiTextGenerationService::class)->generate('write something', null, 2000);

        $this->assertSame('', $out, 'an over-cap ai-text directive resolves to empty (fail-closed)');
        $this->assertFalse($hit, 'the provider must not be hit over cap');
    }

    public function test_image_dispatch_over_cap_returns_429_and_queues_nothing(): void
    {
        $this->setCostCap(1.00);
        $this->seedLedger(150, 'ai_image_edit', 1.50);
        Queue::fake();
        Storage::fake();

        $this->postJson('/api/disk/ai/image', [
            'image' => UploadedFile::fake()->image('canvas.png'),
            'prompt' => 'Remove the background',
        ])->assertStatus(429);

        $this->assertSame(0, DiskAiEdit::count());
        Queue::assertNothingPushed();
    }

    public function test_worker_path_gate_blocks_the_client_when_over_cap(): void
    {
        // The dispatch pre-flight is only the FIRST gate. This proves the SECOND, load-bearing one:
        // even a job that reached the worker (queued before the cap was hit, a stale delivery, etc.)
        // never reaches the provider once the workspace is over cap.
        $this->setCostCap(1.00);
        $this->seedLedger(150, 'ai_image_edit', 1.50);
        Storage::fake();

        // A spy client so we can prove the provider call never fires under the gate.
        $spy = new class extends OpenAiImageEditClient
        {
            public bool $called = false;

            public function edit(string $image, string $prompt, ?string $mask = null, string $size = 'auto'): array
            {
                $this->called = true;

                return ['image' => 'SHOULD NOT RUN', 'mime' => 'image/png'];
            }
        };
        $this->app->instance(OpenAiImageEditClient::class, $spy);

        // A queued edit with its input already persisted (as dispatch would have left it).
        $edit = DiskAiEdit::create([
            'status' => DiskAiEditStatus::Queued,
            'prompt' => 'Remove the background',
            'input_image_path' => 'disk-ai/' . $this->workspace->id . '/over-cap/image.png',
        ]);
        Storage::put($edit->input_image_path, 'PNGBYTES');

        // Run the WORKER path. The over-cap gate fires INSIDE the worker: fail-fast, no rethrow.
        (new EditDiskImageJob($edit->id, $this->workspace->id))->handle(app(ImageAiService::class));

        $this->assertFalse($spy->called, 'the provider client must NOT be called when over cap (gate before spend, in the worker)');
        $edit->refresh();
        $this->assertSame(DiskAiEditStatus::Failed, $edit->status, 'an over-cap worker edit must end failed, not stranded');
        $this->assertSame(__('disk.ai.budget'), $edit->error);
        $this->assertSame(1, AiUsageEvent::count(), 'no spend recorded — the gate fired before the closure');
    }
}
