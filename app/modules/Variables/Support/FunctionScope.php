<?php

namespace App\Modules\Variables\Support;

use App\Modules\Variables\Contracts\OperationDefinition;
use App\Modules\Variables\Enums\PipelineLimits;
use App\Modules\Variables\Enums\VariableType;

/**
 * The CUSTOM-FUNCTION execution state threaded through the (pure, re-entrant) OperationExecutor — the
 * workspace's available functions, the current expansion DEPTH, the active-function VISITED chain, and
 * the current scope FRAME (a function body's `{input + args}` bindings). It rides the run context under a
 * NUL-guarded reserved KEY rather than an execute() parameter, so it flows through EVERY re-entrant call
 * (element pipelines, reducers, scope-arg sub-runs) that already threads the context — without widening a
 * signature the whole engine shares. A form value can never carry a NUL key (Postgres rejects NUL), and
 * no real reference path is a NUL byte, so the key can neither collide with nor be forged by data.
 *
 * FAIL-CLOSED, TWO WAYS (Phase 3b doctrine, both non-negotiable):
 *   - overDepth(): a function op beyond MAX_FUNCTION_EXPANSION_DEPTH fails CLOSED (never loops).
 *   - hasVisited(): re-entering a function already on the active chain — a cycle that bypassed the
 *     write-time acyclic check (a corrupted / raced / hand-written row) — fails CLOSED.
 *
 * SCOPE FRAME (the array-element re-entry pattern, generalized): a function body is PURE over
 * `{input, <argName>…}`, so entering one INSTALLS a fresh frame (enter() REPLACES it — a called
 * function sees ITS OWN input/args, never the caller's). An array transform INSIDE a body keeps the
 * function frame in context (its element/index ride the executor's existing param threading), so the
 * element pipeline resolves BOTH its own `element`/`index` AND the enclosing body's `input`/`<argName>`
 * — the frame STACK the master contract requires. frameRoots() feeds the generalized {@see ScopeRef}.
 */
final class FunctionScope
{
    /** The reserved run-context key this state rides under (NUL-guarded — never a real path or form value). */
    public const CONTEXT_KEY = "\0__variables_function_scope__\0";

    /**
     * @param  array<int, OperationDefinition>  $functions  the workspace's custom functions (resolver input)
     * @param  int  $depth  the current function-expansion depth (0 at a top-level call)
     * @param  array<int, string>  $visited  the active-function chain (each entry a `fn:<uuid>` op id)
     * @param  array<string, array{value: mixed, type: VariableType}>  $frame  the body's `{input + args}` bindings
     */
    public function __construct(
        public readonly array $functions = [],
        public readonly int $depth = 0,
        public readonly array $visited = [],
        public readonly array $frame = [],
    ) {}

    /** A top-level scope seeded with the workspace $functions (depth 0, no chain, no frame). */
    public static function forFunctions(iterable $functions): self
    {
        return new self(is_array($functions) ? $functions : iterator_to_array($functions));
    }

    /** Read the scope off a run context, or an EMPTY scope (no functions) when absent/malformed. */
    public static function fromContext(array $context): self
    {
        $scope = $context[self::CONTEXT_KEY] ?? null;

        return $scope instanceof self ? $scope : new self;
    }

    /**
     * The context with this scope written under the reserved key.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function writeInto(array $context): array
    {
        $context[self::CONTEXT_KEY] = $this;

        return $context;
    }

    /**
     * A scope one function deeper: depth + 1, $uuid appended to the active chain, and $frame INSTALLED
     * as the new body frame (replacing any caller frame — a body is pure over its own input + args).
     *
     * @param  array<string, array{value: mixed, type: VariableType}>  $frame
     */
    public function enter(string $functionId, array $frame): self
    {
        return new self($this->functions, $this->depth + 1, [...$this->visited, $functionId], $frame);
    }

    /** Whether entering one more function would exceed the hard expansion cap → fail CLOSED. */
    public function overDepth(): bool
    {
        return $this->depth + 1 > PipelineLimits::MAX_FUNCTION_EXPANSION_DEPTH;
    }

    /** Whether $functionId is already on the active chain (a cycle) → fail CLOSED. */
    public function hasVisited(string $functionId): bool
    {
        return in_array($functionId, $this->visited, true);
    }

    /**
     * The scope ROOTS the current frame exposes (the distinct first path segment of each binding key —
     * `input`, each arg name), for the generalized {@see ScopeRef}. Empty when no function frame is in
     * play, so a normal element pipeline keeps ScopeRef's default element/index roots (byte-identical).
     *
     * @return array<int, string>
     */
    public function frameRoots(): array
    {
        $roots = [];

        foreach (array_keys($this->frame) as $key) {
            $roots[] = explode('.', (string) $key, 2)[0];
        }

        return array_values(array_unique($roots));
    }
}
