<?php

namespace App\Modules\Generator\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PUSH notification that an async generation-session RUN reached a TERMINAL state (`ready`/`failed`), so the
 * chat can stop waiting and re-fetch the settled session (results + last_op_status) instead of POLLING.
 * Mirrors {@see \App\Modules\Disk\Events\DiskAiEditUpdated}.
 *
 * Deliberately LIGHTWEIGHT: the payload is only { id, status, last_op_status? } — never the produced
 * text/image (which the chat fetches via GET /generator/sessions/{id}). Fired from
 * {@see \App\Modules\Generator\Services\GenerationSessionRunManager} on the terminal transition.
 *
 * Per-WORKSPACE (not per-session) private channel `generator.workspace.{workspaceId}`: every open chat in
 * the workspace subscribes once and filters by id. Authorized by central workspace membership in
 * routes/channels.php (same posture as the Disk AI channel).
 */
class GenerationSessionUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $workspaceId,
        public string $sessionId,
        public string $status,
        public ?string $lastOpStatus = null,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("generator.workspace.{$this->workspaceId}")];
    }

    public function broadcastAs(): string
    {
        return 'generation-session.updated';
    }

    /**
     * The wire payload — status only, never the produced content. `last_op_status` is omitted unless present
     * (a whole-run has none) so the browser can read the per-part op outcome without a second call.
     *
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return array_filter([
            'id' => $this->sessionId,
            'status' => $this->status,
            'last_op_status' => $this->lastOpStatus,
        ], fn ($value) => $value !== null);
    }
}
