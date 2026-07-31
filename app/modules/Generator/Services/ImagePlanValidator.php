<?php

namespace App\Modules\Generator\Services;

use Illuminate\Contracts\Validation\Validator;

/**
 * The write-time authority for an IMAGE PLAN — the config of an `image_plan` part (and of a scene's
 * optional image). An image plan is a DECLARED recipe mirroring the variable pipeline: a `base` (where
 * the image STARTS) + an ordered `filters` CHAIN (how it is transformed). EXECUTION (resolving the base,
 * running the chain) is sub-stage 2; this only validates the SHAPE, fail-closed, so a session can never
 * start from an un-runnable plan.
 *
 *   base = { kind: 'disk_file' | 'from_slot' | 'ai_generate', … }
 *     - disk_file    { file: <id> }   a fixed Disk file. BOUNDARY: Phase 1 authoring is Disk-DECOUPLED —
 *                                     the id is stored as an OPAQUE string here and its existence /
 *                                     ownership is validated at session/execution time (sub-stage 2), so
 *                                     the Generator module stays Generator → Variables only (no Disk import).
 *     - from_slot    { slot: <name> } a FILE-typed declared slot, filled per session. The name MUST be a
 *                                     declared slot whose descriptor base is `file`.
 *     - ai_generate  { prompt: <md> } a text→image prompt (D6 — MODELED but the client ships in sub-stage 6;
 *                                     accepted at write, never required to author/preview).
 *   filters = [ { op, kind: 'pixel' | 'ai_edit', params? | prompt? }, … ]  an ordered chain:
 *     - pixel    a deterministic pixel/geometry op from the {@see self::PIXEL_OPS} set (the imageOps.ts
 *                op vocabulary) with valid params.
 *     - ai_edit  { prompt: <md>, mask? } a provider image-edit prompt (+ optional mask ref).
 *   character = 'auto' | 'never'  (optional, defaults to auto) whether a DELEGATED session's frozen creator
 *                                 may appear in this image — see {@see validateCharacter}.
 *
 * Every PROMPT string (ai_generate, ai_edit) is markdown carrying the SAME `@[variable]` / `slots.*`
 * directives a body does, so each is run through {@see TemplateSlotValidator::validateBody} — the ONE
 * body-directive authority — so a `w stylu {slot}` prompt's slot references are write-validated too.
 */
class ImagePlanValidator
{
    /** The base kinds an image may START from (D5 + D6). */
    private const BASE_KINDS = ['disk_file', 'from_slot', 'ai_generate'];

    /** A filter is either a deterministic pixel op or an AI edit prompt. */
    private const FILTER_KINDS = ['pixel', 'ai_edit'];

    /** Whether a DELEGATED session's frozen creator may appear in this image (see {@see validateCharacter}). */
    private const CHARACTER_MODES = ['auto', 'never'];

    /**
     * The deterministic pixel/geometry ops — the imageOps.ts vocabulary (the editor's pure functions).
     * Kept as literal strings (pinned equal to the FE op set by the FE mirror), so this validator carries
     * no dependency on the Disk editor: the chain is a NEW ordered pipeline that REUSES the op names, not
     * the single-filter editor.
     *
     * @var array<int, string>
     */
    private const PIXEL_OPS = [
        'grayscale', 'sepia', 'invert', 'warm', 'cool', // color casts (no params)
        'brightness', 'contrast', 'saturation',          // tonal adjustments (amount)
        'crop', 'rotate', 'flip',                        // geometry
    ];

    /** The color-cast ops that take NO params (any params are ignored, not an error). */
    private const PIXEL_OPS_NO_PARAMS = ['grayscale', 'sepia', 'invert', 'warm', 'cool'];

    public function __construct(
        private TemplateSlotValidator $bodies,
    ) {}

    /**
     * Validate one image-plan $config, appending errors under $key. $slots + $declaredNames seed the
     * prompt directive validation (the SAME catalog a body uses) and the file-typed-slot lookup a
     * `from_slot` base must name.
     *
     * PART-AWARE (Phase A): $earlierPartKeys is the set of parts declared BEFORE the part this plan belongs to;
     * threaded into every prompt body so an ai_generate / ai_edit prompt may reference `parts.<earlierKey>`.
     *
     * @param  array<int, mixed>  $slots  the declared slots
     * @param  array<int, string>  $declaredNames  the well-formed declared slot names
     * @param  array<int, string>  $earlierPartKeys  the parts declared BEFORE this plan's part
     */
    public function validate(Validator $validator, mixed $config, array $slots, array $declaredNames, array $earlierPartKeys, string $key): void
    {
        if (!is_array($config)) {
            $validator->errors()->add($key, 'The image plan must be an object.');

            return;
        }

        $this->validateBase($validator, $config['base'] ?? null, $slots, $declaredNames, $earlierPartKeys, $key . '.base');
        $this->validateFilters($validator, $config['filters'] ?? null, $slots, $declaredNames, $earlierPartKeys, $key . '.filters');
        $this->validateCharacter($validator, $config['character'] ?? null, $key . '.character');
    }

    /**
     * The CHARACTER policy for this image (the visual-identity phase): whether the frozen creator of a
     * DELEGATED session may appear in it.
     *
     *   auto   (the default, and what an ABSENT key means) — draw the creator when the session has one. That
     *          is what handing a whole session to a persona means, so it must not need opting into.
     *   never  — this image never shows the creator, whoever the session belongs to. The product shot, the
     *           logo, the chart: images where inserting a person is both wrong and billed.
     *
     * There is deliberately no `always`: `auto` already means yes, and an `always` on a session with no
     * character would be an unkeepable promise. Unknown values are REFUSED rather than defaulted, so a typo
     * (`'none'`) fails at write instead of quietly putting a face in every product shot.
     */
    private function validateCharacter(Validator $validator, mixed $character, string $key): void
    {
        if ($character !== null && !in_array($character, self::CHARACTER_MODES, true)) {
            $validator->errors()->add($key, 'The character mode must be one of: ' . implode(', ', self::CHARACTER_MODES) . '.');
        }
    }

    /**
     * The BASE — where the image starts. One of disk_file / from_slot / ai_generate; each carries its own
     * required member.
     *
     * @param  array<int, mixed>  $slots
     * @param  array<int, string>  $declaredNames
     * @param  array<int, string>  $earlierPartKeys
     */
    private function validateBase(Validator $validator, mixed $base, array $slots, array $declaredNames, array $earlierPartKeys, string $key): void
    {
        if (!is_array($base)) {
            $validator->errors()->add($key, 'The image plan requires a base.');

            return;
        }

        $kind = $base['kind'] ?? null;

        if (!in_array($kind, self::BASE_KINDS, true)) {
            $validator->errors()->add($key . '.kind', 'The base kind must be one of: ' . implode(', ', self::BASE_KINDS) . '.');

            return;
        }

        match ($kind) {
            // Disk-DECOUPLED (BOUNDARY): the file id is an opaque string here; its existence + ownership
            // is validated at execution time (sub-stage 2), keeping Generator → Variables only.
            'disk_file' => $this->requireNonEmptyString($validator, $base['file'] ?? null, $key . '.file', 'The disk_file base must reference a Disk file.'),
            // from_slot must name a DECLARED file-typed slot (filled per session).
            'from_slot' => $this->validateFromSlot($validator, $base['slot'] ?? null, $slots, $key . '.slot'),
            // ai_generate (D6): a prompt string, directive-validated. Modeled now, executed sub-stage 6.
            'ai_generate' => $this->bodies->validateBody($validator, $base['prompt'] ?? null, $slots, $declaredNames, $key . '.prompt', $earlierPartKeys),
            default => null,
        };
    }

    /**
     * A `from_slot` base names a DECLARED slot whose descriptor base is `file` — the only slots that hold
     * an image per session. An undeclared name, or a non-file slot, is rejected.
     *
     * @param  array<int, mixed>  $slots
     */
    private function validateFromSlot(Validator $validator, mixed $slot, array $slots, string $key): void
    {
        if (!is_string($slot) || $slot === '' || !in_array($slot, $this->fileSlotNames($slots), true)) {
            $validator->errors()->add($key, 'The from_slot base must name a declared file slot.');
        }
    }

    /**
     * The ordered FILTER chain. An absent chain is fine (a base needs no transform); a present one must be
     * a LIST, each entry a pixel op or an ai_edit prompt. PUBLIC so the storyboard part (Phase B) can reuse
     * the SAME filter-chain validation for the authored per-shot chain (it has no base of its own).
     *
     * @param  array<int, mixed>  $slots
     * @param  array<int, string>  $declaredNames
     * @param  array<int, string>  $earlierPartKeys
     */
    public function validateFilters(Validator $validator, mixed $filters, array $slots, array $declaredNames, array $earlierPartKeys, string $key): void
    {
        if ($filters === null) {
            return;
        }

        if (!is_array($filters) || !array_is_list($filters)) {
            $validator->errors()->add($key, 'The filters must be an ordered list.');

            return;
        }

        foreach ($filters as $i => $filter) {
            $this->validateFilter($validator, $filter, $slots, $declaredNames, $earlierPartKeys, $key . '.' . $i);
        }
    }

    /**
     * One filter: a pixel op (a known op + valid params) or an ai_edit (a directive-validated prompt +
     * optional mask ref).
     *
     * @param  array<int, mixed>  $slots
     * @param  array<int, string>  $declaredNames
     * @param  array<int, string>  $earlierPartKeys
     */
    private function validateFilter(Validator $validator, mixed $filter, array $slots, array $declaredNames, array $earlierPartKeys, string $key): void
    {
        if (!is_array($filter)) {
            $validator->errors()->add($key, 'Each filter must be an object.');

            return;
        }

        $kind = $filter['kind'] ?? null;

        if (!in_array($kind, self::FILTER_KINDS, true)) {
            $validator->errors()->add($key . '.kind', 'The filter kind must be one of: ' . implode(', ', self::FILTER_KINDS) . '.');

            return;
        }

        if ($kind === 'pixel') {
            $this->validatePixelFilter($validator, $filter, $key);

            return;
        }

        // ai_edit: a directive-validated prompt (+ optional mask ref).
        $this->bodies->validateBody($validator, $filter['prompt'] ?? null, $slots, $declaredNames, $key . '.prompt', $earlierPartKeys);
        $this->validateMask($validator, $filter['mask'] ?? null, $key . '.mask');
    }

    /**
     * A pixel filter: a known op from the imageOps vocabulary, with params valid for that op.
     */
    private function validatePixelFilter(Validator $validator, array $filter, string $key): void
    {
        $op = $filter['op'] ?? null;

        if (!in_array($op, self::PIXEL_OPS, true)) {
            $validator->errors()->add($key . '.op', 'The pixel op must be one of: ' . implode(', ', self::PIXEL_OPS) . '.');

            return;
        }

        $this->validatePixelParams($validator, (string) $op, $filter['params'] ?? null, $key . '.params');
    }

    /**
     * Validate a pixel op's params: the tonal adjustments take an `amount` in [-100, 100]; crop a `rect`
     * of four 0..1 fractions; rotate `quarterTurns` in 1..3; flip an `axis`. The no-param color casts
     * accept (and ignore) anything.
     */
    private function validatePixelParams(Validator $validator, string $op, mixed $params, string $key): void
    {
        if (in_array($op, self::PIXEL_OPS_NO_PARAMS, true)) {
            return;
        }

        match ($op) {
            'brightness', 'contrast', 'saturation' => $this->requireAmount($validator, is_array($params) ? ($params['amount'] ?? null) : null, $key . '.amount'),
            'crop' => $this->validateCropRect($validator, is_array($params) ? ($params['rect'] ?? null) : null, $key . '.rect'),
            'rotate' => $this->requireQuarterTurns($validator, is_array($params) ? ($params['quarterTurns'] ?? null) : null, $key . '.quarterTurns'),
            'flip' => $this->requireAxis($validator, is_array($params) ? ($params['axis'] ?? null) : null, $key . '.axis'),
            default => null,
        };
    }

    /** A tonal `amount` on the -100..100 UI scale. */
    private function requireAmount(Validator $validator, mixed $amount, string $key): void
    {
        if (!is_numeric($amount) || $amount < -100 || $amount > 100) {
            $validator->errors()->add($key, 'The amount must be a number between -100 and 100.');
        }
    }

    /** A crop `{x, y, w, h}` of four 0..1 canvas fractions. */
    private function validateCropRect(Validator $validator, mixed $rect, string $key): void
    {
        if (!is_array($rect)) {
            $validator->errors()->add($key, 'The crop rect must be an object of x, y, w, h fractions.');

            return;
        }

        foreach (['x', 'y', 'w', 'h'] as $edge) {
            $value = $rect[$edge] ?? null;

            if (!is_numeric($value) || $value < 0 || $value > 1) {
                $validator->errors()->add($key . '.' . $edge, 'The ' . $edge . ' fraction must be a number between 0 and 1.');
            }
        }
    }

    /** A rotation as `quarterTurns` in 1..3 (90 / 180 / 270 degrees). */
    private function requireQuarterTurns(Validator $validator, mixed $turns, string $key): void
    {
        if (!is_int($turns) || $turns < 1 || $turns > 3) {
            $validator->errors()->add($key, 'The rotation must be 1, 2 or 3 quarter turns.');
        }
    }

    /** A flip `axis`: horizontal or vertical. */
    private function requireAxis(Validator $validator, mixed $axis, string $key): void
    {
        if (!in_array($axis, ['horizontal', 'vertical'], true)) {
            $validator->errors()->add($key, 'The flip axis must be horizontal or vertical.');
        }
    }

    /**
     * An optional mask REFERENCE (a brushed inpainting mask). Absent is fine; present must be a scalar id
     * or an object ref — the actual mask bytes are resolved at execution (sub-stage 2).
     */
    private function validateMask(Validator $validator, mixed $mask, string $key): void
    {
        if ($mask === null) {
            return;
        }

        if (!is_string($mask) && !is_array($mask)) {
            $validator->errors()->add($key, 'The mask reference must be an id or an object.');
        }
    }

    /** Add an error under $key unless $value is a non-empty string. */
    private function requireNonEmptyString(Validator $validator, mixed $value, string $key, string $message): void
    {
        if (!is_string($value) || $value === '') {
            $validator->errors()->add($key, $message);
        }
    }

    /**
     * The names of the DECLARED slots whose descriptor base is `file` — the only slots a `from_slot` base
     * may name. A malformed slot is skipped (its own descriptor error is reported elsewhere).
     *
     * @param  array<int, mixed>  $slots
     * @return array<int, string>
     */
    private function fileSlotNames(array $slots): array
    {
        $names = [];

        foreach ($slots as $slot) {
            if (!is_array($slot) || !is_string($slot['name'] ?? null)) {
                continue;
            }

            $descriptor = $slot['descriptor'] ?? null;

            if (is_array($descriptor) && ($descriptor['base'] ?? null) === 'file') {
                $names[] = $slot['name'];
            }
        }

        return $names;
    }
}
