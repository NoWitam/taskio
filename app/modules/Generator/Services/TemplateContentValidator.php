<?php

namespace App\Modules\Generator\Services;

use App\Modules\Generator\Enums\PartKind;
use App\Modules\Generator\Support\ContentTypeDefinition;
use App\Modules\Generator\Support\ContentTypePart;
use Illuminate\Contracts\Validation\Validator;

/**
 * The single write-time authority for a TEMPLATE's SLOTS + per-part CONTENT — the FormRequest's
 * withValidator delegate. It is DATA-DRIVEN by the selected {@see ContentTypeDefinition}: validate the
 * (content-type-shared) slots ONCE, then iterate the type's declared PARTS and check each part's content
 * BY KIND (D8 — never by content-type id). Reject an unknown part key and a missing REQUIRED part.
 *
 * Per kind:
 *   - text_body / script  the part is `{markdown}` — a body whose `@[variable]` directives are validated
 *                         via {@see TemplateSlotValidator::validateBody} (relocated under
 *                         `content.<part>.markdown`).
 *   - image_plan          the part is a base + filter chain — validated via {@see ImagePlanValidator}.
 *   - scene_plan          the part is `{scenes:[{narration, image_plan?}]}` — each scene's narration is a
 *                         body, its optional image is an image plan (D4).
 *
 * FAIL-CLOSED at write: a malformed content shape / unknown part / non-type-checking directive / bad image
 * plan is a 422, so the CRUD write path is the ONE gate a template passes and it can never persist a recipe
 * the resolver / renderer cannot handle. The `content_type` itself is pinned to a known id by the request's
 * `in:` rule, so a null definition here means the request already reported it (no double error).
 */
class TemplateContentValidator
{
    public function __construct(
        private ContentTypeRegistry $registry,
        private TemplateSlotValidator $slots,
        private ImagePlanValidator $imagePlans,
    ) {}

    /**
     * Validate a pending template's slots + content against its content type, appending errors to
     * $validator. Reads the raw request inputs (any shape) and shapes them defensively.
     */
    public function validate(Validator $validator, mixed $contentType, mixed $content, mixed $slots): void
    {
        // The slots are shared across every part, so validate them ONCE and reuse the declared names.
        $declaredNames = $this->slots->validateSlots($validator, $slots, 'slots');
        $slotList = is_array($slots) ? $slots : [];

        $definition = is_string($contentType) ? $this->registry->find($contentType) : null;

        if ($definition === null) {
            return; // the request's `in:` rule already reported an unknown content_type
        }

        if (!is_array($content)) {
            $validator->errors()->add('content', 'The content must be an object keyed by part.');

            return;
        }

        $this->rejectUnknownParts($validator, $definition, $content);

        // Iterate the parts IN DECLARED ORDER, accumulating a CUMULATIVE set of EARLIER part keys (Phase A,
        // cross-part context). Each part's bodies are validated with the parts declared BEFORE it as the
        // referenceable `parts.<key>` set — so a `@[variable]` over an earlier part type-checks and a
        // FORWARD/SELF/UNKNOWN part reference is a 422 (fail-closed, acyclic by construction).
        $earlierPartKeys = [];

        foreach ($definition->parts as $part) {
            $this->validatePart($validator, $part, $content[$part->key] ?? null, $slotList, $declaredNames, $earlierPartKeys, 'content.' . $part->key);
            $earlierPartKeys[] = $part->key;
        }
    }

    /** An unknown `content.<key>` — not a declared part of the selected type — is a definite mistake. */
    private function rejectUnknownParts(Validator $validator, ContentTypeDefinition $definition, array $content): void
    {
        $partKeys = $definition->partKeys();

        foreach (array_keys($content) as $key) {
            if (!in_array((string) $key, $partKeys, true)) {
                $validator->errors()->add('content.' . $key, 'Unknown content part: ' . $key . '.');
            }
        }
    }

    /**
     * Validate one PART's content by its kind. A required part missing its content is a 422; an optional
     * absent part is skipped.
     *
     * @param  array<int, mixed>  $slots
     * @param  array<int, string>  $declaredNames
     * @param  array<int, string>  $earlierPartKeys  the parts declared BEFORE this one (`parts.<key>` scope)
     */
    private function validatePart(Validator $validator, ContentTypePart $part, mixed $content, array $slots, array $declaredNames, array $earlierPartKeys, string $key): void
    {
        if ($content === null) {
            if ($part->required) {
                $validator->errors()->add($key, 'The ' . $part->label . ' part is required.');
            }

            return;
        }

        match ($part->kind) {
            PartKind::TEXT_BODY, PartKind::SCRIPT => $this->validateBodyPart($validator, $content, $slots, $declaredNames, $earlierPartKeys, $key),
            PartKind::IMAGE_PLAN => $this->imagePlans->validate($validator, $content, $slots, $declaredNames, $earlierPartKeys, $key),
            PartKind::SCENE_PLAN => $this->validateScenePlan($validator, $content, $slots, $declaredNames, $earlierPartKeys, $key),
            PartKind::SHOT_LIST => $this->validateShotList($validator, $content, $slots, $declaredNames, $earlierPartKeys, $key),
            PartKind::STORYBOARD => $this->validateStoryboard($validator, $content, $slots, $declaredNames, $earlierPartKeys, $key),
        };
    }

    /**
     * A shot_list part = `{brief:{markdown}}` (Phase B): the creative brief is a text_body-like body whose
     * `@[variable]` / `parts.*` directives are validated by the shared body authority (relocated under
     * `content.<part>.brief.markdown`), so an author can steer the shot list with slots / earlier parts.
     *
     * @param  array<int, mixed>  $slots
     * @param  array<int, string>  $declaredNames
     * @param  array<int, string>  $earlierPartKeys
     */
    private function validateShotList(Validator $validator, mixed $content, array $slots, array $declaredNames, array $earlierPartKeys, string $key): void
    {
        if (!is_array($content)) {
            $validator->errors()->add($key, 'The shot list part must be an object.');

            return;
        }

        $brief = is_array($content['brief'] ?? null) ? ($content['brief']['markdown'] ?? null) : null;
        $this->slots->validateBody($validator, $brief, $slots, $declaredNames, $key . '.brief.markdown', $earlierPartKeys);
    }

    /**
     * A storyboard part = `{style?:{markdown}, filters?:[…], max_shots?:int}` (Phase B + the direction layer):
     * an OPTIONAL resolvable style prompt (a body, so its directives are validated), an OPTIONAL authored
     * filter chain applied to EACH shot image (validated by the SAME image filter-chain authority), and an
     * OPTIONAL per-template shot cap. All optional — a storyboard need author none of them.
     *
     * @param  array<int, mixed>  $slots
     * @param  array<int, string>  $declaredNames
     * @param  array<int, string>  $earlierPartKeys
     */
    private function validateStoryboard(Validator $validator, mixed $content, array $slots, array $declaredNames, array $earlierPartKeys, string $key): void
    {
        if (!is_array($content)) {
            $validator->errors()->add($key, 'The storyboard part must be an object.');

            return;
        }

        if (($content['style'] ?? null) !== null) {
            $style = is_array($content['style']) ? ($content['style']['markdown'] ?? null) : null;
            $this->slots->validateBody($validator, $style, $slots, $declaredNames, $key . '.style.markdown', $earlierPartKeys);
        }

        if (($content['filters'] ?? null) !== null) {
            $this->imagePlans->validateFilters($validator, $content['filters'], $slots, $declaredNames, $earlierPartKeys, $key . '.filters');
        }

        $this->validateMaxShots($validator, $content['max_shots'] ?? null, $key . '.max_shots');
    }

    /**
     * The OPTIONAL authored shot cap: a whole number between 1 and the PLATFORM CEILING
     * (`generator.storyboard_max_shots`). An author may only ever TIGHTEN the ceiling — a template asking for
     * more than the platform allows is a 422 here rather than a silent truncation at run time (the run's
     * effective cap is min(authored, ceiling), so a stale over-ceiling value could never take effect anyway).
     * Absent/null is fine (the run then uses the ceiling); anything non-integer is rejected fail-closed.
     */
    private function validateMaxShots(Validator $validator, mixed $maxShots, string $key): void
    {
        if ($maxShots === null) {
            return;
        }

        $ceiling = max(1, (int) config('generator.storyboard_max_shots', 8));

        if (!is_int($maxShots) || $maxShots < 1 || $maxShots > $ceiling) {
            $validator->errors()->add($key, 'The maximum number of shots must be a whole number between 1 and ' . $ceiling . '.');
        }
    }

    /**
     * A text_body / script part = `{markdown}`. The markdown's directives are validated by the shared body
     * authority, relocated under `content.<part>.markdown`.
     *
     * @param  array<int, mixed>  $slots
     * @param  array<int, string>  $declaredNames
     * @param  array<int, string>  $earlierPartKeys
     */
    private function validateBodyPart(Validator $validator, mixed $content, array $slots, array $declaredNames, array $earlierPartKeys, string $key): void
    {
        if (!is_array($content)) {
            $validator->errors()->add($key, 'The content part must be an object.');

            return;
        }

        $this->slots->validateBody($validator, $content['markdown'] ?? null, $slots, $declaredNames, $key . '.markdown', $earlierPartKeys);
    }

    /**
     * A scene_plan part = `{scenes:[{narration:{markdown}, image_plan?}]}` (D4). Each scene's narration is a
     * body; its optional image_plan reuses the image-plan authority. Reserved-now / lean authoring: the
     * scene shape is modeled and validated, but a v1 template may leave the list empty.
     *
     * @param  array<int, mixed>  $slots
     * @param  array<int, string>  $declaredNames
     * @param  array<int, string>  $earlierPartKeys
     */
    private function validateScenePlan(Validator $validator, mixed $content, array $slots, array $declaredNames, array $earlierPartKeys, string $key): void
    {
        $scenes = is_array($content) ? ($content['scenes'] ?? null) : null;

        if (!is_array($scenes) || !array_is_list($scenes)) {
            $validator->errors()->add($key . '.scenes', 'The scenes must be an ordered list.');

            return;
        }

        foreach ($scenes as $i => $scene) {
            $sceneKey = $key . '.scenes.' . $i;

            if (!is_array($scene)) {
                $validator->errors()->add($sceneKey, 'Each scene must be an object.');

                continue;
            }

            // The narration is a text_body-like body ({markdown}); its directives are validated the same way,
            // with the EARLIER parts (e.g. the script) referenceable as `parts.<key>`.
            $narration = is_array($scene['narration'] ?? null) ? ($scene['narration']['markdown'] ?? null) : null;
            $this->slots->validateBody($validator, $narration, $slots, $declaredNames, $sceneKey . '.narration.markdown', $earlierPartKeys);

            // A scene's image is OPTIONAL; when present it is a full image plan (its prompts may reference the
            // earlier parts too).
            if (($scene['image_plan'] ?? null) !== null) {
                $this->imagePlans->validate($validator, $scene['image_plan'], $slots, $declaredNames, $earlierPartKeys, $sceneKey . '.image_plan');
            }
        }
    }
}
