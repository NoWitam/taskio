<?php

namespace App\Modules\Publishing\Policies;

use App\Models\User;
use App\Modules\Publishing\Models\PlatformConnection;
use App\Tenancy\TenantContext;

/**
 * Who may attach an account to this workspace, and who may take one away.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * IT MIRRORS PublicationPolicy DELIBERATELY, RATHER THAN INVENTING A SECOND DOCTRINE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Reads: workspace membership, already proven upstream (`ResolveWorkspace` 403s a non-member,
 * `RequireWorkspace` 400s a request naming none, `WorkspaceScope` means a foreign row never resolves).
 * So `viewAny` asks only that somebody is logged in. A team that cannot see which accounts its
 * publications go out on cannot reason about its own queue.
 *
 * Writes: "the creator, or the workspace owner" — the widening the Calendar made and Publishing
 * adopted. The second clause is what stops a connection authorized by somebody who has since left the
 * company from being permanently un-removable, which for a live credential is not a tidiness problem.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * CONNECTING IS ANY MEMBER, AND THAT IS A CHOICE WORTH SEEING
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * It follows `PublicationPolicy::create` — any member may draft — and the products this one resembles
 * work the same way: a person wires up the account they administer.
 *
 * The argument AGAINST is real and is recorded here rather than lost: connecting an account grants the
 * whole workspace the ability to publish under that account's name, which is a wider act than drafting
 * a post. If this workspace's answer should be "owner only", THIS METHOD is the one line that changes,
 * and `can_be_disconnected` on the resource plus the FormRequest that calls it both follow without
 * further edits. It is not gated on a role because the product has no concept of roles beyond
 * owner/member (see the workspace tenancy notes), so "only an administrator" is not expressible today
 * without inventing one.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IS DELIBERATELY ABSENT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * No `update`. A connection has no editable content — its fields are either the platform's answers or
 * the Manager's bookkeeping, and the only "edit" anybody could want (a nicer display name) would put a
 * value in a column whose whole purpose is to say what the PLATFORM calls this account.
 *
 * There is also no state test here, unlike `PublicationPolicy`, which composes `isEditable()` into its
 * abilities. A connection is disconnectable in every status: `active` because that is the normal case,
 * `needs_reauth` because "this is broken, remove it" is the most likely thing somebody wants, and
 * `revoked` is unreachable through a route (the row is soft-deleted and never binds).
 *
 * Fail-closed throughout: a null user, or no active workspace, yields false.
 */
class PlatformConnectionPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, PlatformConnection $connection): bool
    {
        return $user !== null;
    }

    /** Begin a handshake. See the class docblock for the argument this decision is balanced on. */
    public function create(?User $user): bool
    {
        return $user !== null;
    }

    /**
     * DISCONNECT — the consequential act, and the one with a blast radius beyond the row.
     *
     * Removing a connection holds every publication scheduled on it
     * ({@see \App\Modules\Publishing\Managers\PlatformConnectionManager::revoke()}), so this is not
     * "delete a record I made" — it is "stop the team's queue for this account". Hence creator-or-owner
     * rather than any member: the person who wired an account may unwire it, and the workspace owner may
     * always intervene.
     */
    public function delete(?User $user, PlatformConnection $connection): bool
    {
        return $this->ownsOrIsWorkspaceOwner($connection, $user);
    }

    /** The connection's human creator, or the ACTIVE workspace's owner. */
    private function ownsOrIsWorkspaceOwner(PlatformConnection $connection, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($connection->isOwnedBy($user)) {
            return true;
        }

        return app(TenantContext::class)->workspace()?->isOwnedBy($user) ?? false;
    }
}
