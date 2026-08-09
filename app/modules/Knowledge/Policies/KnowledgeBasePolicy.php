<?php

namespace App\Modules\Knowledge\Policies;

use App\Models\User;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Tenancy\TenantContext;

/**
 * Workspace membership is enforced upstream (RequireWorkspace + ResolveWorkspace + WorkspaceScope), so
 * a base only ever reaches these checks if it belongs to the ACTIVE workspace. What is decided here is
 * the split between ordinary work and GOVERNANCE.
 *
 * A knowledge base is a SHARED asset — it exists so a workspace has one answer instead of several — so
 * gating everyday edits on the creator would be wrong: the base would decay the moment its author went
 * on holiday. Hence: any member reads, creates, and edits the name/description.
 *
 * Two things are not everyday edits. The CHARTER states what the base is for, and the METADATA SCHEMA
 * retro-actively decides whether every existing entry's metadata is still valid — one careless schema
 * edit can invalidate a base someone spent a month filling. Those, plus deletion, need `manage`.
 *
 * `manage` is NOT {@see \App\Policies\Concerns\ChecksRecordOwnership}: that trait lets the workspace
 * owner act only on records nobody owns (a system record), which is right for a personal artifact like
 * a file, and wrong here. A workspace owner must be able to govern a shared base their colleague
 * created — otherwise the one person accountable for the workspace cannot fix its knowledge — so the
 * rule is deliberately the wider "creator OR workspace owner" (the ADR-0015 vocabulary, one step
 * wider). Fail-closed: no user or no active workspace yields false.
 */
class KnowledgeBasePolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, KnowledgeBase $base): bool
    {
        return $user !== null;
    }

    public function create(?User $user): bool
    {
        return $user !== null;
    }

    /** Everyday edits: name, description, language. */
    public function update(?User $user, KnowledgeBase $base): bool
    {
        return $user !== null;
    }

    /** Governance: the charter and the metadata schema. */
    public function manage(?User $user, KnowledgeBase $base): bool
    {
        return $this->ownsOrIsWorkspaceOwner($base, $user);
    }

    public function delete(?User $user, KnowledgeBase $base): bool
    {
        return $this->ownsOrIsWorkspaceOwner($base, $user);
    }

    public function restore(?User $user, KnowledgeBase $base): bool
    {
        return $this->ownsOrIsWorkspaceOwner($base, $user);
    }

    /** Irreversible: the base and every entry, revision, chunk and link under it. */
    public function forceDelete(?User $user, KnowledgeBase $base): bool
    {
        return $this->ownsOrIsWorkspaceOwner($base, $user);
    }

    /**
     * The base's human creator, or the ACTIVE workspace's owner. A base authored by a system record
     * (a bot/run creator, so `isOwnedBy` can never be true) is covered by the same second clause,
     * which is why no separate system-record branch is needed.
     */
    private function ownsOrIsWorkspaceOwner(KnowledgeBase $base, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($base->isOwnedBy($user)) {
            return true;
        }

        return app(TenantContext::class)->workspace()?->isOwnedBy($user) ?? false;
    }
}
