<?php

namespace App\Modules\Variables\DTOs;

use App\Modules\Variables\Enums\VariableType;

/**
 * The outcome of running a variable-operation pipeline through OperationExecutor: either a
 * SUCCESS carrying the transformed value + its (terminal) workflow type, or a fail-closed FAILURE
 * (an unknown op, a type mismatch, an unparseable/absent input, divide-by-zero, an unmapped enum
 * option, or an over-long pipeline). The executor NEVER throws — every dead end is a failure result,
 * so both the condition engine and the variable resolver can branch on `$result->failed` without a
 * try/catch and apply their own field doctrine (a condition falls to false; a directive falls to '').
 *
 * On a success the `value` is the canonical PHP shape for `type` (text/enum → string, number →
 * float, boolean → bool, date → CarbonImmutable, multi → string[]); on a failure `value`/`type`
 * are null and must not be read.
 *
 * HARD failure (append-only, phase-1b): a single opt-in variant — hardFailure(), raised only by the
 * assert_present op over an empty value — is still a `failed` result (so a CONDITION caller stays
 * fail-closed to false; it never reads `hard`), but carries `hard = true` so a VALUE-producing
 * resolver path re-raises it as the run's standard step-failure ("force a value"). The executor
 * itself still NEVER throws.
 */
final class OperationResult
{
    private function __construct(
        public readonly bool $failed,
        public readonly mixed $value,
        public readonly ?VariableType $type,
        public readonly bool $hard = false,
    ) {}

    public static function success(mixed $value, VariableType $type): self
    {
        return new self(false, $value, $type);
    }

    public static function failure(): self
    {
        return new self(true, null, null);
    }

    /**
     * The ONE opt-in HARD failure (assert_present over an empty value): still a fail-closed failure
     * (`failed` = true, so conditions stay false), flagged `hard` so a value-producing resolver path
     * re-raises it as a run step-failure. It is NOT a thrown exception — the executor stays throw-free.
     */
    public static function hardFailure(): self
    {
        return new self(true, null, null, true);
    }
}
