<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Models\File;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Jobs\RunGenerationSessionJob;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\GeneratedImageStore;
use App\Modules\Generator\Services\GenerationSessionRunManager;
use App\Modules\Variables\Agents\AiTextAgent;
use App\Modules\Variables\Models\AiUsageEvent;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickPixel;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\ImagePrompt;
use Tests\TestCase;

/**
 * The full SESSION RUN with the image chain (R2 sub-stage 2c/6): a `post_with_image` recipe renders the text
 * part LIVE (as 2b) AND executes the image part — resolving a Disk base OR a metered text→image `ai_generate`
 * base, running an `ai_edit`, storing the produced image — with the ai_text, ai_image_edit AND ai_image_generate
 * spends session-tagged on the meter. Per-part fail-soft holds, and a `video_script` scene_plan run renders
 * narration + its optional scene image.
 */
class ImageChainSessionRunTest extends TestCase
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

        // Creative-direction layer OFF: these cases pin run behavior that PREDATES it, and an un-scripted
        // derivation would be a REAL provider call. The layer is covered by CreativeDirectionTest.
        config()->set('generator.direction.enabled', false);

        Storage::fake();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers ---------------------------------------------------------------

    private function aiText(string $prompt): string
    {
        $payload = json_encode(['v' => 1, 'data' => ['id' => 'ai_1', 'personaId' => null, 'prompt' => $prompt, 'labels' => []]]);

        return '@[ai-text]("' . str_replace('"', '\\"', $payload) . '")';
    }

    private function topicSlot(): array
    {
        return ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]];
    }

    private function png(int $w, int $h, array $rgb): string
    {
        $image = new Imagick;
        $image->newImage($w, $h, new ImagickPixel("rgb({$rgb[0]},{$rgb[1]},{$rgb[2]})"), 'png');
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    private function diskImage(int $w = 8, int $h = 8): File
    {
        $file = File::factory()->image()->atRoot()->create(['uploader_id' => $this->user->id]);
        Storage::put($file->path, $this->png($w, $h, [100, 150, 200]));

        return $file;
    }

    private function runJob(GenerationSession $session): void
    {
        (new RunGenerationSessionJob($session->id, $this->workspace->id))->handle(app(GenerationSessionRunManager::class));
    }

    // ---- the run ---------------------------------------------------------------

    public function test_post_with_image_run_renders_text_and_produces_an_ok_image_with_both_meter_rows(): void
    {
        AiTextAgent::fake(fn (string $prompt) => 'AI OUT');
        Http::fake(['*/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($this->png(4, 4, [10, 20, 30]))]]], 200)]);

        $file = $this->diskImage(8, 8);

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot(
                'post_with_image',
                [
                    'body' => ['markdown' => 'Intro ' . $this->aiText('Describe the topic')],
                    'image' => [
                        'base' => ['kind' => 'disk_file', 'file' => $file->id],
                        'filters' => [
                            ['kind' => 'pixel', 'op' => 'grayscale'],
                            ['kind' => 'ai_edit', 'prompt' => 'make it pop'],
                        ],
                    ],
                ],
                [$this->topicSlot()],
                ['topic' => 'Widgets'],
            )
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);

        // Text part rendered LIVE (as 2b).
        $this->assertSame('ok', $session->results['body']['status']);
        $this->assertSame('Intro AI OUT', $session->results['body']['text']);

        // Image part EXECUTED: ok, with the produced image's meta (no bytes in the JSON) — the provider result
        // is a 4×4 PNG.
        $this->assertSame('ok', $session->results['image']['status']);
        $this->assertSame('image_plan', $session->results['image']['kind']);
        $this->assertSame('image/png', $session->results['image']['image']['mime']);
        $this->assertSame(4, $session->results['image']['image']['width']);
        $this->assertArrayNotHasKey('bytes', $session->results['image']['image']);

        // The produced bytes live in the session-scoped store at version 1 (served by the serve endpoint, saved
        // by save-to-disk); the ok result carries that version for the FE.
        $this->assertSame(1, $session->results['image']['version']);
        $this->assertSame(1, $session->results['image']['image']['version']);
        $this->assertTrue(app(GeneratedImageStore::class)->exists($session->id, 'image', 1));

        // THE meter proof through BOTH channels, both tagged with THIS session.
        $this->assertNotNull(
            AiUsageEvent::where('channel', 'ai_text')->where('session_id', $session->id)->first(),
            'the ai-text spend must be recorded and session-tagged',
        );
        $this->assertNotNull(
            AiUsageEvent::where('channel', 'ai_image_edit')->where('session_id', $session->id)->first(),
            'the ai_image_edit spend must be recorded and session-tagged (meter tag through the image chain)',
        );
    }

    public function test_a_failing_image_part_fails_soft_and_the_text_part_still_renders(): void
    {
        AiTextAgent::fake(fn (string $prompt) => 'AI OUT');

        // An unfilled from_slot base is unavailable → the image part fails soft with the localized message; the
        // text part still renders and the run completes ready.
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot(
                'post_with_image',
                [
                    'body' => ['markdown' => 'Body ' . $this->aiText('write')],
                    'image' => ['base' => ['kind' => 'from_slot', 'slot' => 'photo'], 'filters' => []],
                ],
                [$this->topicSlot()],
                ['topic' => 'X'], // photo slot never filled
            )
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('ok', $session->results['body']['status']);
        $this->assertSame('failed', $session->results['image']['status']);
        $this->assertSame(__('generator.sessions.image_base_unavailable'), $session->results['image']['error']);
        $this->assertFalse(app(GeneratedImageStore::class)->exists($session->id, 'image', 1));
    }

    public function test_post_with_image_ai_generate_base_produces_an_ok_image_with_text_and_generate_meter_rows(): void
    {
        AiTextAgent::fake(fn (string $prompt) => 'AI OUT');
        // The faked text→image provider returns a known 6×6 PNG for the generated base.
        Image::fake([base64_encode($this->png(6, 6, [40, 80, 120]))]);

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot(
                'post_with_image',
                [
                    'body' => ['markdown' => 'Intro ' . $this->aiText('Describe the topic')],
                    'image' => [
                        // The base prompt carries a slot directive — it must be RESOLVED before the provider sees it.
                        'base' => ['kind' => 'ai_generate', 'prompt' => 'A poster about ' . \Database\Factories\TemplateFactory::directive('slots.topic')],
                        'filters' => [['kind' => 'pixel', 'op' => 'grayscale']],
                    ],
                ],
                [$this->topicSlot()],
                ['topic' => 'Widgets'],
            )
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);

        // Text part rendered LIVE (as 2b).
        $this->assertSame('ok', $session->results['body']['status']);
        $this->assertSame('Intro AI OUT', $session->results['body']['text']);

        // Image part EXECUTED from a GENERATED base: ok, produced 6×6 PNG, stored at version 1.
        $this->assertSame('ok', $session->results['image']['status']);
        $this->assertSame('image/png', $session->results['image']['image']['mime']);
        $this->assertSame(6, $session->results['image']['image']['width']);
        $this->assertSame(1, $session->results['image']['image']['version']);
        $this->assertTrue(app(GeneratedImageStore::class)->exists($session->id, 'image', 1));

        // The provider saw the RESOLVED prompt (slot substituted), never the raw directive markdown.
        Image::assertGenerated(fn (ImagePrompt $prompt) => str_contains($prompt->prompt, 'A poster about Widgets')
            && !str_contains($prompt->prompt, 'slots.topic'));

        // THE meter proof: BOTH the ai_text and ai_image_generate spends were recorded AND session-tagged.
        $this->assertNotNull(
            AiUsageEvent::where('channel', 'ai_text')->where('session_id', $session->id)->first(),
            'the ai-text spend must be recorded and session-tagged',
        );
        $this->assertNotNull(
            AiUsageEvent::where('channel', 'ai_image_generate')->where('session_id', $session->id)->first(),
            'the ai_image_generate spend must be recorded and session-tagged (meter tag through the generate base)',
        );
    }

    public function test_scene_plan_run_renders_narration_and_its_optional_scene_image(): void
    {
        $file = $this->diskImage(8, 8);

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot(
                'video_script',
                [
                    'script' => ['markdown' => 'A tale of ' . \Database\Factories\TemplateFactory::directive('slots.topic')],
                    'scene_plan' => ['scenes' => [
                        [
                            'narration' => ['markdown' => 'Scene about ' . \Database\Factories\TemplateFactory::directive('slots.topic')],
                            'image_plan' => ['base' => ['kind' => 'disk_file', 'file' => $file->id], 'filters' => [['kind' => 'pixel', 'op' => 'grayscale']]],
                        ],
                    ]],
                ],
                [$this->topicSlot()],
                ['topic' => 'Foxes'],
            )
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);

        // Script body rendered (a plain variable, no AI).
        $this->assertSame('ok', $session->results['script']['status']);
        $this->assertSame('A tale of Foxes', $session->results['script']['text']);

        // The scene rendered its narration and produced its optional image (stored under scene_plan.0).
        $scene = $session->results['scene_plan']['scenes'][0];
        $this->assertSame('Scene about Foxes', $scene['narration']);
        $this->assertSame('ok', $scene['image_status']);
        $this->assertSame('scene_plan.0', $scene['part_key']);
        $this->assertSame(8, $scene['image']['width']);
        $this->assertSame(1, $scene['image']['version']);
        $this->assertTrue(app(GeneratedImageStore::class)->exists($session->id, 'scene_plan.0', 1));
    }

    // ---- hardening (2c review follow-ups) --------------------------------------

    public function test_over_cap_ai_edits_are_skipped_so_the_image_part_still_produces_a_partially_filtered_image(): void
    {
        // GRACEFUL DEGRADATION (FIX A): a cap of 2 with THREE ai_edit filters — the 3rd is over budget, so it is
        // SKIPPED and the chain CONTINUES. The part is `ok` with the image the first two edits produced, instead
        // of losing the whole image over one refused decorative step. The budget is still hard: the provider is
        // called exactly twice, so no spend and no timeout risk is added.
        config()->set('generator.image_edit_max_calls_per_session', 2);
        Http::fake(['*/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($this->png(4, 4, [10, 20, 30]))]]], 200)]);

        $file = $this->diskImage(8, 8);

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot(
                'post_with_image',
                [
                    'body' => ['markdown' => 'Body'],
                    'image' => [
                        'base' => ['kind' => 'disk_file', 'file' => $file->id],
                        'filters' => [
                            ['kind' => 'ai_edit', 'prompt' => 'one'],
                            ['kind' => 'ai_edit', 'prompt' => 'two'],
                            ['kind' => 'ai_edit', 'prompt' => 'three'], // over the cap of 2
                        ],
                    ],
                ],
                [$this->topicSlot()],
                ['topic' => 'X'],
            )
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        // The run completes ready AND the image part succeeded — with the 3rd filter simply not applied.
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('ok', $session->results['image']['status']);
        $this->assertSame(4, $session->results['image']['image']['width']); // the 2nd edit's 4x4 result
        $this->assertSame(1, $session->results['image']['version']);
        $this->assertArrayNotHasKey('error', $session->results['image']);

        // The provider was invoked at most the cap (2) — never the 3rd (over-budget) time, so no timeout+bill.
        Http::assertSentCount(2);

        // The produced (partially filtered) blob IS stored and servable.
        $this->assertTrue(app(GeneratedImageStore::class)->exists($session->id, 'image', 1));
    }

    public function test_over_monthly_cap_ai_generate_base_fails_the_image_part_with_the_budget_message(): void
    {
        // The workspace is ALREADY over its monthly token cap, so the metered ai_generate base GATES BEFORE
        // SPEND — the provider is never reached. On the FULL RUN path this surfaces through executeImagePart's
        // AiBudgetExceededException catch as the BUDGET message, not the generic image_failed ("check its base
        // and filters") — an over-cap is a budget stop, not a bad base/filters. Mirrors the refine path.
        config()->set('ai.meter.monthly_cost_cap_default', 1.00);
        AiUsageEvent::create(['channel' => 'ai_image_generate', 'prompt_tokens' => 150, 'completion_tokens' => 0, 'total_tokens' => 150, 'estimated_cost' => 1.50]);

        Image::fake(); // the provider must never be reached (gate before spend)

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot(
                'post_with_image',
                [
                    'body' => ['markdown' => 'Body'],
                    'image' => ['base' => ['kind' => 'ai_generate', 'prompt' => 'A poster'], 'filters' => []],
                ],
                [$this->topicSlot()],
                ['topic' => 'X'],
            )
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('failed', $session->results['image']['status']);
        $this->assertSame(__('generator.sessions.image_budget'), $session->results['image']['error']);

        // Gate-before-spend: the provider was never reached and no image blob was stored for the failed part.
        Image::assertNothingGenerated();
        $this->assertFalse(app(GeneratedImageStore::class)->exists($session->id, 'image', 1));
    }

    public function test_over_monthly_cap_scene_image_fails_soft_with_the_budget_message(): void
    {
        // The scene_plan branch (renderSceneImage) carries the SAME over-cap → image_budget catch as the
        // top-level image part: an over-cap scene image fails soft with the budget message while the run
        // (and the scene's narration) still completes ready.
        config()->set('ai.meter.monthly_cost_cap_default', 1.00);
        AiUsageEvent::create(['channel' => 'ai_image_generate', 'prompt_tokens' => 150, 'completion_tokens' => 0, 'total_tokens' => 150, 'estimated_cost' => 1.50]);

        Image::fake(); // gate before spend — the provider is never reached

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot(
                'video_script',
                [
                    'script' => ['markdown' => 'A tale'],
                    'scene_plan' => ['scenes' => [
                        [
                            'narration' => ['markdown' => 'Scene one'],
                            'image_plan' => ['base' => ['kind' => 'ai_generate', 'prompt' => 'A shot'], 'filters' => []],
                        ],
                    ]],
                ],
                [$this->topicSlot()],
                ['topic' => 'X'],
            )
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);

        $scene = $session->results['scene_plan']['scenes'][0];
        $this->assertSame('Scene one', $scene['narration']);
        $this->assertSame('failed', $scene['image_status']);
        $this->assertSame(__('generator.sessions.image_budget'), $scene['image_error']);

        Image::assertNothingGenerated();
        $this->assertFalse(app(GeneratedImageStore::class)->exists($session->id, 'scene_plan.0', 1));
    }

    public function test_a_base_referencing_another_workspaces_file_fails_soft_and_reads_no_bytes(): void
    {
        // A Disk image that lives in ANOTHER workspace (B).
        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $other->users()->attach($this->user->id);

        app(TenantContext::class)->set($other);
        $foreignFile = $this->diskImage(8, 8);
        app(TenantContext::class)->set($this->workspace);

        // A workspace-A session whose image base points at B's file id.
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot(
                'post_with_image',
                [
                    'body' => ['markdown' => 'Body'],
                    'image' => ['base' => ['kind' => 'disk_file', 'file' => $foreignFile->id], 'filters' => []],
                ],
                [$this->topicSlot()],
                ['topic' => 'X'],
            )
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        // WorkspaceScope hides B's file from A's run, so the base is unavailable (never read across tenants):
        // the part fails soft, the run still completes ready, and no blob is produced. Pins the tenant gate
        // against a future withoutWorkspaceScope regression in ImageBaseResolver.
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('failed', $session->results['image']['status']);
        $this->assertSame(__('generator.sessions.image_base_unavailable'), $session->results['image']['error']);
        $this->assertFalse(app(GeneratedImageStore::class)->exists($session->id, 'image', 1));
    }

    public function test_a_re_run_clears_a_prior_blob_so_a_now_failed_part_no_longer_serves_it(): void
    {
        Queue::fake(); // capture the dispatch; run the worker manually below
        $file = $this->diskImage(8, 8);

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready) // a prior run already left it ready
            ->snapshot(
                'post_with_image',
                [
                    'body' => ['markdown' => 'Body'],
                    'image' => ['base' => ['kind' => 'disk_file', 'file' => $file->id], 'filters' => [['kind' => 'pixel', 'op' => 'grayscale']]],
                ],
                [$this->topicSlot()],
                ['topic' => 'X'],
            )
            ->create(['creator_id' => $this->user->id]);

        // Simulate the prior run's produced blob sitting in the store (version 1).
        $store = app(GeneratedImageStore::class);
        $store->storeVersion($session->id, 'image', 'STALE-PNG');
        $this->assertTrue($store->exists($session->id, 'image', 1));

        // The base blob is gone before the re-run, so the image part will now FAIL (base unavailable).
        Storage::delete($file->path);

        // Re-run: claiming clears the prior blob, then the worker runs the (now failing) plan.
        app(GenerationSessionRunManager::class)->claimAndDispatch($session);
        $this->runJob($session->refresh());

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('failed', $session->results['image']['status']);

        // The stale blob was cleared at claim and the failed re-run wrote none — the serve endpoint 404s.
        $this->assertFalse($store->exists($session->id, 'image', 1));
        $this->get("/api/generator/sessions/{$session->id}/parts/image/image")->assertNotFound();
    }
}
