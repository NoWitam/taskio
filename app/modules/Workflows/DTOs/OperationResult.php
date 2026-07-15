<?php

namespace App\Modules\Workflows\DTOs;

use App\Modules\Workflows\Enums\WorkflowVariableType;

/**
 * The outcome of running a variable-operation pipeline through WorkflowOperationExecutor: either a
 * SUCCESS carrying the transformed value + its (terminal) workflow type, or a fail-closed FAILURE
 * (an unknown op, a type mismatch, an unparseable/absent input, divide-by-zero, an unmapped enum
 * option, or an over-long pipeline). The executor NEVER throws — every dead end is a failure result,
 * so both the condition engine and the variable resolver can branch on `$result->failed` without a
 * try/catch and apply their own field doctrine (a condition falls to false; a directive falls to '').
 *
 * On a success the `value` is the canonical PHP shape for `type` (text/enum → string, number →
 * float, boolean → bool, date → CarbonImmutable, multi → string[]); on a failure `value`/`type`
 * are null and must not be read.
 */
final class OperationResult
{
    private function __construct(
        public readonly bool $failed,
        public readonly mixed $value,
        public readonly ?WorkflowVariableType $type,
    ) {}

    public static function success(mixed $value, WorkflowVariableType $type): self
    {
        return new self(false, $value, $type);
    }

    public static function failure(): self
    {
        return new self(true, null, null);
    }
}
