<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Generator\Agents\ShotListAgent;
use App\Modules\Generator\Enums\GenerationRunMode;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Jobs\RenderStoryboardFrameJob;
use App\Modules\Generator\Jobs\RunGenerationSessionJob;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\GeneratedImageStore;
use App\Modules\Generator\Services\GenerationSessionRunManager;
use App\Modules\Generator\Services\StoryboardFrameManager;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Database\Factories\TemplateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickPixel;
use Laravel\Ai\Image;
use Tests\TestCase;

/**
 * The EXECUTOR-ITERATED STORYBOARD (video_script rework Phase B): one AI image PER shot of the shot_list,
 * NESTED under `storyboard.<i>` exactly like `scene_plan.<i>`. Pins the whole-run fan-out (reads the
 * in-progress shot_list shots → one versioned+served image per shot), per-shot FAIL-SOFT (one shot fails,
 * the others + the part stay ok; the generate budget bounds the fan-out), the per-shot REFINE loop
 * (regenerate / instructed refine / versioned undo on a `storyboard.<i>` sub-key, isolated + GC-correct), the
 * shot_list→storyboard STALENESS hook (a shot_list change marks the storyboard stale; a full generate clears
 * it; NO auto-regenerate), and the [script, scene_plan] snapshot BACK-COMPAT.
 */
class StoryboardTest extends TestCase
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

        Storage::fake();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers ---------------------------------------------------------------

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

    private function store(): GeneratedImageStore
    {
        return app(GeneratedImageStore::class);
    }

    /** The video_script snapshot content: a shot_list brief + a storyboard style/filters. */
    private function videoScriptContent(): array
    {
        return [
            'shot_list' => ['brief' => ['markdown' => 'A short video about ' . TemplateFactory::directive('slots.topic')]],
            'storyboard' => ['style' => ['markdown' => 'flat vector'], 'filters' => []],
        ];
    }

    /** A stored ok shot_list result carrying $count shots. */
    private function shotListResult(int $count, int $version = 1): array
    {
        $shots = [];
        for ($i = 0; $i < $count; $i++) {
            $shots[] = ['visual' => 'visual ' . $i, 'voiceover' => 'vo ' . $i, 'seconds' => 3];
        }

        return ['kind' => 'shot_list', 'status' => 'ok', 'hook' => 'H', 'shots' => $shots, 'cta' => 'C', 'text' => 'flat', 'parse_ok' => true, 'version' => $version];
    }

    /** A ready video_script session with a stored shot_list ($shots shots) AND a stored storyboard whose shots
     *  each have an image at version 1 (with the blob in the store). */
    private function readyStoryboardSession(int $shots): GenerationSession
    {
        $storyShots = [];
        for ($i = 0; $i < $shots; $i++) {
            $storyShots[] = [
                'index' => $i, 'visual' => 'visual ' . $i, 'voiceover' => 'vo ' . $i, 'seconds' => 3,
                'image_status' => 'ok', 'image' => ['mime' => 'image/png', 'width' => 8, 'height' => 8, 'version' => 1], 'part_key' => 'storyboard.' . $i,
            ];
        }

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot('video_script', $this->videoScriptContent(), [$this->topicSlot()], ['topic' => 'cats'])
            ->create([
                'creator_id' => $this->user->id,
                'results' => [
                    'shot_list' => $this->shotListResult($shots),
                    'storyboard' => ['kind' => 'storyboard', 'status' => 'ok', 'shots' => $storyShots],
                ],
            ]);

        for ($i = 0; $i < $shots; $i++) {
            $this->store()->storeVersion($session->id, 'storyboard.' . $i, $this->png(8, 8, [$i, $i, $i]));
        }

        return $session;
    }

    private function runJob(GenerationSession $session): void
    {
        (new RunGenerationSessionJob($session->id, $this->workspace->id))->handle(app(GenerationSessionRunManager::class));
    }

    private function claimAndRun(GenerationSession $session, GenerationRunMode $mode, ?string $partKey = null, ?string $instruction = null): GenerationSession
    {
        Queue::fake();
        app(GenerationSessionRunManager::class)->claimAndDispatch($session, $mode, $partKey, $instruction);

        (new RunGenerationSessionJob($session->id, $this->workspace->id, $mode->value, $partKey, $instruction))
            ->handle(app(GenerationSessionRunManager::class));

        $this->runDispatchedFrames();

        return $session->fresh();
    }

    /**
     * Run whatever storyboard FRAME jobs the run just queued. Storyboard images render in their own jobs now
     * (they cannot fit the run job's window), and this helper FAKES the queue to keep the run job from
     * self-dispatching — which captures the frame jobs too. Draining them here keeps these cases asserting
     * the same end state they always did: a settled run with real images.
     */
    private function runDispatchedFrames(): void
    {
        foreach (Queue::pushed(RenderStoryboardFrameJob::class) as $job) {
            $job->handle(app(StoryboardFrameManager::class), app(GenerationSessionRunManager::class));
        }
    }

    // ---- whole-run fan-out -----------------------------------------------------

    public function test_a_whole_run_renders_one_versioned_served_image_per_shot(): void
    {
        ShotListAgent::fake(fn () => json_encode([
            'hook' => 'H',
            'shots' => [
                ['visual' => 'a cat', 'voiceover' => 'v0', 'seconds' => 2],
                ['visual' => 'a dog', 'voiceover' => 'v1', 'seconds' => 3],
            ],
            'cta' => 'C',
        ]));
        Image::fake([base64_encode($this->png(6, 6, [10, 20, 30])), base64_encode($this->png(7, 7, [40, 50, 60]))]);

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('video_script', $this->videoScriptContent(), [$this->topicSlot()], ['topic' => 'pets'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);

        // The storyboard read the in-progress shot_list's 2 shots and produced one image per shot.
        $storyboard = $session->results['storyboard'];
        $this->assertSame('ok', $storyboard['status']);
        $this->assertCount(2, $storyboard['shots']);
        $this->assertSame('ok', $storyboard['shots'][0]['image_status']);
        $this->assertSame('storyboard.0', $storyboard['shots'][0]['part_key']);
        $this->assertSame(6, $storyboard['shots'][0]['image']['width']);
        $this->assertSame(1, $storyboard['shots'][0]['image']['version']);
        $this->assertSame(7, $storyboard['shots'][1]['image']['width']);

        // Both nested blobs are stored + served.
        $this->assertTrue($this->store()->exists($session->id, 'storyboard.0', 1));
        $this->assertTrue($this->store()->exists($session->id, 'storyboard.1', 1));
        $this->get("/api/generator/sessions/{$session->id}/parts/storyboard.0/image")->assertOk();
        $this->get("/api/generator/sessions/{$session->id}/parts/storyboard.1/image")->assertOk();
    }

    public function test_an_empty_or_missing_shot_list_yields_a_storyboard_with_no_shots(): void
    {
        ShotListAgent::fake(fn () => '');  // the shot_list fails soft → no shots for the storyboard

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('video_script', $this->videoScriptContent(), [$this->topicSlot()], ['topic' => 'x'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('failed', $session->results['shot_list']['status']);
        $this->assertSame('ok', $session->results['storyboard']['status']);
        $this->assertSame([], $session->results['storyboard']['shots']);
    }

    public function test_one_failing_shot_image_does_not_sink_the_others_and_the_budget_bounds_the_fan_out(): void
    {
        // A generate budget of 1 with a 2-shot storyboard: shot 0 generates ok, shot 1 is over budget and fails
        // soft — the part itself stays ok and shot 0 is intact (per-shot fail-soft).
        config()->set('generator.image_generate_max_calls_per_session', 1);

        ShotListAgent::fake(fn () => json_encode([
            'hook' => 'H',
            'shots' => [['visual' => 'one', 'voiceover' => 'v0', 'seconds' => 2], ['visual' => 'two', 'voiceover' => 'v1', 'seconds' => 2]],
            'cta' => 'C',
        ]));
        Image::fake([base64_encode($this->png(6, 6, [10, 20, 30]))]); // only the first generate is reached

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('video_script', $this->videoScriptContent(), [$this->topicSlot()], ['topic' => 'x'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $storyboard = $session->results['storyboard'];
        $this->assertSame('ok', $storyboard['status']);
        $this->assertSame('ok', $storyboard['shots'][0]['image_status']);
        $this->assertSame('failed', $storyboard['shots'][1]['image_status']);
        $this->assertSame(__('generator.sessions.image_generate_budget'), $storyboard['shots'][1]['image_error']);

        // Only the first shot's blob exists (the over-budget generate never spent).
        $this->assertTrue($this->store()->exists($session->id, 'storyboard.0', 1));
        $this->assertFalse($this->store()->exists($session->id, 'storyboard.1', 1));
        Image::assertGenerated(fn () => true);
    }

    public function test_an_exhausted_edit_budget_degrades_the_later_shots_to_unfiltered_images_instead_of_failing_them(): void
    {
        // FIX A. An authored storyboard filter chain applies to EVERY shot, and ImageChainExecutor holds ONE
        // cumulative `ai_edit` counter for the whole run — so a single `ai_edit` filter used to mark every shot
        // past the budget `failed`, destroying most of the set over a decorative step. Now the over-budget
        // filter is SKIPPED per shot: every frame still comes back `ok`, the later ones merely unfiltered.
        config()->set('generator.image_edit_max_calls_per_session', 1);
        config()->set('generator.image_generate_max_calls_per_session', 8);

        ShotListAgent::fake(fn () => json_encode([
            'hook' => 'H',
            'shots' => [
                ['visual' => 'one', 'voiceover' => 'v0', 'seconds' => 3],
                ['visual' => 'two', 'voiceover' => 'v1', 'seconds' => 3],
                ['visual' => 'three', 'voiceover' => 'v2', 'seconds' => 3],
            ],
            'cta' => 'C',
        ]));
        // Distinct base sizes per shot; the ai_edit provider answers with a 4x4, so the produced width tells us
        // whether the filter ran (4) or was skipped (the shot's own base size).
        Image::fake([
            base64_encode($this->png(6, 6, [1, 2, 3])),
            base64_encode($this->png(7, 7, [4, 5, 6])),
            base64_encode($this->png(9, 9, [7, 8, 9])),
        ]);
        Http::fake(['*/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($this->png(4, 4, [10, 20, 30]))]]], 200)]);

        $content = $this->videoScriptContent();
        $content['storyboard']['filters'] = [['kind' => 'ai_edit', 'prompt' => 'add film grain']];

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('video_script', $content, [$this->topicSlot()], ['topic' => 'x'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);

        $shots = $session->results['storyboard']['shots'];
        $this->assertCount(3, $shots);

        // EVERY frame is ok with a stored, servable image — none was destroyed by the exhausted budget.
        foreach ($shots as $i => $shot) {
            $this->assertSame('ok', $shot['image_status'], "shot {$i} must keep its image");
            $this->assertArrayNotHasKey('image_error', $shot);
            $this->assertTrue($this->store()->exists($session->id, 'storyboard.' . $i, 1));
        }

        // Shot 0 spent the one edit (4x4 provider result); shots 1-2 kept their unfiltered bases (7x7 / 9x9).
        $this->assertSame(4, $shots[0]['image']['width']);
        $this->assertSame(7, $shots[1]['image']['width']);
        $this->assertSame(9, $shots[2]['image']['width']);

        // The budget is still HARD: exactly ONE edit call reached the provider, no matter how many shots.
        Http::assertSentCount(1);
    }

    public function test_an_authored_max_shots_tightens_the_ceiling_for_both_the_list_and_the_fan_out(): void
    {
        // ADAPTIVE CAP (B1.5): the run's effective cap is min(authored content.storyboard.max_shots, the
        // platform ceiling) — ONE value that clamps the parsed list AND the storyboard iteration, so the two
        // cannot drift and the shot list is WRITTEN for the authored count rather than truncated to it.
        config()->set('generator.storyboard_max_shots', 8);
        config()->set('generator.image_generate_max_calls_per_session', 8);

        ShotListAgent::fake(fn () => json_encode([
            'hook' => 'H',
            'shots' => [
                ['visual' => 'one', 'voiceover' => 'v0', 'seconds' => 5],
                ['visual' => 'two', 'voiceover' => 'v1', 'seconds' => 5],
                ['visual' => 'three', 'voiceover' => 'v2', 'seconds' => 5],
                ['visual' => 'four', 'voiceover' => 'v3', 'seconds' => 5],
            ],
            'cta' => 'C',
        ]));
        Image::fake([base64_encode($this->png(6, 6, [1, 2, 3])), base64_encode($this->png(6, 6, [4, 5, 6]))]);

        $content = $this->videoScriptContent();
        $content['storyboard']['max_shots'] = 2;

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('video_script', $content, [$this->topicSlot()], ['topic' => 'x'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        $this->assertCount(2, $session->results['shot_list']['shots']);
        $this->assertCount(2, $session->results['storyboard']['shots']);
    }

    public function test_the_platform_ceiling_still_bounds_an_over_ceiling_snapshot_value(): void
    {
        // Defense in depth: the write validator refuses an over-ceiling max_shots, but an OLD snapshot (or a
        // lowered ceiling) must still be bounded at RUN time — the effective cap is a min(), never a max().
        config()->set('generator.storyboard_max_shots', 1);
        config()->set('generator.image_generate_max_calls_per_session', 8);

        ShotListAgent::fake(fn () => json_encode([
            'hook' => 'H',
            'shots' => [['visual' => 'one', 'voiceover' => 'v0', 'seconds' => 5], ['visual' => 'two', 'voiceover' => 'v1', 'seconds' => 5]],
            'cta' => 'C',
        ]));
        Image::fake([base64_encode($this->png(6, 6, [1, 2, 3]))]);

        $content = $this->videoScriptContent();
        $content['storyboard']['max_shots'] = 99;

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('video_script', $content, [$this->topicSlot()], ['topic' => 'x'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        $this->assertCount(1, $session->results['shot_list']['shots']);
        $this->assertCount(1, $session->results['storyboard']['shots']);
    }

    public function test_every_frame_prompt_leads_with_the_continuity_clause(): void
    {
        // B1.3: the frames are N independent text→image calls, so each prompt states that it belongs to ONE
        // production — the baseline fix for "five shots that look like five different films" (the derived
        // visual anchor on top of it is the direction layer's job).
        ShotListAgent::fake(fn () => json_encode([
            'hook' => 'H',
            'shots' => [['visual' => 'a cat', 'voiceover' => 'v0', 'seconds' => 2], ['visual' => 'a dog', 'voiceover' => 'v1', 'seconds' => 2]],
            'cta' => 'C',
        ]));

        $prompts = [];
        Image::fake(function (\Laravel\Ai\Prompts\ImagePrompt $prompt) use (&$prompts): string {
            $prompts[] = $prompt->prompt;

            return base64_encode($this->png(6, 6, [1, 2, 3]));
        });

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('video_script', $this->videoScriptContent(), [$this->topicSlot()], ['topic' => 'pets'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $this->assertSame([
            "Frame 1 of 2 from the SAME film/production — consistent world, palette, medium, lighting and subject across all frames.\n\nflat vector\n\na cat",
            "Frame 2 of 2 from the SAME film/production — consistent world, palette, medium, lighting and subject across all frames.\n\nflat vector\n\na dog",
        ], $prompts);
    }

    public function test_the_parsed_shot_list_is_clamped_to_the_storyboard_max(): void
    {
        config()->set('generator.storyboard_max_shots', 1);
        config()->set('generator.image_generate_max_calls_per_session', 5);

        ShotListAgent::fake(fn () => json_encode([
            'hook' => 'H',
            'shots' => [['visual' => 'one', 'voiceover' => 'v0', 'seconds' => 2], ['visual' => 'two', 'voiceover' => 'v1', 'seconds' => 2]],
            'cta' => 'C',
        ]));
        Image::fake([base64_encode($this->png(6, 6, [1, 2, 3]))]);

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('video_script', $this->videoScriptContent(), [$this->topicSlot()], ['topic' => 'x'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        // The shot list was clamped to 1 → the storyboard produced exactly one shot image.
        $this->assertCount(1, $session->results['shot_list']['shots']);
        $this->assertCount(1, $session->results['storyboard']['shots']);
    }

    // ---- per-shot regenerate ---------------------------------------------------

    public function test_regenerate_one_shot_re_images_only_that_shot_versions_it_and_leaves_the_others(): void
    {
        Image::fake([base64_encode($this->png(5, 5, [9, 9, 9]))]);

        $session = $this->readyStoryboardSession(2);
        $session = $this->claimAndRun($session, GenerationRunMode::Regenerate, 'storyboard.0');

        $this->assertSame(GenerationSessionStatus::Ready, $session->status);

        // Shot 0 re-imaged: new version 2 with the provider's 5×5, prior in history['storyboard.0'].
        $this->assertSame('ok', $session->results['storyboard']['shots'][0]['image_status']);
        $this->assertSame(2, $session->results['storyboard']['shots'][0]['image']['version']);
        $this->assertSame(5, $session->results['storyboard']['shots'][0]['image']['width']);
        $this->assertCount(1, $session->history['storyboard.0']);
        $this->assertSame(1, $session->history['storyboard.0'][0]['image']['version']);

        // Shot 1 untouched — still version 1, no history churn.
        $this->assertSame(1, $session->results['storyboard']['shots'][1]['image']['version']);
        $this->assertArrayNotHasKey('storyboard.1', $session->history ?? []);

        // Exactly one generate was made (only shot 0).
        Image::assertGenerated(fn () => true);
        $this->assertTrue($this->store()->exists($session->id, 'storyboard.0', 2));
        $this->assertTrue($this->store()->exists($session->id, 'storyboard.1', 1));
    }

    public function test_regenerate_an_out_of_range_shot_is_404(): void
    {
        $session = $this->readyStoryboardSession(2);

        $this->postJson("/api/generator/sessions/{$session->id}/parts/storyboard.5/regenerate")->assertNotFound();
    }

    // ---- per-shot refine (ai_edit) ---------------------------------------------

    public function test_refine_one_shot_ai_edits_only_that_shot_and_versions_it(): void
    {
        Http::fake(['*/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($this->png(4, 4, [7, 7, 7]))]]], 200)]);

        $session = $this->readyStoryboardSession(2);
        $session = $this->claimAndRun($session, GenerationRunMode::Refine, 'storyboard.1', 'make it pop');

        // Shot 1 ai_edited → new version 2; shot 0 untouched.
        $this->assertSame(2, $session->results['storyboard']['shots'][1]['image']['version']);
        $this->assertSame(4, $session->results['storyboard']['shots'][1]['image']['width']);
        $this->assertSame(1, $session->results['storyboard']['shots'][0]['image']['version']);
        $this->assertTrue($this->store()->exists($session->id, 'storyboard.1', 2));
    }

    public function test_refine_a_never_imaged_shot_is_a_refine_no_image_soft_no_op(): void
    {
        Http::fake();

        // A storyboard whose shot 0 was never imaged (image_status failed, no blob).
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot('video_script', $this->videoScriptContent(), [$this->topicSlot()], ['topic' => 'x'])
            ->create([
                'creator_id' => $this->user->id,
                'results' => [
                    'shot_list' => $this->shotListResult(1),
                    'storyboard' => ['kind' => 'storyboard', 'status' => 'ok', 'shots' => [
                        ['index' => 0, 'visual' => 'v0', 'voiceover' => 'vo0', 'seconds' => 3, 'image_status' => 'failed', 'image_error' => 'x'],
                    ]],
                ],
            ]);

        $session = $this->claimAndRun($session, GenerationRunMode::Refine, 'storyboard.0', 'make it pop');

        $this->assertSame('failed', $session->last_op_status);
        $this->assertSame(__('generator.sessions.refine_no_image'), $session->last_op_error);
        // The shot is unchanged (still failed, no image) — no clobber, no history churn.
        $this->assertSame('failed', $session->results['storyboard']['shots'][0]['image_status']);
        $this->assertArrayNotHasKey('storyboard.0', $session->history ?? []);
        Http::assertNothingSent();
    }

    // ---- per-shot undo ---------------------------------------------------------

    public function test_undo_one_shot_restores_its_prior_version_and_gcs_the_undone_blob(): void
    {
        Image::fake([base64_encode($this->png(5, 5, [9, 9, 9]))]);

        $session = $this->readyStoryboardSession(2);
        $session = $this->claimAndRun($session, GenerationRunMode::Regenerate, 'storyboard.0'); // v2, history=[v1]
        $this->assertTrue($this->store()->exists($session->id, 'storyboard.0', 2));

        $this->postJson("/api/generator/sessions/{$session->id}/parts/storyboard.0/undo")
            ->assertOk()
            ->assertJsonPath('data.results.storyboard.shots.0.image.version', 1);

        // The undone v2 blob is GC'd; v1 restored and served; shot 1 untouched.
        $session->refresh();
        $this->assertSame(1, $session->results['storyboard']['shots'][0]['image']['version']);
        $this->assertFalse($this->store()->exists($session->id, 'storyboard.0', 2));
        $this->assertTrue($this->store()->exists($session->id, 'storyboard.0', 1));
        $this->assertSame(1, $session->results['storyboard']['shots'][1]['image']['version']);
        $this->get("/api/generator/sessions/{$session->id}/parts/storyboard.0/image")->assertOk();
    }

    // ---- staleness -------------------------------------------------------------

    public function test_regenerating_the_shot_list_marks_the_storyboard_stale_without_regenerating_it(): void
    {
        ShotListAgent::fake(fn () => json_encode([
            'hook' => 'New hook',
            'shots' => [['visual' => 'new', 'voiceover' => 'v', 'seconds' => 2]],
            'cta' => 'C',
        ]));
        // No Image fake: a stale-mark must NOT trigger any storyboard generate (no auto-cascade).
        Image::fake();

        $session = $this->readyStoryboardSession(2);
        $session = $this->claimAndRun($session, GenerationRunMode::Regenerate, 'shot_list');

        // The storyboard (which reads the shot_list intra-composition) is now stale — but its shots are UNCHANGED
        // (no auto-regenerate; the images are still version 1).
        $this->assertTrue($session->results['storyboard']['stale']);
        $this->assertSame(1, $session->results['storyboard']['shots'][0]['image']['version']);
        Image::assertNothingGenerated();

        // The Resource emits the flag verbatim.
        $this->getJson("/api/generator/sessions/{$session->id}")
            ->assertJsonPath('data.results.storyboard.stale', true);
    }

    public function test_a_full_generate_clears_the_storyboard_staleness(): void
    {
        ShotListAgent::fake(fn () => json_encode([
            'hook' => 'H',
            'shots' => [['visual' => 'a', 'voiceover' => 'v', 'seconds' => 2]],
            'cta' => 'C',
        ]));
        Image::fake([base64_encode($this->png(6, 6, [1, 2, 3]))]);

        $session = $this->readyStoryboardSession(1);
        // Contrive a stale flag on the storyboard.
        $results = $session->results;
        $results['storyboard']['stale'] = true;
        $session->update(['results' => $results]);

        $session = $this->claimAndRun($session, GenerationRunMode::Full);

        // A full run rebuilds every part fresh → the stale flag is gone.
        $this->assertArrayNotHasKey('stale', $session->results['storyboard']);
    }

    // ---- serve / GC / back-compat ---------------------------------------------

    public function test_regenerating_the_whole_storyboard_gcs_the_history_capped_shot_blobs(): void
    {
        // A whole-storyboard regenerate pushes the PRIOR whole-storyboard result to history['storyboard']; on a
        // history cap of 1 the dropped oldest prior's NESTED shot blobs are GC'd (blobRefsOf storyboard branch).
        config()->set('generator.history_max_versions', 1);
        Image::fake([
            base64_encode($this->png(5, 5, [1, 1, 1])), base64_encode($this->png(5, 5, [2, 2, 2])), // run 1 → v2 each
            base64_encode($this->png(5, 5, [3, 3, 3])), base64_encode($this->png(5, 5, [4, 4, 4])), // run 2 → v3 each (v1 dropped)
        ]);

        $session = $this->readyStoryboardSession(2);
        $session = $this->claimAndRun($session, GenerationRunMode::Regenerate, 'storyboard'); // v2, history=[v1]
        $session = $this->claimAndRun($session, GenerationRunMode::Regenerate, 'storyboard'); // v3, history=[v2] (v1 dropped)

        $this->assertSame(3, $session->results['storyboard']['shots'][0]['image']['version']);

        // The dropped oldest prior's shot blobs (v1) were GC'd; the live set (v2 in history, v3 current) stays.
        $this->assertFalse($this->store()->exists($session->id, 'storyboard.0', 1));
        $this->assertFalse($this->store()->exists($session->id, 'storyboard.1', 1));
        $this->assertTrue($this->store()->exists($session->id, 'storyboard.0', 2));
        $this->assertTrue($this->store()->exists($session->id, 'storyboard.0', 3));
    }

    public function test_a_legacy_script_scene_plan_snapshot_still_renders_both_parts(): void
    {
        // BACK-COMPAT: an existing session snapshotted as [script, scene_plan] (before the Phase B recomposition)
        // still renders both parts — the executor is snapshot-authoritative, not registry-driven.
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('video_script', [
                'script' => ['markdown' => 'A tale of ' . TemplateFactory::directive('slots.topic')],
                'scene_plan' => ['scenes' => [['narration' => ['markdown' => 'Scene about ' . TemplateFactory::directive('slots.topic')]]]],
            ], [$this->topicSlot()], ['topic' => 'Foxes'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('A tale of Foxes', $session->results['script']['text']);
        $this->assertSame('Scene about Foxes', $session->results['scene_plan']['scenes'][0]['narration']);
        // The new parts are NOT spuriously added to a legacy snapshot.
        $this->assertArrayNotHasKey('shot_list', $session->results);
        $this->assertArrayNotHasKey('storyboard', $session->results);
    }
}
