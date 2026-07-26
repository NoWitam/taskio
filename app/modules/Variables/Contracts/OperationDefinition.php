<?php

namespace App\Modules\Variables\Contracts;

use App\Modules\Variables\DTOs\OperationArg;
use App\Modules\Variables\Enums\VariableType;

/**
 * The shape the pipeline WALK reads for a single operation — the abstraction that lets a pipeline
 * step be EITHER a built-in {@see \App\Modules\Variables\Enums\Operation} OR a user custom function
 * ({@see \App\Modules\Variables\Support\CustomFunctionOperation}). Extracted so the write-validator's
 * op walk (and, in Phase 3b, the runtime executor) can resolve a `fn:<uuid>` step to a definition it
 * type-checks exactly like a built-in, without either side importing the persistence layer.
 *
 * It exposes EXACTLY what the walk consults:
 *   - id()             the stable wire id (a built-in op value, or `fn:<uuid>` for a function).
 *   - inputType()      the single value type the op consumes (its input gate).
 *   - outputType()     the single value type it produces (the flat terminal fallback).
 *   - argDescriptors() the ordered argument descriptors.
 *   - outputDescriptor() the precise TERMINAL descriptor given the input descriptor + args.
 * plus the DISCRIMINATORS the walk branches on, each with a safe default for a plain op:
 *   - isCustom()       whether this is a custom function (vs a built-in) — only functions are true.
 *   - producesChoice() a choice-producing terminal (enum_to_choice / match_to_choice).
 *   - isArrayOp()      gated on the running value being an array (any element base).
 *   - isCollectionOp() a higher-order array op carrying a per-element pipeline.
 *   - isPresenceOp()   a presence-family helper handled before the per-step type gate.
 *
 * A custom function is none of the special kinds (all discriminators false); its typing comes purely
 * from its declared input/args/return.
 */
interface OperationDefinition
{
    /** The stable wire id: a built-in op value, or `fn:<uuid>` for a custom function. */
    public function id(): string;

    /** The value type this op CONSUMES (its single input). */
    public function inputType(): VariableType;

    /** The value type this op PRODUCES (its single output). */
    public function outputType(): VariableType;

    /**
     * The ordered argument descriptors ([] for a nullary op).
     *
     * @return array<int, OperationArg>
     */
    public function argDescriptors(): array;

    /** Whether this is a user CUSTOM FUNCTION (false for every built-in op). */
    public function isCustom(): bool;

    /** Whether this op maps a value into a destination field's option set (a choice terminal). */
    public function producesChoice(): bool;

    /** Whether this op is gated on the running value being an ARRAY (any element base). */
    public function isArrayOp(): bool;

    /** Whether this op is a higher-order array transform carrying a per-element pipeline. */
    public function isCollectionOp(): bool;

    /** Whether this op is a PRESENCE-family helper (handled before the per-step type gate). */
    public function isPresenceOp(): bool;

    /**
     * The TERMINAL descriptor this op produces given its INPUT descriptor (+ its literal $args and,
     * for a higher-order array op, the element pipeline's $terminalDescriptor).
     *
     * @param  array<string, mixed>  $inputDescriptor
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>|null  $terminalDescriptor
     * @return array<string, mixed>
     */
    public function outputDescriptor(array $inputDescriptor, array $args = [], ?array $terminalDescriptor = null): array;
}
