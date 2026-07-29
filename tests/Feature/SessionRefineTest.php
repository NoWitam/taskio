<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Models\File;
use App\Modules\Generator\Enums\GenerationRunMode;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Jobs\RunGenerationSessionJob;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\GeneratedImageStore;
use App\Modules\Generator\Services\GenerationSessionRefiner;
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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The per-part REFINE LOOP (R2 sub-stage 2d): regenerate (a fresh snapshot variation) + instructed refine (a
 * revision of the current output — text via an AI text revision, image via an AI edit of the current image) +
 * versioned undo, all riding the SAME async claim/job/tenancy/meter machinery. Pins: version bumps + prior
 * pushed to bounded history, image blobs versioned (old kept until cap), the meter session-tag on a refine, the
 * revision prompt carrying the instruction + current text as DATA, undo restoring the prior version + deleting
 * the undone blob, the history cap GC, the version-aware serve, and the claim/409 + validation surface.
 *
 * Setup mirrors GenerationSessionGenerateTest: a real workspace + active tenancy so the real
 * LedgerMeteredAiCall records the rows the session-tag assertions read.
 */
class SessionRefineTest extends TestCase
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

        // Creative-direction layer OFF: these cases pin refine behavior that PREDATES it, and an un-scripted
        // derivation would be a REAL provider call. That a refine reuses the STORED direction and derives
        // NOTHING is pinned in CreativeDirectionTest.
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

    private function store(): GeneratedImageStore
    {
        return app(GeneratedImageStore::class);
    }

    /** A ready `post` session whose body has a current text at $version (its markdown may carry @[ai-text]). */
    private function readyTextSession(string $markdown, string $currentText, int $version = 1): GenerationSession
    {
        return GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot('post', ['body' => ['markdown' => $markdown]], [$this->topicSlot()], ['topic' => 'X'])
            ->create([
                'creator_id' => $this->user->id,
                'results' => ['body' => ['kind' => 'text_body', 'status' => 'ok', 'text' => $currentText, 'version' => $version]],
            ]);
    }

    /**
     * A ready `post_with_image` session: a plain body at version 1 AND an ok image at version 1 whose v1 blob
     * sits in the store. The image base is a real Disk file + a deterministic pixel grayscale (no AI on
     * regenerate).
     */
    private function readyImageSession(File $file): GenerationSession
    {
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot('post_with_image', [
                'body' => ['markdown' => 'Body'],
                'image' => ['base' => ['kind' => 'disk_file', 'file' => $file->id], 'filters' => [['kind' => 'pixel', 'op' => 'grayscale']]],
            ], [$this->topicSlot()], ['topic' => 'X'])
            ->create([
                'creator_id' => $this->user->id,
                'results' => [
                    'body' => ['kind' => 'text_body', 'status' => 'ok', 'text' => 'Body', 'version' => 1],
                    'image' => ['kind' => 'image_plan', 'status' => 'ok', 'image' => ['mime' => 'image/png', 'width' => 8, 'height' => 8, 'version' => 1], 'version' => 1],
                ],
            ]);

        $this->store()->storeVersion($session->id, 'image', $this->png(8, 8, [1, 2, 3]));

        return $session;
    }

    /** Claim a part op (the real, no-clear claim) and run its worker job — the async path minus the queue. */
    private function claimAndRun(GenerationSession $session, GenerationRunMode $mode, string $partKey, ?string $instruction = null): GenerationSession
    {
        Queue::fake();
        app(GenerationSessionRunManager::class)->claimAndDispatch($session, $mode, $partKey, $instruction);

        (new RunGenerationSessionJob($session->id, $this->workspace->id, $mode->value, $partKey, $instruction))
            ->handle(app(GenerationSessionRunManager::class));

        return $session->fresh();
    }

    // ---- regenerate ------------------------------------------------------------

    public function test_regenerate_text_bumps_version_pushes_prior_to_history_and_meters(): void
    {
        AiTextAgent::fake(fn (string $prompt) => 'FRESH VARIATION');

        $session = $this->readyTextSession('Post ' . $this->aiText('write'), 'ORIGINAL TEXT', 1);
        $session = $this->claimAndRun($session, GenerationRunMode::Regenerate, 'body');

        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('Post FRESH VARIATION', $session->results['body']['text']);
        $this->assertSame(2, $session->results['body']['version']);

        // The prior result is pushed to the part's history stack.
        $this->assertCount(1, $session->history['body']);
        $this->assertSame('ORIGINAL TEXT', $session->history['body'][0]['text']);
        $this->assertSame(1, $session->history['body'][0]['version']);

        // A NEW ai-text spend was recorded AND tagged with THIS session.
        $this->assertSame(1, AiUsageEvent::where('channel', 'ai_text')->where('session_id', $session->id)->count());
    }

    public function test_regenerate_image_versions_the_blob_keeps_the_old_and_leaves_other_parts_untouched(): void
    {
        $file = $this->diskImage(8, 8);
        $session = $this->readyImageSession($file);

        $session = $this->claimAndRun($session, GenerationRunMode::Regenerate, 'image');

        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('ok', $session->results['image']['status']);
        $this->assertSame(2, $session->results['image']['version']);
        $this->assertSame(2, $session->results['image']['image']['version']);

        // Old + new blobs both exist (the old is still referenced by history).
        $this->assertTrue($this->store()->exists($session->id, 'image', 1));
        $this->assertTrue($this->store()->exists($session->id, 'image', 2));

        // Prior pushed to history.
        $this->assertCount(1, $session->history['image']);
        $this->assertSame(1, $session->history['image'][0]['version']);

        // The OTHER part (body) was untouched — no re-render, no version bump, no history churn.
        $this->assertSame(1, $session->results['body']['version']);
        $this->assertSame('Body', $session->results['body']['text']);
        $this->assertArrayNotHasKey('body', $session->history ?? []);
    }

    // ---- refine ----------------------------------------------------------------

    public function test_refine_text_revises_the_current_output_with_the_instruction_and_current_as_data(): void
    {
        $captured = null;
        AiTextAgent::fake(function (string $prompt) use (&$captured) {
            $captured = $prompt;

            return 'SHORTENED POST';
        });

        $session = $this->readyTextSession('irrelevant markdown', 'A VERY LONG CURRENT POST', 1);
        $session = $this->claimAndRun($session, GenerationRunMode::Refine, 'body', 'shorten this');

        // The revision prompt embedded BOTH the instruction and the current text as DATA (the agent frames the
        // whole request as data-to-write-about; injection-hardened).
        $this->assertNotNull($captured);
        $this->assertStringContainsString('shorten this', $captured);
        $this->assertStringContainsString('A VERY LONG CURRENT POST', $captured);

        // The revised text is the new current; version bumped; prior in history.
        $this->assertSame('SHORTENED POST', $session->results['body']['text']);
        $this->assertSame(2, $session->results['body']['version']);
        $this->assertSame('A VERY LONG CURRENT POST', $session->history['body'][0]['text']);

        // A NEW ai-text spend, session-tagged (meter tag on a refine).
        $this->assertSame(1, AiUsageEvent::where('channel', 'ai_text')->where('session_id', $session->id)->count());
    }

    public function test_refine_image_ai_edits_the_current_image_meters_and_versions(): void
    {
        Http::fake(['*/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($this->png(4, 4, [9, 9, 9]))]]], 200)]);

        $file = $this->diskImage(8, 8);
        $session = $this->readyImageSession($file);

        $session = $this->claimAndRun($session, GenerationRunMode::Refine, 'image', 'make it pop');

        $this->assertSame('ok', $session->results['image']['status']);
        $this->assertSame(2, $session->results['image']['version']);
        // The provider returned a 4×4 image → that is the new produced version's meta.
        $this->assertSame(4, $session->results['image']['image']['width']);

        // Old + new versions both exist; prior in history.
        $this->assertTrue($this->store()->exists($session->id, 'image', 1));
        $this->assertTrue($this->store()->exists($session->id, 'image', 2));
        $this->assertCount(1, $session->history['image']);

        // The ai_image_edit spend was recorded AND session-tagged (meter tag through the image refine).
        $this->assertNotNull(
            AiUsageEvent::where('channel', 'ai_image_edit')->where('session_id', $session->id)->first(),
            'the ai_image_edit refine spend must be recorded and tagged with the session id',
        );
    }

    // ---- undo ------------------------------------------------------------------

    public function test_undo_restores_the_prior_text_flips_can_undo_off_and_409s_at_the_bottom(): void
    {
        AiTextAgent::fake(fn (string $prompt) => 'V2 TEXT');

        $session = $this->readyTextSession('Post ' . $this->aiText('x'), 'V1 TEXT', 1);
        $session = $this->claimAndRun($session, GenerationRunMode::Regenerate, 'body');
        $this->assertSame(2, $session->results['body']['version']);

        // Before undo: the resource exposes can_undo for the part.
        $this->getJson("/api/generator/sessions/{$session->id}")
            ->assertJsonPath('data.part_history.body.can_undo', true)
            ->assertJsonPath('data.part_history.body.undo_depth', 1);

        // Undo (synchronous) restores the prior version.
        $this->postJson("/api/generator/sessions/{$session->id}/parts/body/undo")
            ->assertOk()
            ->assertJsonPath('data.results.body.text', 'V1 TEXT')
            ->assertJsonPath('data.results.body.version', 1)
            ->assertJsonMissingPath('data.part_history.body');

        // Nothing left to undo → 409.
        $this->postJson("/api/generator/sessions/{$session->id}/parts/body/undo")
            ->assertStatus(409);
    }

    public function test_undo_image_restores_the_prior_version_and_deletes_the_undone_blob(): void
    {
        $file = $this->diskImage(8, 8);
        $session = $this->readyImageSession($file);
        $session = $this->claimAndRun($session, GenerationRunMode::Regenerate, 'image');

        $this->assertTrue($this->store()->exists($session->id, 'image', 2));

        $this->postJson("/api/generator/sessions/{$session->id}/parts/image/undo")
            ->assertOk()
            ->assertJsonPath('data.results.image.version', 1);

        // The just-undone (v2) blob is discarded; the restored v1 stays and the serve endpoint streams it.
        $this->assertFalse($this->store()->exists($session->id, 'image', 2));
        $this->assertTrue($this->store()->exists($session->id, 'image', 1));
        $this->get("/api/generator/sessions/{$session->id}/parts/image/image")->assertOk();
    }

    public function test_undo_while_generating_is_409(): void
    {
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->create([
                'creator_id' => $this->user->id,
                'history' => ['body' => [['kind' => 'text_body', 'status' => 'ok', 'text' => 'PRIOR', 'version' => 1]]],
            ]);

        $this->postJson("/api/generator/sessions/{$session->id}/parts/body/undo")->assertStatus(409);
    }

    // ---- history cap -----------------------------------------------------------

    public function test_history_cap_drops_the_oldest_prior_and_gcs_its_blob(): void
    {
        config()->set('generator.history_max_versions', 1);

        $file = $this->diskImage(8, 8);
        $session = $this->readyImageSession($file);
        $session = $this->claimAndRun($session, GenerationRunMode::Regenerate, 'image'); // v2, history=[v1]
        $session = $this->claimAndRun($session, GenerationRunMode::Regenerate, 'image'); // v3, history=[v2] (v1 dropped)

        $this->assertSame(3, $session->results['image']['version']);
        $this->assertCount(1, $session->history['image']);
        $this->assertSame(2, $session->history['image'][0]['version']);

        // The dropped oldest prior's blob (v1) was GC'd; the live set (v2 current-in-history, v3 current) stays.
        $this->assertFalse($this->store()->exists($session->id, 'image', 1));
        $this->assertTrue($this->store()->exists($session->id, 'image', 2));
        $this->assertTrue($this->store()->exists($session->id, 'image', 3));
    }

    // ---- version-aware serve ---------------------------------------------------

    public function test_serve_streams_the_current_version_not_an_older_one(): void
    {
        $session = GenerationSession::factory()->status(GenerationSessionStatus::Ready)->create(['creator_id' => $this->user->id]);
        $this->store()->storeVersion($session->id, 'image', 'OLD-V1'); // version 1
        $this->store()->storeVersion($session->id, 'image', 'NEW-V2'); // version 2
        $session->update(['results' => ['image' => [
            'kind' => 'image_plan', 'status' => 'ok',
            'image' => ['mime' => 'image/png', 'width' => 1, 'height' => 1, 'version' => 2],
            'version' => 2,
        ]]]);

        $response = $this->get("/api/generator/sessions/{$session->id}/parts/image/image")->assertOk();

        // The serve endpoint streams the CURRENT version (v2), never the older v1.
        $this->assertSame('NEW-V2', $response->streamedContent());
    }

    // ---- claim / 409 / no-clear invariants ------------------------------------

    public function test_a_regenerate_claim_flips_status_but_keeps_other_results_and_blobs(): void
    {
        Queue::fake();
        $file = $this->diskImage(8, 8);
        $session = $this->readyImageSession($file);

        app(GenerationSessionRunManager::class)->claimAndDispatch($session, GenerationRunMode::Regenerate, 'image');

        $fresh = $session->fresh();
        // A part op claims the whole session (→ generating) but must NOT clear results/blobs (unlike a full run).
        $this->assertSame(GenerationSessionStatus::Generating, $fresh->status);
        $this->assertSame('Body', $fresh->results['body']['text']);
        $this->assertTrue($this->store()->exists($session->id, 'image', 1));

        Queue::assertPushed(
            RunGenerationSessionJob::class,
            fn (RunGenerationSessionJob $job) => $job->mode === 'regenerate' && $job->partKey === 'image',
        );
    }

    public function test_a_full_generate_claim_clears_prior_results_and_history(): void
    {
        Queue::fake();
        $session = GenerationSession::factory()->status(GenerationSessionStatus::Ready)->create([
            'creator_id' => $this->user->id,
            'results' => ['body' => ['kind' => 'text_body', 'status' => 'ok', 'text' => 'OLD', 'version' => 3]],
            'history' => ['body' => [['kind' => 'text_body', 'status' => 'ok', 'text' => 'OLDER', 'version' => 2]]],
        ]);

        app(GenerationSessionRunManager::class)->claimAndDispatch($session);

        $fresh = $session->fresh();
        $this->assertNull($fresh->results);
        $this->assertNull($fresh->history);
        Queue::assertPushed(RunGenerationSessionJob::class, fn (RunGenerationSessionJob $job) => $job->mode === 'full');
    }

    public function test_a_part_op_while_generating_is_409(): void
    {
        Queue::fake();
        $session = GenerationSession::factory()->status(GenerationSessionStatus::Generating)->create(['creator_id' => $this->user->id]);

        $this->postJson("/api/generator/sessions/{$session->id}/parts/body/regenerate")->assertStatus(409);
        $this->postJson("/api/generator/sessions/{$session->id}/parts/body/refine", ['instruction' => 'x'])->assertStatus(409);

        Queue::assertNothingPushed();
    }

    // ---- endpoint surface ------------------------------------------------------

    public function test_regenerate_endpoint_claims_and_queues_a_regenerate_run(): void
    {
        Queue::fake();
        $session = GenerationSession::factory()->status(GenerationSessionStatus::Ready)->create(['creator_id' => $this->user->id]);

        $this->postJson("/api/generator/sessions/{$session->id}/parts/body/regenerate")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'generating');

        Queue::assertPushed(
            RunGenerationSessionJob::class,
            fn (RunGenerationSessionJob $job) => $job->mode === 'regenerate' && $job->partKey === 'body' && $job->workspaceId === $this->workspace->id,
        );
    }

    public function test_refine_endpoint_claims_and_queues_a_refine_run_with_the_instruction(): void
    {
        Queue::fake();
        $session = GenerationSession::factory()->status(GenerationSessionStatus::Ready)->create(['creator_id' => $this->user->id]);

        $this->postJson("/api/generator/sessions/{$session->id}/parts/body/refine", ['instruction' => '  shorten it  '])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'generating');

        Queue::assertPushed(
            RunGenerationSessionJob::class,
            fn (RunGenerationSessionJob $job) => $job->mode === 'refine' && $job->partKey === 'body' && $job->instruction === 'shorten it',
        );
    }

    // ---- validation ------------------------------------------------------------

    public function test_regenerate_on_an_unknown_part_is_404(): void
    {
        $session = GenerationSession::factory()->status(GenerationSessionStatus::Ready)->create(['creator_id' => $this->user->id]);

        $this->postJson("/api/generator/sessions/{$session->id}/parts/not_a_part/regenerate")->assertNotFound();
    }

    public function test_refine_with_a_blank_instruction_is_422(): void
    {
        $session = GenerationSession::factory()->status(GenerationSessionStatus::Ready)->create(['creator_id' => $this->user->id]);

        $this->postJson("/api/generator/sessions/{$session->id}/parts/body/refine", ['instruction' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('instruction');
    }

    public function test_refine_on_a_scene_plan_part_is_422_unsupported(): void
    {
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot('video_script', [
                'script' => ['markdown' => 'A script'],
                'scene_plan' => ['scenes' => []],
            ], [$this->topicSlot()], ['topic' => 'X'])
            ->create(['creator_id' => $this->user->id]);

        $this->postJson("/api/generator/sessions/{$session->id}/parts/scene_plan/refine", ['instruction' => 'do it'])
            ->assertStatus(422);
    }

    public function test_another_user_cannot_regenerate_or_undo_a_session(): void
    {
        $other = User::factory()->create();
        $this->workspace->users()->attach($other->id);
        $session = GenerationSession::factory()->status(GenerationSessionStatus::Ready)->create(['creator_id' => $this->user->id]);

        $this->actingAs($other)
            ->postJson("/api/generator/sessions/{$session->id}/parts/body/regenerate")
            ->assertForbidden();

        $this->actingAs($other)
            ->postJson("/api/generator/sessions/{$session->id}/parts/body/undo")
            ->assertForbidden();
    }

    // ---- failed-op surfacing + concurrency hardening (2d-core review) ----------

    public function test_a_failed_text_refine_preserves_the_current_text_and_surfaces_failed(): void
    {
        // The shared ai-text generator is FAIL-CLOSED: a provider error resolves to '' (never throws). A blank
        // revision must FAIL the op, not overwrite the good post with an empty string (C1 data-loss fix).
        AiTextAgent::fake(fn () => throw new \RuntimeException('provider down'));

        $session = $this->readyTextSession('irrelevant markdown', 'GOOD CURRENT POST', 1);
        $session = $this->claimAndRun($session, GenerationRunMode::Refine, 'body', 'shorten this');

        // The current text + version are UNCHANGED and history was NOT churned — the failed op no-op'd.
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('GOOD CURRENT POST', $session->results['body']['text']);
        $this->assertSame(1, $session->results['body']['version']);
        $this->assertArrayNotHasKey('body', $session->history ?? []);

        // ...but the outcome is SURFACED so the FE can toast the failure (A1).
        $this->assertSame('failed', $session->last_op_status);
        $this->assertSame(__('generator.sessions.part_failed'), $session->last_op_error);

        // The Resource carries the same wire shape the FE reads.
        $this->getJson("/api/generator/sessions/{$session->id}")
            ->assertJsonPath('data.last_op_status', 'failed')
            ->assertJsonPath('data.last_op_error', __('generator.sessions.part_failed'))
            ->assertJsonPath('data.results.body.text', 'GOOD CURRENT POST');
    }

    public function test_a_blank_revision_is_rejected_and_does_not_overwrite(): void
    {
        // Even a NON-error provider reply that is only whitespace must be rejected (empty-revision fail-closed):
        // it is never a desired refine result, so the current text is preserved and the op reported failed.
        AiTextAgent::fake(fn () => '   ');

        $session = $this->readyTextSession('irrelevant markdown', 'KEEP ME', 3);
        $session = $this->claimAndRun($session, GenerationRunMode::Refine, 'body', 'do something');

        $this->assertSame('KEEP ME', $session->results['body']['text']);
        $this->assertSame(3, $session->results['body']['version']);
        $this->assertArrayNotHasKey('body', $session->history ?? []);
        $this->assertSame('failed', $session->last_op_status);
        $this->assertSame(__('generator.sessions.part_failed'), $session->last_op_error);
    }

    public function test_a_failed_image_refine_over_cap_leaves_the_image_unchanged_and_surfaces_the_budget_message(): void
    {
        // MID-FLIGHT crossing (distinct from the PRE-RUN gate in SessionAiBudgetGateTest): the run is CLAIMED
        // while still UNDER the cap — so the pre-run gate allows it — and the cap is then crossed BEFORE the
        // worker reaches the image edit, so the metered ai_image_edit gate throws MID-RUN. The refine no-op's
        // (image unchanged) AND surfaces the BUDGET message (U1), not the misleading "check base and filters"
        // one. This pins that the mid-run fail-soft path is UNCHANGED by the pre-run gate.
        config()->set('ai.meter.monthly_cost_cap_default', 1.00);
        Http::fake();

        $file = $this->diskImage(8, 8);
        $session = $this->readyImageSession($file);

        // Claim under the cap (no spend yet → pre-run gate passes)...
        Queue::fake();
        app(GenerationSessionRunManager::class)->claimAndDispatch($session, GenerationRunMode::Refine, 'image', 'make it pop');

        // ...then the cap is crossed before the worker runs (a mid-flight over-cap the executor fails SOFT).
        AiUsageEvent::create(['channel' => 'ai_image_edit', 'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 150, 'estimated_cost' => 1.50]);

        (new RunGenerationSessionJob($session->id, $this->workspace->id, GenerationRunMode::Refine->value, 'image', 'make it pop'))
            ->handle(app(GenerationSessionRunManager::class));

        $session = $session->fresh();

        // The current image is UNCHANGED (still v1) — no clobber, no spurious v2 blob, no history churn.
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('ok', $session->results['image']['status']);
        $this->assertSame(1, $session->results['image']['version']);
        $this->assertTrue($this->store()->exists($session->id, 'image', 1));
        $this->assertFalse($this->store()->exists($session->id, 'image', 2));
        $this->assertArrayNotHasKey('image', $session->history ?? []);

        // The failure is surfaced as a BUDGET stop.
        $this->assertSame('failed', $session->last_op_status);
        $this->assertSame(__('generator.sessions.image_budget'), $session->last_op_error);

        Http::assertNothingSent();
    }

    public function test_undo_rereads_the_status_under_the_lock_and_does_not_apply_on_a_stale_ready_model(): void
    {
        // A2: undo re-reads the session UNDER a row lock before mutating. A stale in-memory model that still
        // believes the session is `ready` must NOT double-apply when the DB row has since flipped (a concurrent
        // claim won the race) — the fresh, locked read 409s and results + history stay intact.
        AiTextAgent::fake(fn () => 'V2 TEXT');

        $session = $this->readyTextSession('Post ' . $this->aiText('x'), 'V1 TEXT', 1);
        $session = $this->claimAndRun($session, GenerationRunMode::Regenerate, 'body'); // v2, history=[v1]

        $stale = GenerationSession::find($session->id); // still `ready` in memory
        GenerationSession::whereKey($session->id)->update(['status' => GenerationSessionStatus::Generating->value]);

        try {
            app(GenerationSessionRefiner::class)->undo($stale, 'body');
            $this->fail('undo must 409 when the fresh, locked row is generating');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        // No double-apply: the regenerate result + its history are untouched.
        $fresh = $session->fresh();
        $this->assertSame('Post V2 TEXT', $fresh->results['body']['text']);
        $this->assertSame(2, $fresh->results['body']['version']);
        $this->assertCount(1, $fresh->history['body']);
    }

    public function test_last_op_status_is_cleared_at_the_next_claim_then_restamped_by_the_run(): void
    {
        // A failed refine surfaces last_op_status:'failed'.
        AiTextAgent::fake(fn () => throw new \RuntimeException('provider down'));
        $session = $this->readyTextSession('Post ' . $this->aiText('x'), 'GOOD TEXT', 1);
        $session = $this->claimAndRun($session, GenerationRunMode::Refine, 'body', 'shorten');
        $this->assertSame('failed', $session->last_op_status);

        // The NEXT claim CLEARS the transient signal FIRST (strictly per-op, never stale) — before the run
        // writes the new outcome.
        Queue::fake();
        app(GenerationSessionRunManager::class)->claimAndDispatch($session->fresh(), GenerationRunMode::Regenerate, 'body');
        $cleared = $session->fresh();
        $this->assertNull($cleared->last_op_status);
        $this->assertNull($cleared->last_op_error);

        // Running the (successful) regenerate restamps last_op_status:'ok' with no error.
        AiTextAgent::fake(fn () => 'FRESH');
        (new RunGenerationSessionJob($session->id, $this->workspace->id, 'regenerate', 'body', null))
            ->handle(app(GenerationSessionRunManager::class));
        $ok = $session->fresh();
        $this->assertSame('ok', $ok->last_op_status);
        $this->assertNull($ok->last_op_error);

        // A full-generate claim also clears it (never leaks a prior part-op outcome onto a whole run).
        app(GenerationSessionRunManager::class)->claimAndDispatch($session->fresh());
        $this->assertNull($session->fresh()->last_op_status);
    }
}
