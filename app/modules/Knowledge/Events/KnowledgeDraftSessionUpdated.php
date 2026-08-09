<?php

namespace App\Modules\Knowledge\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PUSH notification that a drafting session changed state, so the composer can stop waiting and
 * re-fetch instead of POLLING. Mirrors the generation-session and Disk AI edit events.
 *
 * A composition takes tens of seconds. Polling for that is a request every second or two per open
 * composer, most of them answering "still working" — and the interval is a guess that is either too
 * slow for the user or too expensive for the server. An event costs one message at the moment the
 * answer changes.
 *
 * DELIBERATELY STATUS-ONLY: `{ id, status }`, never the drafts. The payload rides a broadcast channel
 * that fans out to every open composer in the workspace, and the drafts are the user's raw material
 * turned into text — the client already has an authenticated, workspace-scoped endpoint to fetch them
 * through, and that endpoint is where the authorization lives.
 *
 * Per-WORKSPACE (not per-session) private channel `knowledge.workspace.{workspaceId}`: every open
 * composer subscribes once and filters by id, exactly as the generator's chat does. Authorized by
 * central workspace membership in routes/channels.php.
 *
 * BOUNDARY NOTE: this event lives in the Knowledge module and names nothing above it. Broadcasting is
 * framework infrastructure, and the channel string is a literal — no consumer module is referenced, so
 * the one-way rule holds.
 */
class KnowledgeDraftSessionUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $workspaceId,
        public string $sessionId,
        public string $status,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("knowledge.workspace.{$this->workspaceId}")];
    }

    public function broadcastAs(): string
    {
        return 'knowledge-draft-session.updated';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->sessionId, 'status' => $this->status];
    }
}
