<?php

namespace App\Modules\Variables\Support;

/**
 * THE single predicate for the VALUE-OR-VARIABLE union wire shape
 * (`{kind:'literal', value} | {kind:'variable', ref:{source,path,type}, pipeline?, default?}`) that
 * every structured (non-text) field — and, since phase-4b, every operation ARGUMENT — may carry.
 *
 * It exists because three layers must agree BYTE-for-BYTE on "is this array a union, or is it data",
 * and each had (or was about to grow) its own private copy of the check:
 *   - WorkflowConditionTreeValidator  gates a union at WRITE time (validateArgVariable),
 *   - VariableResolver        pre-resolves a union into a LITERAL before the executor runs,
 *   - OperationExecutor       REJECTS one defensively: it is a PURE transformer with no
 *     resolver/context handle, so a union that still reaches it (a legacy / hand-written / imported
 *     row that bypassed pre-resolution) is a MALFORMED argument. Its ARRAY-shaped arg readers only
 *     checked `is_array` — and a union IS an array — so they consumed the union's own keys/values AS
 *     DATA, which could OPEN a condition gate (see the ADR-0022 amendment).
 *
 * One shape, one place: a union that stops being detected here stops being detected everywhere at
 * once, instead of in two layers out of three — which is exactly how the executor's readers came to
 * fail OPEN while the scalar readers next to them failed closed.
 */
final class ValueOrVariable
{
    /** The union's `kind` discriminator for the VARIABLE half; anything else is a literal / plain data. */
    private const VARIABLE_KIND = 'variable';

    /** Whether $value is the VARIABLE half of the union (`{kind:'variable', …}`) rather than a literal. */
    public static function isVariable(mixed $value): bool
    {
        return is_array($value) && ($value['kind'] ?? null) === self::VARIABLE_KIND;
    }
}
