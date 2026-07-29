<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Models\File;
use App\Modules\Generator\Exceptions\ImageBaseUnavailable;
use App\Modules\Generator\Exceptions\ImageBaseUnsupported;
use App\Modules\Generator\Exceptions\ImageGenerateBudgetExceeded;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\ImageBaseResolver;
use App\Modules\Generator\Services\ImageChainExecutor;
use App\Modules\Variables\Models\AiUsageEvent;
use App\Modules\Variables\Support\MeterContext;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickPixel;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\ImagePrompt;
use RuntimeException;
use Tests\TestCase;

/**
 * The server-side image CHAIN (R2 sub-stage 2c/6): base resolve (disk_file / from_slot, workspace-scoped, OR
 * a metered text→image `ai_generate` base) → an ordered pixel chain (exact imageOps math) → a synchronous,
 * METERED, session-tagged `ai_edit`. Proves the fail-soft domain errors (missing base, the retained defensive
 * ai_generate throw on a DIRECT resolve), the metered ai_generate base + its per-session budget, and the
 * meter tag flowing through the chain.
 */
class ImageChainExecutorTest extends TestCase
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

        Storage::fake();
    }

    protected function tearDown(): void
    {
        app(MeterContext::class)->clearSession();
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers ---------------------------------------------------------------

    /** Raw PNG bytes of a solid $w×$h image. */
    private function png(int $w, int $h, array $rgb): string
    {
        $image = new Imagick;
        $image->newImage($w, $h, new ImagickPixel("rgb({$rgb[0]},{$rgb[1]},{$rgb[2]})"), 'png');
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    /** A disk-native image File whose bytes live on the faked disk. */
    private function diskImage(int $w = 8, int $h = 8, array $rgb = [100, 150, 200]): File
    {
        $file = File::factory()->image()->atRoot()->create(['uploader_id' => $this->user->id]);
        Storage::put($file->path, $this->png($w, $h, $rgb));

        return $file;
    }

    private function executor(): ImageChainExecutor
    {
        return app(ImageChainExecutor::class);
    }

    private function pixelOf(string $bytes, int $x = 0, int $y = 0): array
    {
        $image = new Imagick;
        $image->readImageBlob($bytes);
        $c = $image->getImagePixelColor($x, $y)->getColor();

        return [$c['r'], $c['g'], $c['b']];
    }

    // ---- base resolve ----------------------------------------------------------

    public function test_disk_file_base_with_a_pixel_chain_produces_a_png(): void
    {
        $file = $this->diskImage(8, 8, [100, 150, 200]);

        $result = $this->executor()->execute(
            ['base' => ['kind' => 'disk_file', 'file' => $file->id], 'filters' => [['kind' => 'pixel', 'op' => 'grayscale']]],
            [],
            fn (string $prompt) => $prompt,
        );

        $this->assertSame('image/png', $result['mime']);
        $this->assertSame(8, $result['width']);
        $this->assertSame(8, $result['height']);
        // Grayscale of (100,150,200) = Rec.601 luma 141 on every channel (±1).
        foreach ($this->pixelOf($result['bytes']) as $channel) {
            $this->assertLessThanOrEqual(1, abs(141 - $channel));
        }
    }

    public function test_from_slot_base_resolves_a_file_typed_slot_value(): void
    {
        $file = $this->diskImage(6, 4, [10, 20, 30]);

        // The file-typed slot value is a {id,…} descriptor (also accepts a bare id).
        $result = $this->executor()->execute(
            ['base' => ['kind' => 'from_slot', 'slot' => 'photo'], 'filters' => []],
            ['photo' => ['id' => $file->id, 'name' => 'p.png']],
            fn (string $prompt) => $prompt,
        );

        $this->assertSame(6, $result['width']);
        $this->assertSame(4, $result['height']);
    }

    public function test_ordered_pixel_chain_applies_filters_in_declared_order(): void
    {
        $file = $this->diskImage(4, 4, [100, 150, 200]);

        // grayscale → 141 on all channels, then invert → 255-141 = 114.
        $result = $this->executor()->execute(
            ['base' => ['kind' => 'disk_file', 'file' => $file->id], 'filters' => [
                ['kind' => 'pixel', 'op' => 'grayscale'],
                ['kind' => 'pixel', 'op' => 'invert'],
            ]],
            [],
            fn (string $prompt) => $prompt,
        );

        foreach ($this->pixelOf($result['bytes']) as $channel) {
            $this->assertLessThanOrEqual(1, abs(114 - $channel));
        }
    }

    // ---- fail-soft domain errors ----------------------------------------------

    public function test_a_missing_disk_file_base_throws_image_base_unavailable(): void
    {
        $this->expectException(ImageBaseUnavailable::class);

        $this->executor()->execute(
            ['base' => ['kind' => 'disk_file', 'file' => (string) \Illuminate\Support\Str::uuid()], 'filters' => []],
            [],
            fn (string $prompt) => $prompt,
        );
    }

    public function test_a_non_image_disk_file_base_throws_image_base_unavailable(): void
    {
        // A PDF (default factory) is not an image → refused even though it exists in the workspace.
        $file = File::factory()->atRoot()->create(['uploader_id' => $this->user->id]);
        Storage::put($file->path, 'not-an-image');

        $this->expectException(ImageBaseUnavailable::class);

        $this->executor()->execute(
            ['base' => ['kind' => 'disk_file', 'file' => $file->id], 'filters' => []],
            [],
            fn (string $prompt) => $prompt,
        );
    }

    public function test_an_unfilled_from_slot_base_throws_image_base_unavailable(): void
    {
        $this->expectException(ImageBaseUnavailable::class);

        $this->executor()->execute(
            ['base' => ['kind' => 'from_slot', 'slot' => 'photo'], 'filters' => []],
            [], // photo never filled
            fn (string $prompt) => $prompt,
        );
    }

    public function test_a_direct_ai_generate_resolve_still_throws_unsupported_as_a_defensive_fallback(): void
    {
        // The executor now INTERCEPTS an ai_generate base upstream (see the generate tests below), but the
        // ImageBaseResolver keeps the throw as a DEFENSIVE guard for any DIRECT resolve() call.
        $this->expectException(ImageBaseUnsupported::class);

        app(ImageBaseResolver::class)->resolve(['kind' => 'ai_generate', 'prompt' => 'a red bicycle'], []);
    }

    // ---- ai_generate base: metered + session-tagged + budgeted (R2 sub-stage 6) ------

    public function test_ai_generate_base_generates_bytes_runs_the_chain_and_tags_the_meter_with_the_session(): void
    {
        // The faked provider returns a known 6×6 PNG (base64 is the GeneratedImage's storage form).
        Image::fake([base64_encode($this->png(6, 6, [100, 150, 200]))]);

        $session = GenerationSession::factory()->create(['creator_id' => $this->user->id]);
        app(MeterContext::class)->setSession($session->id);

        $result = $this->executor()->execute(
            ['base' => ['kind' => 'ai_generate', 'prompt' => 'raw prompt'], 'filters' => [
                ['kind' => 'pixel', 'op' => 'grayscale'], // the filter chain runs on the GENERATED bytes
            ]],
            [],
            fn (string $prompt) => 'RESOLVED ' . $prompt, // stand-in for the session resolver
        );

        // The generated 6×6 image became the base and the grayscale filter ran on it.
        $this->assertSame('image/png', $result['mime']);
        $this->assertSame(6, $result['width']);
        foreach ($this->pixelOf($result['bytes']) as $channel) {
            $this->assertLessThanOrEqual(1, abs(141 - $channel)); // grayscale of (100,150,200)
        }

        // The provider saw the RESOLVED prompt (never the raw markdown), at square 1:1 + the config quality.
        Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->prompt === 'RESOLVED raw prompt'
            && $prompt->size === '1:1'
            && $prompt->quality === 'high');

        // THE meter proof through the chain: an ai_image_generate spend was recorded AND tagged with THIS session.
        $event = AiUsageEvent::where('channel', 'ai_image_generate')->where('session_id', $session->id)->first();
        $this->assertNotNull($event, 'the ai_image_generate spend must be recorded and tagged with the session id');
        $this->assertSame($this->workspace->id, $event->workspace_id);
    }

    public function test_over_the_per_session_ai_generate_budget_throws_and_does_not_call_the_provider_again(): void
    {
        config()->set('generator.image_generate_max_calls_per_session', 1);

        $calls = 0;
        Image::fake(function () use (&$calls): string {
            $calls++;

            return base64_encode($this->png(4, 4, [10, 20, 30]));
        });

        // ONE executor instance = ONE session run: the $aiGenerations counter accumulates across execute() calls.
        $executor = $this->executor();
        $plan = ['base' => ['kind' => 'ai_generate', 'prompt' => 'x'], 'filters' => []];

        $executor->execute($plan, [], fn (string $p) => $p); // 1st: within budget

        try {
            $executor->execute($plan, [], fn (string $p) => $p); // 2nd: over the cap of 1
            $this->fail('expected ImageGenerateBudgetExceeded');
        } catch (ImageGenerateBudgetExceeded $e) {
            $this->assertSame('generator.sessions.image_generate_budget', $e->messageKey());
        }

        // The provider was invoked exactly ONCE — the over-budget generate never reached it (no wasted spend).
        $this->assertSame(1, $calls);
    }

    public function test_a_generate_provider_failure_bubbles_for_the_caller_to_fail_soft(): void
    {
        Image::fake(fn () => throw new RuntimeException('provider down'));

        $this->expectException(RuntimeException::class);

        $this->executor()->execute(
            ['base' => ['kind' => 'ai_generate', 'prompt' => 'x'], 'filters' => []],
            [],
            fn (string $p) => $p,
        );
    }

    // ---- ai_edit: metered + session-tagged ------------------------------------

    public function test_ai_edit_calls_the_provider_with_the_resolved_prompt_and_tags_the_meter_with_the_session(): void
    {
        Http::fake(['*/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($this->png(4, 4, [10, 20, 30]))]]], 200)]);

        $file = $this->diskImage(8, 8, [100, 150, 200]);
        $session = GenerationSession::factory()->create(['creator_id' => $this->user->id]);

        // The session executor sets this around a run; here we set it directly to prove the tag flows through
        // ImageAiService's meter.
        app(MeterContext::class)->setSession($session->id);

        $result = $this->executor()->execute(
            ['base' => ['kind' => 'disk_file', 'file' => $file->id], 'filters' => [
                ['kind' => 'ai_edit', 'prompt' => 'raw prompt'],
            ]],
            [],
            fn (string $prompt) => 'RESOLVED ' . $prompt, // stand-in for the session resolver
        );

        // The provider received the RESOLVED prompt (never the raw markdown).
        Http::assertSent(fn ($request) => str_contains($request->url(), '/images/edits')
            && str_contains($request->body(), 'RESOLVED raw prompt'));

        // The produced image is the provider's 4×4 result (the chain swapped the working image).
        $this->assertSame(4, $result['width']);

        // THE meter proof through the chain: an ai_image_edit spend was recorded AND tagged with THIS session.
        $event = AiUsageEvent::where('channel', 'ai_image_edit')->where('session_id', $session->id)->first();
        $this->assertNotNull($event, 'the ai_image_edit spend must be recorded and tagged with the session id');
        $this->assertSame($this->workspace->id, $event->workspace_id);
    }
}
