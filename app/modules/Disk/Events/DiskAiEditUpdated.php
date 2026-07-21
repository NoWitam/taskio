<?php

namespace App\Modules\Disk\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PUSH notification that an async Disk AI image edit reached a TERMINAL state (done/failed), so the
 * browser can stop polling and — on done — fetch the result via GET /disk/ai/image/{id}.
 *
 * Deliberately LIGHTWEIGHT: the payload is only { id, status, error? }. The multi-MB base64
 * result_image is NEVER broadcast — it exceeds the Reverb/pusher per-message size limit, and the
 * poll endpoint remains the one place that serves the image. Fired from {@see ImageAiService} on
 * the terminal transition.
 *
 * Per-WORKSPACE (not per-edit) private channel `disk-ai.workspace.{workspaceId}`: every editor open
 * in the workspace subscribes once and filters by id. Authorized centrally by workspace membership
 * in routes/channels.php.
 */
class DiskAiEditUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $workspaceId,
        public string $editId,
        public string $status,
        public ?string $error = null,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("disk-ai.workspace.{$this->workspaceId}")];
    }

    public function broadcastAs(): string
    {
        return 'disk-ai-edit.updated';
    }

    /**
     * The wire payload — status only, never the image (see the class note). `error` is omitted
     * entirely unless present so the browser can treat its mere presence as the failure signal.
     *
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return array_filter([
            'id' => $this->editId,
            'status' => $this->status,
            'error' => $this->error,
        ], fn ($value) => $value !== null);
    }
}
