<?php

namespace App\Modules\Generator\Services;

use App\Modules\Generator\Agents\CreativeDirectionAgent;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Support\CreativeDirection;
use App\Modules\Generator\Support\JsonObjectExtractor;
use App\Modules\Variables\Services\AiTextGenerationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Derives the ONE {@see CreativeDirection} a full generation run is made to (the direction layer). Exactly
 * ONE metered `ai_text` call per FULL run — never on a regenerate/refine/per-shot op, which reuse the stored
 * direction — and NEVER throws: any failure returns null and the run renders exactly as it did before this
 * layer existed (fail-soft by construction).
 *
 * THE INPUT is the AUTHORED RECIPE, not the resolved brief. It is built from
 * {@see TemplateRenderService::render} — the SAME faithful per-part preview the template editor shows, which
 * the module provider binds to the NO-OP ai-text generator — fed the session's SNAPSHOT + its filled slot
 * values. That choice is load-bearing on two counts:
 *   - ZERO extra AI spend and deterministic: `@[ai-text]` renders as a `[AI: <resolved prompt>]` PLACEHOLDER,
 *     so the direction sees the author's INSTRUCTION to the writer (with its slots substituted). Deriving
 *     from a RESOLVED brief instead would have re-run that nested ai-text call — billing it twice.
 *   - FIDELITY: the author's hard constraints ("1–2 minutes", "must open on the product") survive verbatim
 *     in their own words, instead of arriving as a model's paraphrase of them.
 * A size-capped digest of the SCALAR slot values rides along (file/composite slots excluded — a file
 * snapshot is bytes and ids, nothing a director can use). The preview is driven by the SNAPSHOT's parts
 * ({@see ContentTypeRegistry::partsForSnapshot}), the same authority the executor uses, so a legacy snapshot
 * is directed by the body it will actually render. NO RECIPE, NO DIRECTION: a digest alone never triggers a
 * call (see {@see input}), so nothing is ever invented — or billed — from slot values with no recipe.
 *
 * THE OUTPUT is untrusted (a model wrote it from user-supplied values): it is defensively parsed with the
 * SHARED {@see JsonObjectExtractor} (the same fence-strip + string-aware brace walk the shot list uses, not a
 * fork) and then laundered through {@see CreativeDirection::fromArray}, which is the security boundary.
 *
 * NOTHING here is ever logged but the FACT of a failure — not the recipe, not the slot values, not the
 * derived direction.
 */
class CreativeDirectionService
{
    /** Max SCALAR slot entries in the digest (a bound on the prompt, not on the recipe). */
    private const MAX_SLOT_ENTRIES = 30;

    /** Max characters of ONE slot value in the digest. */
    private const MAX_SLOT_VALUE_CHARS = 300;

    public function __construct(
        private TemplateRenderService $preview,
        private AiTextGenerationService $generator,
        private ContentTypeRegistry $registry,
    ) {}

    /**
     * The workspace's UI language, handed to the agent as a LANGUAGE TIE-BREAKER (never an override — the
     * recipe always wins). Read from the app locale, the same source the schedule assist uses; the derivation
     * runs inside the queued run job, where that resolves to the configured `APP_LOCALE` rather than any
     * per-request locale. Anything other than the two catalogs we ship yields null, which leaves the agent
     * with the pure follow-the-recipe rule.
     */
    private function language(): ?string
    {
        $locale = app()->getLocale();

        return in_array($locale, ['pl', 'en'], true) ? $locale : null;
    }

    /**
     * Derive the run's creative direction, or null when there is nothing to derive from, the model returned
     * nothing usable, or anything at all went wrong. A null input costs NO provider call (and therefore no
     * spend); a failed call is already fail-closed to '' by the shared generator.
     */
    public function derive(GenerationSession $session): ?CreativeDirection
    {
        try {
            $input = $this->input($session);

            if ($input === '') {
                return null;
            }

            $raw = $this->generator->generateWith(
                new CreativeDirectionAgent((string) $session->content_type, $this->language()),
                $input,
                (int) config('generator.direction.max_chars', 4000),
                (int) config('ai.direction_timeout', 30),
            );

            return $this->parse($raw);
        } catch (Throwable $e) {
            // FACTS ONLY: never the recipe, the slot values, or the model reply.
            Log::warning('Creative direction derivation failed; the run proceeds without one', [
                'session_id' => $session->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'at' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return null;
        }
    }

    /**
     * The model input (D8): the NO-OP-previewed authored recipe + the scalar slot digest, size-capped.
     *
     * SNAPSHOT-AUTHORITATIVE, exactly like the executor it directs: the preview is driven by
     * {@see ContentTypeRegistry::partsForSnapshot}, NOT by the live registry composition. A snapshot whose
     * content type has since been recomposed (a pre-Phase-B `video_script` still carrying `script` /
     * `scene_plan`) therefore shows the director the body the RUN will actually render.
     *
     * '' when there is NO RECIPE — and then no call is made at all. That is a deliberate gate, not just a
     * micro-optimization: the director's own rule is to INVENT where the recipe is silent, so deriving from
     * a slot digest ALONE ("topic: Espresso") would bill one call to fabricate a whole creative frame out of
     * nothing and then inject it as BINDING into the run. No recipe → no direction → the run renders exactly
     * as it did before this layer existed.
     */
    private function input(GenerationSession $session): string
    {
        $snapshot = is_array($session->recipe_snapshot) ? $session->recipe_snapshot : [];
        $content = is_array($snapshot['content'] ?? null) ? $snapshot['content'] : [];
        $slots = is_array($snapshot['slots'] ?? null) ? $snapshot['slots'] : [];
        $slotValues = is_array($session->slot_values) ? $session->slot_values : [];
        $contentType = (string) $session->content_type;

        $preview = $this->preview->render(
            $contentType,
            $content,
            $slots,
            $slotValues,
            $this->registry->partsForSnapshot($contentType, $content),
        );
        $recipe = $this->recipeLines(is_array($preview['parts'] ?? null) ? $preview['parts'] : []);

        if ($recipe === '') {
            return '';
        }

        $digest = $this->slotDigest($slots, $slotValues);

        $sections = [
            "Derive the creative direction for the content recipe below.\n",
            "CONTENT RECIPE:\n" . $recipe,
        ];

        if ($digest !== '') {
            $sections[] = "VALUES FILLED IN FOR THIS RUN:\n" . $digest;
        }

        return $this->cap(implode("\n\n", $sections), (int) config('generator.direction.max_input_chars', 6000));
    }

    /**
     * Flatten the per-part preview into readable recipe lines. Each part surfaces whichever shape the preview
     * emitted for its kind — a rendered body, a shot-list brief, or a media PLAN (base/filter prompts, a
     * storyboard style + shot cap, per-scene narrations).
     *
     * @param  array<string, mixed>  $parts
     */
    private function recipeLines(array $parts): string
    {
        $lines = [];

        foreach ($parts as $key => $summary) {
            if (!is_array($summary)) {
                continue;
            }

            $body = [];

            if (is_string($summary['rendered'] ?? null) && trim($summary['rendered']) !== '') {
                $body[] = trim($summary['rendered']);
            }

            if (is_string($summary['brief'] ?? null) && trim($summary['brief']) !== '') {
                $body[] = trim($summary['brief']);
            }

            if (is_array($summary['plan'] ?? null)) {
                $body = array_merge($body, $this->planLines($summary['plan']));
            }

            if ($body !== []) {
                $lines[] = '[' . $key . "]\n" . implode("\n", $body);
            }
        }

        return implode("\n\n", $lines);
    }

    /**
     * The meaningful strings of a media PLAN summary: the storyboard's style + shot cap, an image base and
     * its ai_edit prompts, and a scene plan's narrations (each with its own nested image plan).
     *
     * @param  array<string, mixed>  $plan
     * @return array<int, string>
     */
    private function planLines(array $plan): array
    {
        $lines = [];

        if (is_string($plan['style'] ?? null) && trim($plan['style']) !== '') {
            $lines[] = 'visual style: ' . trim($plan['style']);
        }

        if (is_int($plan['max_shots'] ?? null)) {
            $lines[] = 'at most ' . $plan['max_shots'] . ' shots';
        }

        if (is_array($plan['base'] ?? null)) {
            $base = $plan['base'];
            $prompt = is_string($base['prompt'] ?? null) ? trim($base['prompt']) : '';
            $label = is_string($base['label'] ?? null) ? $base['label'] : 'image';
            $lines[] = 'image: ' . $label . ($prompt === '' ? '' : ' — ' . $prompt);
        }

        foreach ((is_array($plan['filters'] ?? null) ? $plan['filters'] : []) as $filter) {
            if (is_array($filter) && is_string($filter['prompt'] ?? null) && trim($filter['prompt']) !== '') {
                $lines[] = 'image edit: ' . trim($filter['prompt']);
            }
        }

        foreach (array_values(is_array($plan['scenes'] ?? null) ? $plan['scenes'] : []) as $i => $scene) {
            if (!is_array($scene)) {
                continue;
            }

            if (is_string($scene['narration'] ?? null) && trim($scene['narration']) !== '') {
                $lines[] = 'scene ' . ($i + 1) . ': ' . trim($scene['narration']);
            }

            if (is_array($scene['image'] ?? null)) {
                $lines = array_merge($lines, $this->planLines($scene['image']));
            }
        }

        return $lines;
    }

    /**
     * A `name: value` digest of the run's SCALAR slot values. FILE (and any composite) slots are excluded:
     * a file value is ids and metadata, useless to a director and needless prompt weight. Bounded in entry
     * count and per-value length.
     *
     * @param  array<int, mixed>  $slots  the snapshot's declared slots (for the descriptor-based file skip)
     * @param  array<string, mixed>  $values
     */
    private function slotDigest(array $slots, array $values): string
    {
        $fileSlots = [];

        foreach ($slots as $slot) {
            $descriptor = is_array($slot) && is_array($slot['descriptor'] ?? null) ? $slot['descriptor'] : [];

            if (is_string($slot['name'] ?? null) && ($descriptor['base'] ?? null) === 'file') {
                $fileSlots[] = $slot['name'];
            }
        }

        $lines = [];

        foreach ($values as $name => $value) {
            if (count($lines) >= self::MAX_SLOT_ENTRIES) {
                break;
            }

            // Non-scalars (file snapshots, repeaters, objects) are excluded along with declared file slots.
            if (in_array((string) $name, $fileSlots, true) || !is_scalar($value)) {
                continue;
            }

            $text = trim(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);

            if ($text !== '') {
                $lines[] = $name . ': ' . $this->cap($text, self::MAX_SLOT_VALUE_CHARS);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Defensively parse the model reply into a direction: blank → null; otherwise the shared fence-strip +
     * balanced-brace object scan, a json_decode, and the normalizer (which drops unknown keys, caps lengths
     * and strips control characters). Anything unparseable → null, exactly like a failed call.
     */
    private function parse(string $raw): ?CreativeDirection
    {
        if (trim($raw) === '') {
            return null;
        }

        $json = JsonObjectExtractor::extract($raw);

        return $json === null ? null : CreativeDirection::fromArray(json_decode($json, true));
    }

    /** Multibyte-safe hard cap ($max <= 0 disables). */
    private function cap(string $text, int $max): string
    {
        return $max > 0 && mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }
}
