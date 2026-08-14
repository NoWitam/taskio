<?php

namespace App\Modules\Calendar\Policies;

use App\Models\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Tenancy\TenantContext;

/**
 * Who may write on the shared grid.
 *
 * READS are not decided here in any meaningful sense. Workspace membership is already proven upstream
 * (ResolveWorkspace 403s a non-member, RequireWorkspace 400s a request that names no workspace) and
 * `WorkspaceScope` means a foreign event never resolves at all — so `view`/`viewAny` ask only that
 * somebody is logged in. A calendar that hid squares from members of the workspace whose calendar it is
 * would not be a coordination surface.
 *
 * WRITES are "the event's creator, OR the workspace owner". That is one step wider than
 * {@see \App\Policies\Concerns\ChecksRecordOwnership}, which escalates to the workspace owner ONLY for
 * a system record nobody owns, and the widening is deliberate — the same one
 * `KnowledgeBasePolicy` made, for the same reason. Those rules are right for a PERSONAL artifact (my
 * file, my task); an event is not one. It is a mark on a grid the whole team reads, so an event left
 * by somebody who has since gone on holiday, changed teams or left the company must not be
 * un-removable — a shared calendar nobody can correct stops being trusted, and then stops being used.
 * The workspace owner is the one person accountable for that, so the owner can correct it.
 *
 * The wider rule also happens to cover the system-record case for free: an event created by a workflow
 * run has no human owner (`isOwnedBy` can never be true for it), and the second clause is what keeps
 * it mutable. No separate branch is needed.
 *
 * Fail-closed throughout: a null user, or no active workspace (a queue/console context), yields false.
 */
class CalendarEventPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, CalendarEvent $event): bool
    {
        return $user !== null;
    }

    /** Any member may put something on the team's calendar. */
    public function create(?User $user): bool
    {
        return $user !== null;
    }

    public function update(?User $user, CalendarEvent $event): bool
    {
        return $this->ownsOrIsWorkspaceOwner($event, $user);
    }

    public function delete(?User $user, CalendarEvent $event): bool
    {
        return $this->ownsOrIsWorkspaceOwner($event, $user);
    }

    /** The event's human creator, or the ACTIVE workspace's owner. */
    private function ownsOrIsWorkspaceOwner(CalendarEvent $event, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($event->isOwnedBy($user)) {
            return true;
        }

        return app(TenantContext::class)->workspace()?->isOwnedBy($user) ?? false;
    }
}
