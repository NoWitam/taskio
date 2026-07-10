<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\Enums\WorkflowVariableType;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Resolves workflow variable references in a step's config against the run context. REPLACES
 * the flat ReferenceResolver: it understands BOTH serializations of the ONE canonical variable
 * identity, plus keeps the legacy flat `{{...}}` tokens working during the transition.
 *
 * A variable identity is always `{ source: trigger|steps, path, type }`. It is serialized two
 * ways, both handled here:
 *
 *   1. TEXT / markdown fields carry the next editor's VARIABLE DIRECTIVE:
 *
 *        @[variable]("<json>")   where <json> = {"v":1,"data":{id,name,type,locked,pipeline,resultType}}
 *      (the payload's `"` are escaped as `\"` — the exact bytes of the editor's
 *      encodeVariableDirective; the directive carries NO extra type field). `data.id` is the
 *      full path ("trigger.fields.abc") and the ONLY key the resolver reads — the directive
 *      is IDENTITY-ONLY; a variable's REAL workflow type is recovered from the catalog by
 *      path, never from the directive. pipeline content is IGNORED (MVP identity refs).
 *   2. NON-TEXT (structured) fields carry the UNION:
 *        { kind: 'literal', value: <typed> } | { kind: 'variable', ref: { source, path, type } }
 *      handled by resolveValueOrVariable() with type coercion.
 *
 * TRANSITIONAL flat tokens: `{{trigger.*}}` / `{{steps.<key>.*}}` are still resolved (a plain
 * whitelisted dotted lookup). This keeps B1's e2e paths and any already-authored step configs
 * working until B7 ships directive-authored configs; it is NOT an expression engine — no
 * filters, arithmetic, or method calls, so nothing can be injected.
 *
 * WHITELIST: only the roots `trigger` and `steps` are readable, in EVERY serialization. Any
 * other root (env, config, __proto__, …) is not a reference — a standalone one resolves to
 * null, an embedded one to '' — so no context/env exfiltration is possible.
 */
class WorkflowVariableResolver
{
    /** Roots a reference may read from — anything else is not a reference. */
    private const ROOTS = ['trigger', 'steps'];

    /** A whole string that is EXACTLY one flat token: {{ path }}. */
    private const FLAT_STANDALONE = '/^\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}$/';

    /** A flat token anywhere inside a larger string. */
    private const FLAT_EMBEDDED = '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/';

    /** A whole string that is EXACTLY one variable directive: @[variable]("…"). */
    private const DIRECTIVE_STANDALONE = '/^@\[variable\]\("(.*)"\)$/s';

    /** A variable directive anywhere inside a larger string (payload may contain \" escapes). */
    private const DIRECTIVE_EMBEDDED = '/@\[variable\]\("((?:\\\\.|[^"\\\\])*)"\)/s';

    /**
     * Resolve every reference in $value against $context. Scalars resolve directly, arrays
     * recursively. Non-string scalars (int/bool/null) pass through untouched.
     *
     * @param  array<string, mixed>  $context
     */
    public function resolve(mixed $value, array $context): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->resolve($item, $context), $value);
        }

        if (is_string($value)) {
            return $this->resolveString($value, $context);
        }

        return $value;
    }

    /**
     * Resolve a single string that may contain editor directives and/or flat tokens.
     *
     * A STANDALONE reference (the whole string is exactly one directive or one flat token)
     * returns the TYPED looked-up value (arrays stay arrays, ints stay ints, null stays null).
     * Otherwise every EMBEDDED reference is stringified into the surrounding text (arrays join
     * with ', '; null / unknown → '').
     *
     * @param  array<string, mixed>  $context
     */
    public function resolveString(string $value, array $context): mixed
    {
        // Standalone directive → typed value.
        if (preg_match(self::DIRECTIVE_STANDALONE, $value, $m) === 1) {
            $path = $this->directivePath($m[1]);

            return $path !== null && $this->isReference($path) ? Arr::get($context, $path) : null;
        }

        // Standalone flat token → typed value (transitional).
        if (preg_match(self::FLAT_STANDALONE, $value, $m) === 1) {
            return $this->isReference($m[1]) ? Arr::get($context, $m[1]) : $value;
        }

        // Embedded: replace directives first, then flat tokens, stringifying each.
        $value = preg_replace_callback(self::DIRECTIVE_EMBEDDED, function (array $m) use ($context): string {
            $path = $this->directivePath($m[1]);

            if ($path === null || !$this->isReference($path)) {
                return $m[0]; // malformed / non-reference directive: leave literal
            }

            return $this->stringify(Arr::get($context, $path));
        }, $value);

        return preg_replace_callback(self::FLAT_EMBEDDED, function (array $m) use ($context): string {
            if (!$this->isReference($m[1])) {
                return $m[0]; // non-reference flat token: leave literal
            }

            return $this->stringify(Arr::get($context, $m[1]));
        }, $value);
    }

    /**
     * Resolve the STRUCTURED union used by non-text fields, coercing the result to the
     * expected type:
     *   { kind: 'literal',  value } → the value coerced to $expectedType.
     *   { kind: 'variable', ref: { source, path, type } } → Arr::get(context, path) coerced.
     * A non-union value is treated as a literal (so a bare scalar in a structured slot works).
     * A variable whose ref root is not whitelisted, or any unresolvable path, → null.
     *
     * @param  array<string, mixed>  $context
     */
    public function resolveValueOrVariable(mixed $field, array $context, WorkflowVariableType $expectedType): mixed
    {
        if (!is_array($field)) {
            return $this->coerce($field, $expectedType);
        }

        $kind = $field['kind'] ?? null;

        if ($kind === 'variable') {
            $path = $this->refPath($field['ref'] ?? null);

            $raw = $path !== null && $this->isReference($path) ? Arr::get($context, $path) : null;

            return $this->coerce($raw, $expectedType);
        }

        // literal (default): coerce the literal value.
        return $this->coerce($field['value'] ?? null, $expectedType);
    }

    /**
     * Decode a variable directive payload and return its `data.id` (the path). Tolerates
     * malformed JSON (returns null, so the caller leaves the directive literal / resolves null)
     * and IGNORES pipeline content — MVP references are identity-only. The payload is the legacy
     * byte-format: the inner JSON with each `"` written as `\"`.
     */
    private function directivePath(string $rawPayload): ?string
    {
        try {
            $decoded = json_decode(str_replace('\\"', '"', $rawPayload), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $data = $decoded['data'] ?? $decoded;
        $id = is_array($data) ? ($data['id'] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The dotted path of a structured `variable` ref. Prefers an explicit `source` + `path`
     * pair (`trigger` + `fields.abc` → `trigger.fields.abc`); a `path` that already carries a
     * whitelisted root is accepted as-is (tolerant of either shape).
     */
    private function refPath(mixed $ref): ?string
    {
        if (!is_array($ref)) {
            return null;
        }

        $path = $ref['path'] ?? null;

        if (!is_string($path) || $path === '') {
            return null;
        }

        $source = $ref['source'] ?? null;

        if (is_string($source) && $source !== '' && !str_starts_with($path, $source . '.') && $path !== $source) {
            return $source . '.' . $path;
        }

        return $path;
    }

    /** Whether a dotted path is rooted at a whitelisted context root. */
    private function isReference(string $path): bool
    {
        return in_array(explode('.', $path, 2)[0], self::ROOTS, true);
    }

    /** Stringify a resolved value for embedding in surrounding text. */
    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value));
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Coerce a resolved value into the expected workflow type. A value that cannot be coerced
     * becomes null (date parse fail, non-numeric to number) — the step decides how to react.
     *   date    → ISO-8601 string (via Carbon) | null
     *   number  → int|float | null
     *   boolean → bool
     *   enum/text → string | null
     *   multi   → array (a scalar is wrapped)
     */
    private function coerce(mixed $value, WorkflowVariableType $type): mixed
    {
        if ($value === null) {
            return $type === WorkflowVariableType::MULTI ? [] : null;
        }

        return match ($type) {
            WorkflowVariableType::DATE => $this->coerceDate($value),
            WorkflowVariableType::NUMBER => is_numeric($value) ? $value + 0 : null,
            WorkflowVariableType::BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            WorkflowVariableType::MULTI => is_array($value) ? array_values($value) : [$value],
            WorkflowVariableType::ENUM, WorkflowVariableType::TEXT => is_scalar($value) ? (string) $value : null,
        };
    }

    /** Coerce to an ISO-8601 string, or null on any parse failure (never throws). */
    private function coerceDate(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
