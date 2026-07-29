<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Services\ImageGenerateService;
use App\Modules\Variables\Exceptions\AiBudgetExceededException;
use App\Modules\Variables\Models\AiUsageEvent;
use App\Modules\Variables\Support\MeterContext;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Imagick;
use ImagickPixel;
use Laravel\Ai\AiManager;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\ImagePrompt;
use Tests\TestCase;

/**
 * The metered text→image generation service (R2 sub-stage 6). Proves the load-bearing posture it inherits
 * from the shared cost meter: the provider call is METERED (channel `ai_image_generate`), session-tagged via
 * the ambient MeterContext, and GATED BEFORE SPEND on the workspace's monthly token budget — an over-cap
 * workspace never reaches the provider. Uses laravel/ai's Image::fake() — no real provider call.
 */
class ImageGenerateServiceTest extends TestCase
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
        app(MeterContext::class)->clearSession();
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /** Raw PNG bytes of a solid $w×$h image. */
    private function png(int $w, int $h, array $rgb): string
    {
        $image = new Imagick;
        $image->newImage($w, $h, new ImagickPixel("rgb({$rgb[0]},{$rgb[1]},{$rgb[2]})"), 'png');
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    /** Seed the ledger so the rolling-month token sum is $totalTokens (drives the gate). */
    private function seedLedger(int $totalTokens): void
    {
        AiUsageEvent::create([
            'channel' => 'ai_image_generate',
            'prompt_tokens' => $totalTokens,
            'completion_tokens' => 0,
            'total_tokens' => $totalTokens,
            'estimated_cost' => 1.50, // over the $ cap for the R2 sub-stage 4 gate
        ]);
    }

    public function test_generate_returns_raw_bytes_and_records_a_session_tagged_meter_row(): void
    {
        $bytes = $this->png(8, 8, [100, 150, 200]);
        // Image::fake wraps a string response as the GeneratedImage's base64 storage form; (string) decodes it.
        Image::fake([base64_encode($bytes)]);

        $session = (string) Str::uuid(); // the ledger's session_id is a uuid column
        app(MeterContext::class)->setSession($session);

        $result = app(ImageGenerateService::class)->generate('a red bicycle');

        // The raw provider bytes are returned verbatim (the (string) cast decoded the base64), nominal PNG.
        $this->assertSame($bytes, $result['bytes']);
        $this->assertSame('image/png', $result['mime']);

        // The provider saw the prompt at square 1:1 + the config quality, sent to the CONFIGURED provider
        // (config('ai.image_generate_provider')), never laravel/ai's own default_for_images.
        Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->prompt === 'a red bicycle'
            && $prompt->size === '1:1'
            && $prompt->quality === 'high'
            && app(AiManager::class)->imageProvider(config('ai.image_generate_provider'))::class === $prompt->provider::class);

        // THE meter proof: an ai_image_generate spend recorded, the config unit as tokens, session-tagged.
        $event = AiUsageEvent::where('channel', 'ai_image_generate')->where('session_id', $session)->first();
        $this->assertNotNull($event, 'the ai_image_generate spend must be recorded and session-tagged');
        $this->assertSame($this->workspace->id, $event->workspace_id);
        $this->assertSame((int) config('ai.meter.unit_cost.ai_image_generate'), $event->total_tokens);
    }

    public function test_generate_targets_the_configured_provider_not_the_package_image_default(): void
    {
        // laravel/ai's own default_for_images is 'gemini' (this app neither overrides it nor holds a key for
        // it); our explicit config routes generation to the app provider instead. Guarding the two are DISTINCT
        // makes this a genuine regression pin: a revert to the implicit `->generate()` (which falls back to
        // default_for_images) would record the gemini provider and fail the assertion below.
        $this->assertNotSame(config('ai.default_for_images'), config('ai.image_generate_provider'));

        Image::fake([base64_encode($this->png(4, 4, [1, 2, 3]))]);

        app(ImageGenerateService::class)->generate('a red bicycle');

        // The recorded generation went to the provider config('ai.image_generate_provider') resolves to.
        $expected = app(AiManager::class)->imageProvider(config('ai.image_generate_provider'))::class;
        Image::assertGenerated(fn (ImagePrompt $prompt) => $expected === $prompt->provider::class);
    }

    public function test_generate_gates_before_spend_when_the_monthly_token_budget_is_over_cap(): void
    {
        config()->set('ai.meter.monthly_cost_cap_default', 1.00);
        $this->seedLedger(150); // already over the cap

        Image::fake();

        try {
            app(ImageGenerateService::class)->generate('a red bicycle');
            $this->fail('expected AiBudgetExceededException');
        } catch (AiBudgetExceededException $e) {
            $this->assertSame('ai_image_generate', $e->channel);
        }

        // THE gate proof: the provider was NEVER reached and no new spend row was recorded.
        Image::assertNothingGenerated();
        $this->assertSame(1, AiUsageEvent::count(), 'only the seeded row — the over-cap generate recorded nothing');
    }
}
