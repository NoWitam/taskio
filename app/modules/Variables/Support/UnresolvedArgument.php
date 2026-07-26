<?php

namespace App\Modules\Variables\Support;

/**
 * A DEFENSE-ONLY sentinel for a WHOLE structural argument (sourceMap / choiceRules `rules`) that could
 * not resolve to an array. It is rejected by OperationExecutor::arrayArg so such an argument
 * fails its operation CLOSED instead of degrading to the "no rules / empty map" semantic.
 *
 * IT IS NO LONGER PRODUCED BY THE RESOLVER. WorkflowVariableResolver now pre-resolves a structural
 * container PER ENTRY (resolveStructuralEntry): a sourceMap VALUE or a choiceRules `then` that cannot
 * resolve becomes a typed `null`, and the executor's READERS fail that entry closed on their own —
 * enumMap treats a null mapped value as unmapped → FAIL, and matchToChoice, when a rule's `when`
 * MATCHES but its `then` is non-scalar, fails the op closed rather than returning the fallback
 * (symmetric with enumMap). So an unresolvable per-entry variable never OPENS a condition gate without
 * this marker ever being minted.
 *
 * WHY IT REMAINS. The executor's array-shaped reader spells its absent-argument default as
 * `$args[$key] ?? $whenAbsent`, and `??` cannot tell "the author wrote no `rules`" (a DELIBERATE,
 * documented semantic: no rules → the required fallback — see ADR-0022) apart from "the WHOLE
 * container resolved to null". Were a future path to hand the executor a null-valued whole `rules`
 * argument, `??` would swallow it into "no rules" and match_to_choice would yield its fallback as a
 * SUCCESS — FAILING OPEN. This non-null, non-array marker keeps that distinction available: `??` never
 * swallows it, and arrayArg — the ONE reader `values` (enum_in / multi_includes_any|all), `mapping`
 * (every enum_to_*) and `rules` (match_to_choice) all funnel through — rejects it EXPLICITLY. Fail-SOFT,
 * not fatal: the executor returns an ordinary failure result and never throws.
 *
 * A LITERAL `rules: null` still means "no rules" — only a marker changes the outcome.
 *
 * The bytes are NUL-delimited like the module's other in-band sentinels: a form value can never
 * carry a NUL (Postgres rejects it), so user data can never forge one.
 */
final class UnresolvedArgument
{
    /** The marker itself — produced by the resolver, rejected by the executor's array-shaped reader. */
    public const MARKER = "\0__workflow_unresolved_argument__\0";

    /** Whether $value is the unresolvable-argument marker. */
    public static function is(mixed $value): bool
    {
        return $value === self::MARKER;
    }
}
