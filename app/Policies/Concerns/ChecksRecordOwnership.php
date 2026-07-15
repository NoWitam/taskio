<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Tenancy\TenantContext;

/**
 * Ownership gate for the MUTATION abilities of a HasCreator record (update/delete/restore/…).
 *
 * The record's human owner (isOwnedBy) may always act. A SYSTEM record — one created by a
 * workflow run or bot, whose creatorUser() is null and which therefore nobody owns — would
 * otherwise be permanently un-mutable; so the ACTIVE workspace's OWNER is allowed to act on
 * it as a safety fallback. A record that DOES have a human creator is never escalated to the
 * workspace owner (that would be cross-user privilege, which access control does not grant).
 *
 * Fail-closed: a null user, or no active workspace (e.g. a queue/console context), yields false.
 */
trait ChecksRecordOwnership
{
    /**
     * @param  object  $record  A HasCreator model (exposes isOwnedBy()/creatorUser()).
     */
    protected function ownsOrManagesSystemRecord(object $record, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($record->isOwnedBy($user)) {
            return true;
        }

        // Only a system record (no human creator) falls back to the workspace owner.
        if ($record->creatorUser() !== null) {
            return false;
        }

        return app(TenantContext::class)->workspace()?->isOwnedBy($user) ?? false;
    }
}
