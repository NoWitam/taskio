<?php

namespace App\Modules\Variables\Support;

/**
 * THE single predicate for "is this variable REF the synthetic per-element `element` / `index` scope"
 * (array-ops wave 2). Like ValueOrVariable, it exists because THREE layers must agree BYTE-for-BYTE, and
 * each had grown its own private copy — which had DRIFTED:
 *   - WorkflowConditionTreeValidator  gates a scope ref at WRITE time (source-aware — CORRECT),
 *   - WorkflowVariableResolver        keeps a scope ref OUT of global pre-resolution (was LEAF-ONLY),
 *   - OperationExecutor       resolves a scope ref per iteration against the overlay (was LEAF-ONLY).
 *
 * The two LEAF-ONLY copies flagged ANY ref whose PATH leaf was literally `element` / `index` as scope,
 * ignoring a real `source`. So an ordinary workspace-global / trigger / step variable named "Index"
 * (`source:'globals', path:'…index'`) was write-accepted as a normal ref but at runtime mis-flagged as
 * scope: the resolver refused to pre-resolve it and, inside an element pipeline, it resolved to the LOOP
 * index instead of the global's value — a WRONG-VALUE substitution feeding a filter/map predicate that
 * gates a condition. This helper is source-aware (only `source:'scope'`, or an absent source, is scope)
 * so the three sites can never disagree again.
 *
 * WAVE 3 (array<object>/array<file> element access): a scope ref may now carry a SUBFIELD path
 * `element.<field>` (e.g. `element.price`, `element.name`) — the object/file element's subfields the
 * element pipeline exposes. `leaf()` therefore returns the FULL scope path (`element`, `index`, or
 * `element.<sub>`), keyed on the ROOT segment being `element` / `index` (source-aware still). `index`
 * is a leaf scalar with no subfields, so only `element` may carry a `.<field>` tail. The three sites
 * validate `element.<field>` against the element scope, resolve it off the element snapshot, and reject
 * it everywhere else — exactly as they already do for the bare `element` / `index`.
 */
final class ScopeRef
{
    /**
     * The DEFAULT synthetic scope ROOTS — the `element` / `index` an array element pipeline exposes.
     * Callers that expose a DIFFERENT scope (a custom function body, whose roots are `input` + its
     * arg names) pass their own root set; keeping this the default means every existing element-pipeline
     * call is byte-identical.
     */
    public const DEFAULT_ROOTS = ['element', 'index'];

    /**
     * The scope PATH (`element` / `index` / `element.<field>` — or, for a function body, `input` /
     * `<argName>`) a reference addresses, or null when it is NOT a scope reference. SOURCE-AWARE: a ref
     * with a real, non-scope `source` (globals / trigger / step) is never the scope, even when its path
     * root happens to match a scope root. An absent source is tolerated (a bare scope ref) — the FE emits
     * `source:'scope'`, so this only widens for legacy / hand-written rows. Keyed on the ROOT segment so a
     * subfield tail (`element.price`, wave 3) rides along, while `index` (a leaf scalar) is rejected the
     * moment it carries a `.<sub>` tail.
     *
     * $roots is CALLER-SUPPLIED so the SAME predicate serves an array element pipeline (`element`/`index`,
     * the default) and a function body (`input` + arg names); nothing else is a scope reference.
     *
     * @param  array<string, mixed>  $ref
     * @param  array<int, string>  $roots  the scope roots this context exposes (default: element/index)
     */
    public static function leaf(array $ref, array $roots = self::DEFAULT_ROOTS): ?string
    {
        $source = $ref['source'] ?? null;

        if (is_string($source) && $source !== '' && $source !== 'scope') {
            return null; // a real (or foreign) root is not the scope
        }

        $path = (string) ($ref['path'] ?? '');
        $root = explode('.', $path, 2)[0];

        if (!in_array($root, $roots, true)) {
            return null;
        }

        // `index` is a leaf scalar with NO subfields; only `element` may carry a `.<field>` tail.
        if ($root === 'index' && $path !== 'index') {
            return null;
        }

        return $path;
    }

    /**
     * Whether $value is the VALUE-OR-VARIABLE union for a scope reference — the shape the resolver keys on
     * (it must be a variable union AND address a scope leaf). Combines ValueOrVariable::isVariable with
     * leaf() so the resolver never re-implements either half. $roots is threaded to leaf() (default:
     * element/index) so a function-body scope union resolves the same way.
     *
     * @param  array<int, string>  $roots
     */
    public static function isScopeUnion(mixed $value, array $roots = self::DEFAULT_ROOTS): bool
    {
        if (!ValueOrVariable::isVariable($value)) {
            return false;
        }

        $ref = is_array($value['ref'] ?? null) ? $value['ref'] : [];

        return self::leaf($ref, $roots) !== null;
    }
}
