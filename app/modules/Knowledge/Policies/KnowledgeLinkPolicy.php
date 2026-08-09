<?php

namespace App\Modules\Knowledge\Policies;

use App\Models\User;
use App\Modules\Knowledge\Models\KnowledgeLink;

/**
 * Who may say "no, these two are not related".
 *
 * ANY MEMBER may, and that is the point rather than an oversight. A similarity edge is a MACHINE
 * SUGGESTION, and the only thing that makes suggestions bearable is that the person looking at a
 * wrong one can remove it in one click. Routing that through an ownership check would mean the
 * suggestion nobody can dismiss stays on the screen of everyone who did not write the entry — which
 * is precisely the population most bothered by it. Nothing is destroyed either: a dismissal sets a
 * timestamp, it is visible (`?include_dismissed=1`) and it is reversible.
 *
 * It also matches the entry policy's own posture: entries are collaborative, any member may edit or
 * trash one, and dismissing a derived edge is strictly less consequential than either.
 *
 * WHICH EDGES may be dismissed is a domain rule, not an authorization one, and lives in
 * {@see \App\Modules\Knowledge\Http\Requests\DismissKnowledgeLinkRequest}: only `similarity` edges
 * qualify. A `wikilink` is what the entry's text SAYS — dismissing it would make the graph disagree
 * with the document, and the way to remove it is to remove the `[[…]]`. A `manual` edge was drawn by
 * a human on purpose; the way to remove it is to delete it, not to mark it rejected.
 */
class KnowledgeLinkPolicy
{
    public function dismiss(?User $user, KnowledgeLink $link): bool
    {
        return $user !== null;
    }
}
