<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Enums\DiskAiEditStatus;
use App\Modules\Disk\Jobs\EditDiskImageJob;
use App\Modules\Disk\Models\DiskAiEdit;
use App\Modules\Disk\Services\ImageAiService;
use App\Modules\Variables\Models\AiUsageEvent;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * The ASYNC AI image-edit flow (F2-2): POST /disk/ai/image queues an edit and returns a status id
 * (202); a worker runs the provider call (the custom OpenAiImageEditClient over OpenAI
 * images/edits, faked here via Http::fake()); GET /disk/ai/image/{id} polls until done/failed.
 * A `disk:reap-stale-ai-edits` reaper recovers edits stranded by a dead worker and prunes old rows.
 */
class DiskAiImageTest extends TestCase
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

        // The dispatch persists the upload; the worker reads it back. A fake disk gives every test
        // an isolated blob store.
        Storage::fake();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function fakeEdited(string $bytes = 'EDITED'): void
    {
        Http::fake([
            '*/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($bytes)]]], 200),
        ]);
    }

    // ---- Dispatch --------------------------------------------------------------------

    public function test_dispatch_queues_a_job_and_persists_the_upload(): void
    {
        Queue::fake();

        $this->postJson('/api/disk/ai/image', [
            'image' => UploadedFile::fake()->image('canvas.png'),
            'prompt' => 'Remove the background',
        ])
            ->assertStatus(202)
            ->assertJsonStructure(['data' => ['id', 'status']])
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonMissingPath('data.image');

        $edit = DiskAiEdit::sole();
        $this->assertSame(DiskAiEditStatus::Queued, $edit->status);
        $this->assertSame('Remove the background', $edit->prompt);
        $this->assertFalse($edit->has_mask);
        $this->assertSame($this->workspace->id, $edit->workspace_id);
        $this->assertNotNull($edit->input_image_path);
        Storage::assertExists($edit->input_image_path);

        Queue::assertPushed(
            EditDiskImageJob::class,
            fn (EditDiskImageJob $job) => $job->editId === $edit->id
                && $job->workspaceId === $this->workspace->id,
        );
    }

    public function test_dispatch_persists_the_mask_for_an_inpainting_edit(): void
    {
        Queue::fake();

        $this->postJson('/api/disk/ai/image', [
            'image' => UploadedFile::fake()->image('canvas.png'),
            'mask' => UploadedFile::fake()->image('mask.png'),
            'prompt' => 'Remove the person',
        ])->assertStatus(202);

        $edit = DiskAiEdit::sole();
        $this->assertTrue($edit->has_mask);
        $this->assertNotNull($edit->input_mask_path);
        Storage::assertExists($edit->input_mask_path);
    }

    public function test_the_daily_budget_is_enforced_at_dispatch(): void
    {
        config()->set('ai.disk_image_max_per_day', 1);
        Queue::fake();

        // The first dispatch consumes the workspace's daily budget immediately (counted at dispatch,
        // not on job success) — so it holds even though the job has not run.
        $this->postJson('/api/disk/ai/image', [
            'image' => UploadedFile::fake()->image('canvas.png'),
            'prompt' => 'x',
        ])->assertStatus(202);

        // The second is refused (429): no row created, no job queued.
        $this->postJson('/api/disk/ai/image', [
            'image' => UploadedFile::fake()->image('canvas.png'),
            'prompt' => 'y',
        ])->assertStatus(429);

        $this->assertSame(1, DiskAiEdit::count());
        Queue::assertPushed(EditDiskImageJob::class, 1);
    }

    public function test_it_validates_the_image_prompt_and_mask(): void
    {
        Queue::fake();

        $this->postJson('/api/disk/ai/image', [
            'image' => UploadedFile::fake()->image('canvas.png'),
        ])->assertStatus(422)->assertJsonValidationErrors('prompt');

        $this->postJson('/api/disk/ai/image', [
            'image' => UploadedFile::fake()->create('not-an-image.txt', 10),
            'prompt' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors('image');

        // A mask must be PNG (it carries the alpha channel) — a jpeg mask is rejected.
        $this->postJson('/api/disk/ai/image', [
            'image' => UploadedFile::fake()->image('canvas.png'),
            'mask' => UploadedFile::fake()->image('mask.jpg'),
            'prompt' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors('mask');

        Queue::assertNothingPushed();
    }

    // ---- Worker (the job) ------------------------------------------------------------

    public function test_the_job_processes_a_queued_edit_to_done_and_deletes_inputs(): void
    {
        Queue::fake(); // create the row + inputs without the sync queue auto-running the job
        $this->fakeEdited();

        $this->postJson('/api/disk/ai/image', [
            'image' => UploadedFile::fake()->image('canvas.png'),
            'mask' => UploadedFile::fake()->image('mask.png'),
            'prompt' => 'Remove the person',
        ])->assertStatus(202);

        $edit = DiskAiEdit::sole();
        $imagePath = $edit->input_image_path;
        $maskPath = $edit->input_mask_path;

        (new EditDiskImageJob($edit->id, $this->workspace->id))->handle(app(ImageAiService::class));

        $edit->refresh();
        $this->assertSame(DiskAiEditStatus::Done, $edit->status);
        $this->assertSame(base64_encode('EDITED'), $edit->result_image);
        // Inputs are dropped once processed (row + blobs).
        $this->assertNull($edit->input_image_path);
        $this->assertNull($edit->input_mask_path);
        Storage::assertMissing($imagePath);
        Storage::assertMissing($maskPath);

        // The provider got a multipart images/edits call carrying the image + prompt + mask.
        // `input_fidelity` is PINNED: it is what keeps the source's faces/detail, it was documented
        // but silently absent from the payload once, and nothing else would catch it going missing.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/images/edits')
            && $request->isMultipart()
            && str_contains($request->body(), 'name="image"')
            && str_contains($request->body(), 'name="mask"')
            && str_contains($request->body(), 'name="input_fidelity"')
            && str_contains($request->body(), 'Remove the person'));
    }

    public function test_a_provider_failure_marks_the_edit_failed_and_deletes_inputs(): void
    {
        Queue::fake();
        Http::fake(['*/images/edits' => Http::response(['error' => 'boom'], 500)]);

        $this->postJson('/api/disk/ai/image', [
            'image' => UploadedFile::fake()->image('canvas.png'),
            'prompt' => 'Enhance',
        ])->assertStatus(202);

        $edit = DiskAiEdit::sole();
        $imagePath = $edit->input_image_path;

        $job = new EditDiskImageJob($edit->id, $this->workspace->id);

        // The provider failure propagates out of handle() (so the queue would retry / eventually
        // fail); the terminal failed() hook then records it and cleans up.
        $this->assertThrows(
            fn () => $job->handle(app(ImageAiService::class)),
            RuntimeException::class,
        );
        $job->failed(new RuntimeException('retries exhausted'));

        $edit->refresh();
        $this->assertSame(DiskAiEditStatus::Failed, $edit->status);
        // A localized, non-secret message — never the raw provider body.
        $this->assertSame(__('disk.ai.failed'), $edit->error);
        $this->assertNull($edit->input_image_path);
        Storage::assertMissing($imagePath);
    }

    public function test_an_over_cap_edit_fails_fast_in_the_worker_without_retrying(): void
    {
        // Contrast with the test ABOVE: a provider/transport error propagates so the queue RETRIES.
        // An over-cap token budget can never clear within the retry window, so the worker fails the
        // edit FAST — handle() does NOT rethrow (no burned retries) and the provider is never called.
        // The over-cap gate lives in the shared Variables cost meter.
        config()->set('ai.meter.monthly_cost_cap_default', 1.00);
        AiUsageEvent::create([
            'channel' => 'ai_image_edit',
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 150,
            'estimated_cost' => 1.50, // already over the $ cap for this calendar month
        ]);
        Http::fake(); // prove the provider transport is never touched

        $edit = DiskAiEdit::create([
            'status' => DiskAiEditStatus::Queued,
            'prompt' => 'Remove the background',
            'input_image_path' => 'disk-ai/' . $this->workspace->id . '/over-cap/image.png',
        ]);
        Storage::put($edit->input_image_path, 'PNGBYTES');

        // handle() returns normally (no thrown exception) — so the queue does NOT retry this edit.
        (new EditDiskImageJob($edit->id, $this->workspace->id))->handle(app(ImageAiService::class));

        $edit->refresh();
        $this->assertSame(DiskAiEditStatus::Failed, $edit->status);
        $this->assertSame(__('disk.ai.budget'), $edit->error);
        $this->assertNull($edit->input_image_path); // inputs cleaned up by the fail() path
        Http::assertNothingSent();
    }

    // ---- Poll ------------------------------------------------------------------------

    public function test_polling_returns_status_only_while_pending(): void
    {
        $edit = DiskAiEdit::create([
            'status' => DiskAiEditStatus::Processing,
            'prompt' => 'x',
        ]);

        $this->getJson('/api/disk/ai/image/' . $edit->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonMissingPath('data.image')
            ->assertJsonMissingPath('data.error');
    }

    public function test_polling_returns_the_image_when_done(): void
    {
        $edit = DiskAiEdit::create([
            'status' => DiskAiEditStatus::Done,
            'prompt' => 'x',
            'result_image' => base64_encode('EDITED'),
        ]);

        $this->getJson('/api/disk/ai/image/' . $edit->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.image', base64_encode('EDITED'))
            ->assertJsonPath('data.mime', 'image/png');
    }

    public function test_polling_a_failed_edit_returns_the_error_not_the_image(): void
    {
        $edit = DiskAiEdit::create([
            'status' => DiskAiEditStatus::Failed,
            'prompt' => 'x',
            'error' => __('disk.ai.failed'),
        ]);

        $this->getJson('/api/disk/ai/image/' . $edit->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', __('disk.ai.failed'))
            ->assertJsonMissingPath('data.image');
    }

    public function test_polling_cannot_reach_another_workspaces_edit(): void
    {
        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $other->users()->attach($this->user->id);

        // Create the edit under the OTHER workspace's tenancy, then restore ours.
        app(TenantContext::class)->set($other);
        $foreign = DiskAiEdit::create([
            'status' => DiskAiEditStatus::Done,
            'prompt' => 'x',
            'result_image' => base64_encode('SECRET'),
        ]);
        app(TenantContext::class)->set($this->workspace);

        // Route-model binding is tenant-scoped, so a foreign id never resolves.
        $this->getJson('/api/disk/ai/image/' . $foreign->id)->assertNotFound();
    }

    public function test_polling_requires_an_active_workspace(): void
    {
        $edit = DiskAiEdit::create([
            'status' => DiskAiEditStatus::Queued,
            'prompt' => 'x',
        ]);

        $this->flushHeaders();

        $this->actingAs($this->user)
            ->getJson('/api/disk/ai/image/' . $edit->id)
            ->assertStatus(400); // RequireWorkspace refuses (runs before the binding resolves)
    }

    // ---- Reaper ----------------------------------------------------------------------

    public function test_the_reaper_fails_edits_stuck_past_the_timeout(): void
    {
        config()->set('ai.disk_image_edit_timeout', 900);

        $stale = DiskAiEdit::create([
            'status' => DiskAiEditStatus::Processing,
            'prompt' => 'x',
            'input_image_path' => 'disk-ai/' . $this->workspace->id . '/stale/image.png',
        ]);
        Storage::put($stale->input_image_path, 'bytes');
        // Age it past the timeout (a raw update leaves updated_at exactly as given).
        DiskAiEdit::where('id', $stale->id)->update(['updated_at' => now()->subHour()]);

        $fresh = DiskAiEdit::create([
            'status' => DiskAiEditStatus::Processing,
            'prompt' => 'y',
        ]);

        $this->artisan('disk:reap-stale-ai-edits')
            ->expectsOutputToContain('Reaped 1 stale AI edit(s)')
            ->assertSuccessful();

        $stale->refresh();
        $this->assertSame(DiskAiEditStatus::Failed, $stale->status);
        $this->assertSame(__('disk.ai.failed'), $stale->error);
        Storage::assertMissing('disk-ai/' . $this->workspace->id . '/stale/image.png');

        // A fresh in-flight edit is untouched.
        $this->assertSame(DiskAiEditStatus::Processing, $fresh->fresh()->status);
    }

    public function test_the_reaper_prunes_old_terminal_edits(): void
    {
        config()->set('ai.disk_image_edit_retention', 3600);

        $old = DiskAiEdit::create([
            'status' => DiskAiEditStatus::Done,
            'prompt' => 'x',
            'result_image' => base64_encode('EDITED'),
        ]);
        DiskAiEdit::where('id', $old->id)->update(['updated_at' => now()->subHours(2)]);

        $recent = DiskAiEdit::create([
            'status' => DiskAiEditStatus::Done,
            'prompt' => 'y',
            'result_image' => base64_encode('EDITED'),
        ]);

        $this->artisan('disk:reap-stale-ai-edits')->assertSuccessful();

        $this->assertNull(DiskAiEdit::find($old->id));
        $this->assertNotNull(DiskAiEdit::find($recent->id));
    }
}
