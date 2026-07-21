<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Enums\DiskAiEditStatus;
use App\Modules\Disk\Events\DiskAiEditUpdated;
use App\Modules\Disk\Jobs\EditDiskImageJob;
use App\Modules\Disk\Models\DiskAiEdit;
use App\Modules\Disk\Services\ImageAiService;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * The PUSH half of the async Disk AI image edit: when an edit reaches a terminal state the worker
 * broadcasts a LIGHTWEIGHT {@see DiskAiEditUpdated} ({ id, status, error? }) on the per-workspace
 * private channel `disk-ai.workspace.{workspaceId}`, so the browser can stop polling and fetch the
 * image via the (retained) GET /disk/ai/image/{id} endpoint. The multi-MB base64 result is NEVER on
 * the wire. The channel is authorized by CENTRAL workspace membership (routes/channels.php).
 */
class DiskAiBroadcastTest extends TestCase
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
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function fakeEdited(string $bytes = 'EDITED'): void
    {
        Http::fake([
            '*/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($bytes)]]], 200),
        ]);
    }

    /** A queued edit with its input image persisted on the fake disk — ready for the worker. */
    private function queuedEditWithInput(string $prompt = 'Enhance'): DiskAiEdit
    {
        $edit = DiskAiEdit::create([
            'status' => DiskAiEditStatus::Queued,
            'prompt' => $prompt,
        ]);

        $path = 'disk-ai/' . $this->workspace->id . '/' . $edit->id . '/image.png';
        Storage::put($path, 'PNGBYTES');
        $edit->update(['input_image_path' => $path]);

        return $edit;
    }

    // ---- Terminal-status pushes ------------------------------------------------------

    public function test_processing_an_edit_to_done_broadcasts_a_lightweight_status_push(): void
    {
        Event::fake([DiskAiEditUpdated::class]);
        $this->fakeEdited();

        $edit = $this->queuedEditWithInput();

        (new EditDiskImageJob($edit->id, $this->workspace->id))->handle(app(ImageAiService::class));

        $this->assertSame(DiskAiEditStatus::Done, $edit->fresh()->status);

        Event::assertDispatched(DiskAiEditUpdated::class, function (DiskAiEditUpdated $event) use ($edit) {
            return $event->workspaceId === $this->workspace->id
                && $event->editId === $edit->id
                && $event->status === 'done'
                && $event->error === null
                && $event->broadcastAs() === 'disk-ai-edit.updated'
                && $event->broadcastOn()[0]->name === 'private-disk-ai.workspace.' . $this->workspace->id
                // Status only — NEVER the multi-MB image (it exceeds the per-message limit).
                && $event->broadcastWith() === ['id' => $edit->id, 'status' => 'done']
                && !array_key_exists('image', $event->broadcastWith())
                && !array_key_exists('result_image', $event->broadcastWith());
        });
    }

    public function test_a_provider_failure_broadcasts_a_failed_status_push(): void
    {
        Event::fake([DiskAiEditUpdated::class]);
        Http::fake(['*/images/edits' => Http::response(['error' => 'boom'], 500)]);

        $edit = $this->queuedEditWithInput();
        $job = new EditDiskImageJob($edit->id, $this->workspace->id);

        // The provider failure propagates out of handle(); the terminal failed() hook records it.
        $this->assertThrows(
            fn () => $job->handle(app(ImageAiService::class)),
            RuntimeException::class,
        );
        $job->failed(new RuntimeException('retries exhausted'));

        $this->assertSame(DiskAiEditStatus::Failed, $edit->fresh()->status);

        // Processing never broadcasts — exactly ONE terminal push, and it is the failure.
        Event::assertDispatchedTimes(DiskAiEditUpdated::class, 1);
        Event::assertDispatched(DiskAiEditUpdated::class, function (DiskAiEditUpdated $event) use ($edit) {
            return $event->workspaceId === $this->workspace->id
                && $event->editId === $edit->id
                && $event->status === 'failed'
                && $event->error === __('disk.ai.failed')
                && $event->broadcastWith() === ['id' => $edit->id, 'status' => 'failed', 'error' => __('disk.ai.failed')]
                && !array_key_exists('image', $event->broadcastWith());
        });
    }

    public function test_no_push_is_broadcast_when_no_workspace_is_active(): void
    {
        // The reaper fails SHARED-DB edits with the tenant context cleared; the guard must then skip
        // the push (the poll endpoint stays the fallback) rather than broadcast to a null workspace.
        Event::fake([DiskAiEditUpdated::class]);

        $edit = $this->queuedEditWithInput();

        app(TenantContext::class)->clear();
        app(ImageAiService::class)->fail($edit->id, __('disk.ai.failed'));

        $this->assertSame(DiskAiEditStatus::Failed, $edit->fresh()->status);
        Event::assertNotDispatched(DiskAiEditUpdated::class);
    }

    // ---- Channel authorization -------------------------------------------------------

    public function test_a_workspace_member_is_authorized_for_their_channel_and_non_members_denied(): void
    {
        $authorize = $this->diskAiChannelCallback();

        $nonMember = User::factory()->create();

        // A member (here the owner + attached user) is authorized for their workspace's channel.
        $this->assertTrue($authorize($this->user, $this->workspace->id));

        // A user who does not belong to the workspace is denied.
        $this->assertFalse($authorize($nonMember, $this->workspace->id));

        // An unknown workspace id is denied — the answer leaks nothing about whether it exists.
        $this->assertFalse($authorize($this->user, (string) Str::uuid()));
    }

    public function test_the_broadcasting_auth_route_is_registered(): void
    {
        // bootstrap/app.php passes channels: to withRouting(), which registers /broadcasting/auth.
        $registered = collect(app('router')->getRoutes()->getRoutes())
            ->contains(fn ($route) => $route->uri() === 'broadcasting/auth');

        $this->assertTrue($registered, 'The /broadcasting/auth channel-authorization route should be registered.');
    }

    /**
     * Pull the real `disk-ai.workspace.{workspaceId}` authorization callback registered by
     * routes/channels.php off the resolved broadcaster, so the membership check can be exercised
     * directly (the test broadcaster is `null`, whose auth() is a no-op, so /broadcasting/auth
     * cannot run it).
     */
    private function diskAiChannelCallback(): callable
    {
        $broadcaster = Broadcast::driver();

        $channels = (fn () => $this->channels)->call($broadcaster);

        return $channels['disk-ai.workspace.{workspaceId}'];
    }
}
