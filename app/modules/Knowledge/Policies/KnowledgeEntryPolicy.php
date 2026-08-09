<?php

namespace App\Modules\Knowledge\Policies;

use App\Models\User;
use App\Modules\Knowledge\Models\KnowledgeEntry;

/**
 * Who may READ an entry — and, deliberately, nobody may write one.
 *
 * ------------------------------------------------------------------------------------------------
 * KNOWLEDGE IS AUTHORED BY THE AI, NOT BY PEOPLE
 *
 * An entry reaches a base one way: the composer proposes it and a person ACCEPTS the proposal. There
 * is no hand-written entry, no hand-edited body, no hand-deleted page, no restore from the trash and
 * no purge. A person's authority over the base is to approve, to refuse, and to ask the composer
 * again in different words — which is a real authority, and a different one from writing.
 *
 * `create`, `update`, `delete`, `restore`, `forceDelete` and `reorder` therefore DENY EVERYONE. They
 * are kept rather than removed on purpose: the abilities are still named all over the framework
 * (`$this->authorize(...)`, `@can`, resource capability flags), and a policy with no method for an
 * ability is not a refusal — Laravel falls through to whatever the gate's default is. Answering
 * `false` in writing is the barrier; deleting the method would be a hole shaped like a tidy-up.
 *
 * These are the SECOND barrier. The first is that the routes no longer exist. Two, because a route
 * table is edited casually and a policy is not.
 *
 * THE SERVICE IS UNTOUCHED. `KnowledgeEntryService::create/update/delete/purge` run constantly — the
 * composer writing a draft, the applier publishing an accepted one, the erasure command destroying a
 * subject's data. Policies gate PEOPLE; they do not gate the module's own machinery.
 */
class KnowledgeEntryPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, KnowledgeEntry $entry): bool
    {
        return $user !== null;
    }

    /**
     * RUNNING THE COMPOSER AND ACCEPTING WHAT IT PRODUCES — the ability a person actually has.
     *
     * It exists as its own name because it used to borrow `create`, and that conflation is what made
     * withdrawing hand-authorship briefly withdraw the composer too: starting a session, refining it,
     * widening its context, rebasing a proposal and ACCEPTING one were all authorized as "may create an
     * entry". They are not that. "May direct the AI and approve its output" and "may write an entry by
     * hand" were the same sentence only for as long as both were true of everybody.
     *
     * Ordinary member work: a shared base is composed by whoever is reading the material.
     */
    public function compose(?User $user): bool
    {
        return $user !== null;
    }

    /**
     * Re-queue a failed indexing run. Ordinary member work, and its own ability for the same reason as
     * `compose`: it used to borrow `update`, which now denies everyone.
     */
    public function retryIndex(?User $user, KnowledgeEntry $entry): bool
    {
        return $user !== null;
    }

    /**
     * THROW AWAY A DRAFT the composer proposed — the refusal half of the review, and the sharpest case
     * of why these abilities had to be separated.
     *
     * It used to borrow `delete`, which is now denied, and the two look alike only from a distance:
     * both destroy a row. Deleting a published ENTRY removes knowledge the base holds; rejecting a
     * DRAFT declines to add any. The second is the thing a reviewer is FOR.
     *
     * Refused for anything that is not a draft, so this can never become a back door to the first.
     */
    public function rejectDraft(?User $user, KnowledgeEntry $entry): bool
    {
        return $user !== null && $entry->isDraft();
    }

    /** Writing an entry by hand: refused. The composer writes; a person accepts what it wrote. */
    public function create(?User $user): bool
    {
        return false;
    }

    /**
     * Editing an entry's text by hand: refused.
     *
     * The nearest thing a person still has is to REFUSE the amendment the composer proposed, or to
     * re-run the session with an instruction that produces a better one.
     */
    public function update(?User $user, KnowledgeEntry $entry): bool
    {
        return false;
    }

    /**
     * Re-ordering a base's entries by hand: refused.
     *
     * This one changes no text, and it was the closest call in the whole withdrawal. It goes because
     * `position` is the base's CANONICAL order — the one every reader's list is sorted by, not a
     * per-person view preference — so re-arranging it is editorial control over the base's structure.
     * The composer still sets it, at the end, through `nextPosition()`.
     */
    public function reorder(?User $user): bool
    {
        return false;
    }

    /** Trashing an entry: refused. Refusing the PROPOSAL is the equivalent act, and it comes first. */
    public function delete(?User $user, KnowledgeEntry $entry): bool
    {
        return false;
    }

    public function restore(?User $user, KnowledgeEntry $entry): bool
    {
        return false;
    }

    /**
     * Destroying an entry, its history and its chunks: refused HERE, and still possible.
     *
     * The erasure command (`knowledge:purge-subject`) purges entries on an operator's behalf, from the
     * console, against a named subject and behind a typed confirmation. That is the one path by which
     * knowledge is destroyed, and it is deliberately not a button.
     */
    public function forceDelete(?User $user, KnowledgeEntry $entry): bool
    {
        return false;
    }
}
