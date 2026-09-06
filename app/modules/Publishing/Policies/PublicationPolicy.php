<?php

namespace App\Modules\Publishing\Policies;

use App\Models\User;
use App\Modules\Publishing\Models\Publication;
use App\Tenancy\TenantContext;

/**
 * Who may write to the outside world on this workspace's behalf.
 *
 * READS are not decided here in any meaningful sense, exactly as in `CalendarEventPolicy`: workspace
 * membership is already proven upstream (ResolveWorkspace 403s a non-member, RequireWorkspace 400s a
 * request naming no workspace) and `WorkspaceScope` means a foreign publication never resolves at all.
 * So `view`/`viewAny` ask only that somebody is logged in. A publishing queue the team cannot see is
 * not a queue.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WRITES ARE "THE CREATOR, OR THE WORKSPACE OWNER" — the same widening the Calendar made
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * One step wider than `ChecksRecordOwnership`, which escalates to the workspace owner only for a system
 * record nobody owns. A publication is not a personal artifact: it is the team's voice going out under
 * the team's account, and one left armed by somebody who has gone on holiday, changed teams or left the
 * company must not be un-cancellable. Here the argument is sharper than it was for a calendar
 * annotation — the thing that happens if nobody can intervene is not a stale square on a grid, it is a
 * post appearing publicly that nobody present wanted.
 *
 * A publication created by a workflow run has no human owner (`isOwnedBy` can never be true for it),
 * and the second clause is what keeps it manageable. No separate branch is needed.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE STATE HALF, AND WHY IT IS IN THE POLICY RATHER THAN IN A SERVICE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * "May this be edited?" has two independent answers — WHO is asking, and WHERE the row is in its life.
 * The second lives on {@see \App\Modules\Publishing\Enums\PublicationStatus} (`isEditable`,
 * `isDeletable`) and is composed here.
 *
 * It is composed HERE, and not checked inside the service, because the resource publishes
 * `can_be_edited` / `can_be_deleted` and those flags must be the same computation the write path
 * enforces. Split them and the UI offers a button whose save 403s — which is precisely the class of
 * defect a capability flag exists to prevent. It also keeps authorization out of services, where nobody
 * looks for it.
 *
 * The Manager remains the sole authority on TRANSITIONS. This policy answers a different question
 * (may this person touch this row at all), and neither duplicates the other: the transition table says
 * nothing about people, and this file contains no edges.
 *
 * Fail-closed throughout: a null user, or no active workspace (a queue/console context), yields false.
 */
class PublicationPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, Publication $publication): bool
    {
        return $user !== null;
    }

    /** Any member may draft something. Nothing leaves the building until it is armed and claimed. */
    public function create(?User $user): bool
    {
        return $user !== null;
    }

    public function update(?User $user, Publication $publication): bool
    {
        return $publication->status->isEditable() && $this->ownsOrIsWorkspaceOwner($publication, $user);
    }

    public function delete(?User $user, Publication $publication): bool
    {
        return $publication->status->isDeletable() && $this->ownsOrIsWorkspaceOwner($publication, $user);
    }

    /**
     * ARMING IS THE CONSEQUENTIAL ACT, and it is its own ability for that reason.
     *
     * Editing a draft changes text in a database. Arming it is what puts a moment on a clock after which
     * something appears in public, so it gets a name of its own — a UI can offer editing while
     * withholding arming, and a future rule ("only a reviewer may arm", the Approvable work) has a place
     * to attach that does not require re-deciding what `update` means.
     *
     * `isEditable()` is the right state test even here: the three statuses it refuses are exactly the
     * three where arming is meaningless or dangerous, and the Manager's table independently refuses the
     * edges anyway. This is the pre-filter that keeps the capability flag honest, not the enforcement.
     */
    public function schedule(?User $user, Publication $publication): bool
    {
        return $publication->status->isEditable() && $this->ownsOrIsWorkspaceOwner($publication, $user);
    }

    /** The publication's human creator, or the ACTIVE workspace's owner. */
    private function ownsOrIsWorkspaceOwner(Publication $publication, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($publication->isOwnedBy($user)) {
            return true;
        }

        return app(TenantContext::class)->workspace()?->isOwnedBy($user) ?? false;
    }
}
