<?php

namespace App\Modules\Generator\Services;

use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Services\ConstantTypeValidator;
use App\Modules\Variables\Services\PipelineValidator;
use App\Modules\Variables\Services\VariableResolver;
use Illuminate\Contracts\Validation\Validator;
use Throwable;

/**
 * The single, reusable authority for a TEMPLATE's SLOTS + any interpolated markdown BODY — the Generator
 * twin of FunctionDefinitionValidator / ConstantTypeValidator, appending granular errors to the request
 * Validator. Centralized here (not hand-rolled in the FormRequest) so it is the ONE place a template's
 * slots + a body's directives are checked and they can never drift from what the engine understands. Its
 * two public methods are composed by {@see TemplateContentValidator}: validate the slots ONCE, then run
 * validateBody over each part's markdown (a text_body/script part, a scene narration, an image-plan prompt).
 *
 * It enforces:
 *   - each SLOT {name, description?, descriptor}: a UNIQUE, non-RESERVED, safe-identifier name; an
 *     optional text description; and a `descriptor` that is a well-formed type — DELEGATED to the shared
 *     ConstantTypeValidator (the SAME descriptor authority a constant uses), so a slot's type can never
 *     accept a shape the resolver/catalog cannot.
 *   - a markdown body's `@[variable]` directives: each reference is resolved against the TEMPLATE catalog
 *     (the declared `slots.<name>` + the workspace globals), so an unknown `slots.<name>` is rejected at
 *     write; and each directive's PIPELINE is type-flowed through the shared PipelineValidator (built-ins
 *     ∪ custom functions), so a pipeline that does not type-check is a 422.
 *
 * FAIL-SOFT on noise, FAIL-CLOSED on error: a malformed / undecodable directive renders inert at runtime
 * (the resolver treats it as literal), so it is SKIPPED here rather than falsely rejected; only a genuine
 * unknown-slot reference or a type-incompatible pipeline is reported.
 */
class TemplateSlotValidator
{
    /**
     * The scope roots a slot name may not shadow: the function scope names (input/element/index) and the
     * whitelisted context ROOTS (trigger/steps/globals/slots/parts). Inlined as literals — mirroring
     * VariableResolver::ROOTS (the R2 superset now includes the cross-part `parts` root) — so this validator
     * carries no dependency on the resolver service. Doubles as the recognized reference-source set threaded
     * into the body write-validator's refCtx (so a `parts.<key>` reference is a known source).
     *
     * @var array<int, string>
     */
    private const RESERVED_SLOT_NAMES = ['input', 'element', 'index', 'trigger', 'steps', 'globals', 'slots', 'parts'];

    /** A safe slot identifier — a scope path segment read like a variable path (Arr::get-safe). */
    private const SAFE_NAME = '/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/';

    /** The `@[variable]("<payload>")` directive pattern — the SAME the resolver reads (identity-only). */
    private const DIRECTIVE = '/@\[variable\]\("((?:\\\\.|[^"\\\\])*)"\)/s';

    public function __construct(
        private ConstantTypeValidator $descriptors,
        private PipelineValidator $pipeline,
        private TemplateVariableCatalog $catalog,
        private VariableResolver $resolver,
    ) {}

    /**
     * Validate the slots list and return the set of DECLARED slot names (for the body reference check).
     * Per-slot errors land under slots.<i>.*; a malformed slot is skipped from the declared set but never
     * aborts the others. PUBLIC so {@see TemplateContentValidator} validates the (content-type-shared) slots
     * ONCE, then hands the returned names to each part's body/plan check.
     *
     * @return array<int, string>
     */
    public function validateSlots(Validator $validator, mixed $slots, string $slotsKey = 'slots'): array
    {
        if (!is_array($slots) || !array_is_list($slots)) {
            $validator->errors()->add($slotsKey, 'The slots must be a list of { name, descriptor } definitions.');

            return [];
        }

        $declared = [];
        $seen = [];

        foreach ($slots as $i => $slot) {
            $key = $slotsKey . '.' . $i;

            if (!is_array($slot)) {
                $validator->errors()->add($key, 'Each slot must be an object.');

                continue;
            }

            // The descriptor is validated regardless of the name (independent errors), reusing the SAME
            // authority a constant's type uses — a slot declares a type exactly like a constant, plus the
            // slot-only `file` composite (validateSlotDescriptorShape): a file is a legit reusable input.
            $this->descriptors->validateSlotDescriptorShape($validator, $slot['descriptor'] ?? null, $key . '.descriptor');

            if (array_key_exists('description', $slot) && $slot['description'] !== null && !is_string($slot['description'])) {
                $validator->errors()->add($key . '.description', 'The slot description must be text.');
            }

            $name = $slot['name'] ?? null;

            if (!is_string($name) || preg_match(self::SAFE_NAME, $name) !== 1) {
                $validator->errors()->add($key . '.name', 'The slot name must be a valid identifier (letters, digits and underscores, not starting with a digit).');

                continue;
            }

            if (in_array($name, self::RESERVED_SLOT_NAMES, true)) {
                $validator->errors()->add($key . '.name', 'The slot name may not be a reserved scope name (' . implode(', ', self::RESERVED_SLOT_NAMES) . ').');

                continue;
            }

            if (in_array($name, $seen, true)) {
                $validator->errors()->add($key . '.name', 'The slot names must be distinct.');

                continue;
            }

            $seen[] = $name;
            $declared[] = $name;
        }

        return $declared;
    }

    /**
     * Validate every `@[variable]` directive in a MARKDOWN BODY: its reference against the template
     * catalog, and its pipeline against the shared PipelineValidator. Only DECODABLE directives are
     * checked (a malformed one renders inert at runtime, so it is not a write error).
     *
     * PART-AGNOSTIC: the caller passes the exact $promptKey the errors land under, so the SAME body
     * validator gates a `text_body` / `script` part's `content.<part>.markdown`, a scene's narration, and
     * an image-plan `ai_edit` / `ai_generate` prompt — the ONE place a template body's directives are
     * checked, reused everywhere a template carries an interpolated markdown string.
     *
     * PART-AWARE (Phase A): $earlierPartKeys is the set of parts declared BEFORE the body's part; it seeds the
     * `parts.<earlierKey>` entries in the reference index, so a `@[variable]` over an EARLIER part type-checks
     * and a FORWARD/SELF/UNKNOWN part reference is rejected (earlier-only → acyclic). Empty (the default)
     * means no `parts.*` is referenceable.
     *
     * @param  array<int, mixed>  $slots  the declared slots (for the reference index)
     * @param  array<int, string>  $declaredNames  the well-formed declared slot names
     * @param  array<int, string>  $earlierPartKeys  the parts declared BEFORE this body's part
     */
    public function validateBody(Validator $validator, mixed $promptBody, array $slots, array $declaredNames, string $promptKey, array $earlierPartKeys = []): void
    {
        if ($promptBody === null || $promptBody === '') {
            return;
        }

        if (!is_string($promptBody)) {
            $validator->errors()->add($promptKey, 'The prompt body must be text.');

            return;
        }

        // Cross-part EARLIER-ONLY gate (Phase A, C2 fix): the shared resolver scanner finds EVERY `parts.*`
        // reference the resolver would resolve — a top-level `@[variable]` directive, a `@[ai-text]` prompt, OR
        // an if-block CONDITION/BODY — so a forward/self/unknown part reference ANYWHERE is a 422, keeping the
        // cross-part graph acyclic. A flat directive regex is BLIND to the nested + marker cases; this is now
        // the single authority for the `parts.*` gate (the flat directive loop below no longer checks it).
        $this->validateCrossPartReferences($validator, $promptBody, $earlierPartKeys, $promptKey);

        if (preg_match_all(self::DIRECTIVE, $promptBody, $matches) === false || $matches[1] === []) {
            return;
        }

        $index = $this->catalog->referenceIndex($slots, $earlierPartKeys);
        $refCtx = [
            'index' => $index,
            'fields_available' => true,
            'sources' => self::RESERVED_SLOT_NAMES,
            'functions' => $this->catalog->customFunctionOperations(),
        ];

        foreach ($matches[1] as $rawPayload) {
            $directive = $this->decodeDirective($rawPayload);

            if ($directive === null) {
                continue; // malformed / non-directive — renders inert, not a write error
            }

            $this->validateDirective($validator, $directive, $declaredNames, $index, $refCtx, $promptKey);
        }
    }

    /**
     * Enforce the cross-part EARLIER-ONLY rule for EVERY `parts.*` reference in a markdown body, found through
     * the shared {@see VariableResolver::collectReferenceIds} scanner — so a reference nested in an `@[ai-text]`
     * prompt or an if-block CONDITION/BODY is gated exactly like a top-level directive (the C2 fix). A ref whose
     * part key is not among $earlierPartKeys (a forward, self, or unknown part — or a bare `parts` root) is a
     * 422. Cross-part refs are TEXT-ONLY, so only the FIRST segment after `parts.` matters (no subfields).
     *
     * @param  array<int, string>  $earlierPartKeys
     */
    private function validateCrossPartReferences(Validator $validator, string $promptBody, array $earlierPartKeys, string $promptKey): void
    {
        foreach ($this->resolver->collectReferenceIds($promptBody) as $refId) {
            $segments = explode('.', $refId);

            if (($segments[0] ?? null) !== 'parts') {
                continue;
            }

            $key = $segments[1] ?? null;

            if ($key === null || !in_array($key, $earlierPartKeys, true)) {
                $validator->errors()->add($promptKey, 'The prompt references an unknown or later part: ' . $refId . '.');
            }
        }
    }

    /**
     * Validate one decoded directive `{id, pipeline}`. The `slots` surface is fully DECLARED, so both an
     * unknown `slots.<name>` AND an unknown SUBFIELD of a declared object/file slot (`slots.<name>.<sub>`
     * not in the index) are rejected — the two symmetric hard rejections the write gate owes. A KNOWN
     * reference (a declared slot, an indexed slot/global subfield, or a global) has its (non-empty) pipeline
     * type-flowed. Every other root (trigger/steps, a non-root, or an inert/unknown `globals.*`) renders
     * inert and is left unchecked (fail-soft at runtime).
     *
     * @param  array{id: string, pipeline: array<int, mixed>}  $directive
     * @param  array<int, string>  $declaredNames
     * @param  array<string, array{type: VariableType, enumOptions: array<int, string>|null, descriptor: array<string, mixed>|null}>  $index
     * @param  array<string, mixed>  $refCtx
     */
    private function validateDirective(Validator $validator, array $directive, array $declaredNames, array $index, array $refCtx, string $promptKey): void
    {
        $path = $directive['id'];
        $segments = explode('.', $path);
        $root = $segments[0] ?? null;
        $name = $segments[1] ?? null;

        // A `parts.*` reference's earlier-only gate is enforced ONCE, up front, by the shared-scanner pass
        // ({@see validateCrossPartReferences}) so a forward/self/unknown part ref nested in an ai-text prompt
        // or if-block marker is caught too — NOT here. A KNOWN (earlier) `parts.<key>` ref still type-flows its
        // pipeline through the gate below (its index entry is present); a forward one is absent from the index,
        // so it silently skips the pipeline flow (already rejected by the scanner pass — no double error).
        if ($root === 'slots') {
            // A `slots.<name>` reference must name a DECLARED slot (a bare `slots` root, or an undeclared
            // slot, is unresolvable).
            if ($name === null || !in_array($name, $declaredNames, true)) {
                $validator->errors()->add($promptKey, 'The prompt references an unknown slot: ' . $path . '.');

                return;
            }

            // ...and when it addresses a SUBFIELD (`slots.<name>.<sub>`) that subfield must be indexed — a
            // KNOWN object/file subfield of the declared slot. An unknown subfield is a definite mistake
            // (the slot surface is fully declared), rejected symmetrically with the unknown-slot check. A
            // top-level `slots.<name>` is left to the pipeline gate below (a malformed slot's descriptor
            // error already covers it — no double report).
            if (count($segments) > 2 && !array_key_exists($path, $index)) {
                $validator->errors()->add($promptKey, 'The prompt references an unknown slot field: ' . $path . '.');

                return;
            }
        }

        // Type-flow the pipeline only for a KNOWN reference (a declared slot, an indexed slot/global
        // subfield, or a global). An inert/unknown non-slot root carries no index entry — left unchecked.
        if ($directive['pipeline'] === [] || !array_key_exists($path, $index)) {
            return;
        }

        $entry = $index[$path];

        $this->pipeline->validateValuePipeline(
            $validator,
            $directive['pipeline'],
            $promptKey,
            $entry['type'],
            VariableType::cases(),
            $entry['enumOptions'],
            null,
            $refCtx,
            0,
            null,
            $entry['descriptor'],
        );
    }

    /**
     * Decode a `@[variable]` payload to `{id, pipeline}` — the SAME byte-format the resolver reads (the
     * inner JSON with each `"` written as `\"`). Tolerates malformed JSON / a missing id (returns null so
     * the caller skips it). Mirrors VariableResolver::decodeDirective (identity + pipeline only).
     *
     * @return array{id: string, pipeline: array<int, mixed>}|null
     */
    private function decodeDirective(string $rawPayload): ?array
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

        if (!is_array($data) || !is_string($data['id'] ?? null) || $data['id'] === '') {
            return null;
        }

        return [
            'id' => $data['id'],
            'pipeline' => is_array($data['pipeline'] ?? null) ? $data['pipeline'] : [],
        ];
    }
}
