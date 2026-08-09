<?php

namespace App\Modules\Knowledge\Support;

/**
 * What the type matrix concluded about one proposed relation.
 *
 * THREE outcomes rather than a boolean, because the interesting case is the middle one. A base's
 * existing entries are all untyped, so "I cannot tell" is not a rare edge — it is the normal state of
 * every base on the day this ships, and folding it into either `ALLOWED` or `REFUSED` gets something
 * badly wrong:
 *
 *   folded into REFUSED  → every correct relation in every existing base is rejected, confidently,
 *                          on the strength of a fact nobody supplied.
 *   folded into ALLOWED  → the reviewer is told nothing, and a genuinely dubious pair looks identical
 *                          to a checked one.
 *
 * So it is its own answer, and each CALLER decides the consequence: the HTTP write accepts it (there
 * is nowhere to show a warning on a 201, and refusing would be the first failure mode above), while
 * the composer's review report carries it as a warning next to the proposal, where a human is already
 * looking and can supply the missing type.
 */
enum RelationVerdict
{
    /** Both ends are typed and the pair is in the matrix. */
    case ALLOWED;

    /** At least one end has no type (or is `other`), so the matrix has nothing to say. */
    case UNKNOWN_TYPES;

    /** Both ends are typed and the pair is NOT in the matrix — the only hard refusal. */
    case REFUSED;

    public function isRefused(): bool
    {
        return $this === self::REFUSED;
    }

    /** Whether a reviewer should be shown a "this was not checked" marker. */
    public function isAdvisory(): bool
    {
        return $this === self::UNKNOWN_TYPES;
    }
}
