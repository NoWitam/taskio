<?php

namespace App\Modules\Knowledge\Policies;

use App\Models\User;
use App\Modules\Knowledge\Models\KnowledgeRelation;

/**
 * Who may READ the facts a base records about its entries — and, deliberately, nobody may write one.
 *
 * ------------------------------------------------------------------------------------------------
 * THE GRAPH IS THE AI'S ACCOUNT OF THE MATERIAL
 *
 * A relation exists because the composer proposed it and a person accepted that proposal. Nothing else
 * creates one, edits one, ends one or destroys one. A graph that mixes machine-derived statements with
 * hand-drawn ones cannot answer the question it exists for — "what does the material actually say" —
 * because the two are indistinguishable once written, and `origin` records where a row came from only
 * as long as every row comes from somewhere accountable.
 *
 * `end` and `retract` are refused along with the rest, and that is not an oversight about severity.
 * They keep the row, which makes them SAFE; it does not make them somebody else's authorship. Deciding
 * that the base should stop asserting something is exactly as much an editorial act as deciding it
 * should start.
 *
 * The abilities are kept and return `false` rather than being deleted: a policy with no method for an
 * ability is not a refusal, it is a fall-through to the gate's default. Written refusals are the
 * barrier. The routes are gone as well — two barriers, because a route table gets edited casually.
 *
 * THE SERVICE IS UNTOUCHED and is called on every accepted proposal, including the `end` half of a
 * supersede. Policies gate PEOPLE, never the module's own machinery.
 */
class KnowledgeRelationPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, KnowledgeRelation $relation): bool
    {
        return $user !== null;
    }

    /** Drawing a relation by hand — including promoting a machine suggestion into one: refused. */
    public function create(?User $user): bool
    {
        return false;
    }

    public function update(?User $user, KnowledgeRelation $relation): bool
    {
        return false;
    }

    /** Ending, superseding, retracting: refused. See the class docblock for why "safe" is not the test. */
    public function end(?User $user, KnowledgeRelation $relation): bool
    {
        return false;
    }

    /**
     * Destroying the statement and the fact that it was ever made: refused.
     *
     * Still reachable where it has to be: `knowledge:purge-subject` deletes relations naming an erasure
     * subject, from the console, behind a typed confirmation.
     */
    public function delete(?User $user, KnowledgeRelation $relation): bool
    {
        return false;
    }
}
