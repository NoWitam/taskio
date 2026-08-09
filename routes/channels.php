<?php

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\Broadcast;

/**
 * Private channel carrying async Disk AI image-edit status pushes for ONE workspace
 * (see {@see \App\Modules\Disk\Events\DiskAiEditUpdated}). It is per-workspace, not
 * per-edit: every editor open in the workspace subscribes once and filters by id.
 *
 * Authorized by CENTRAL workspace membership so the `/broadcasting/auth` request needs
 * no tenant context (it runs on the `auth:sanctum` guard with the API's Bearer token,
 * without X-Workspace-Id): the channel name carries the workspace id and we check the
 * authenticated user belongs to it. `Workspace::find()` is unscoped (Workspace is a
 * central model, not tenant-aware) and `hasMember()` runs the membership existence check
 * WITHOUT the member scope — exactly the check ResolveWorkspace uses to gate the
 * X-Workspace-Id header. A missing user, workspace, or non-member returns false (denied).
 *
 * `$user` is nullable defensively: `auth:sanctum` normally rejects an unauthenticated
 * request before the callback runs, but a null here yields a clean denial rather than a
 * TypeError.
 */
Broadcast::channel('disk-ai.workspace.{workspaceId}', function (?User $user, string $workspaceId): bool {
    return $user !== null && (Workspace::find($workspaceId)?->hasMember($user) ?? false);
});

/**
 * Private channel carrying async generation-SESSION terminal-status pushes for ONE workspace
 * (see {@see \App\Modules\Generator\Events\GenerationSessionUpdated}) — so the chat waits on an event
 * instead of polling. Per-workspace, not per-session: every open chat subscribes once and filters by id.
 * Authorized by CENTRAL workspace membership, identical posture to the Disk AI channel above.
 */
Broadcast::channel('generator.workspace.{workspaceId}', function (?User $user, string $workspaceId): bool {
    return $user !== null && (Workspace::find($workspaceId)?->hasMember($user) ?? false);
});

/**
 * Private channel carrying AI drafting-session status pushes for ONE workspace
 * (see {@see \App\Modules\Knowledge\Events\KnowledgeDraftSessionUpdated}) — so the composer waits on an
 * event instead of polling a run that takes tens of seconds. Per-workspace, not per-session: every open
 * composer subscribes once and filters by id. The payload is status-only, never the drafts. Authorized
 * by CENTRAL workspace membership, identical posture to the two channels above.
 */
Broadcast::channel('knowledge.workspace.{workspaceId}', function (?User $user, string $workspaceId): bool {
    return $user !== null && (Workspace::find($workspaceId)?->hasMember($user) ?? false);
});
