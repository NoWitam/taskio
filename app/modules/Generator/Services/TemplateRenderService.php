<?php

namespace App\Modules\Generator\Services;

use App\Modules\Generator\Enums\PartKind;
use App\Modules\Generator\Support\ContentTypePart;
use App\Modules\Variables\Services\VariableResolver;
use Throwable;

/**
 * The FAITHFUL, PER-PART template PREVIEW: for the selected content type it renders each declared PART by
 * KIND (D8), so an author sees the finished post exactly as a real generation would shape it (minus the
 * real AI, which lands in sub-stage 2):
 *   - text_body / script  the markdown BODY resolved through the SAME shared {@see VariableResolver} the
 *                         workflow run path uses (directives, if-blocks, pipelines, custom functions, the
 *                         NUL-mask injection guard) → `{rendered}`. `@[ai-text]` is INERT but LABELED: the
 *                         no-op generator emits a `[AI: …]` placeholder (not empty), so the author sees
 *                         WHERE the AI lands and against WHICH resolved prompt.
 *   - image_plan          a PLAN SUMMARY → `{plan}`: the base + the ordered filter chain, with every prompt
 *                         string directive-resolved through the SAME resolver — NO image is executed here.
 *   - scene_plan          a per-scene summary → `{plan}`: each scene's resolved narration + its image plan.
 *
 * Every path is FAIL-SOFT — an unresolvable reference becomes null/'' and the render NEVER throws (a hard
 * assert_present in a preview is caught and degraded to an empty render, not a 500). The response is
 * `{parts:{<partKey>:{rendered}|{plan}}}`.
 */
class TemplateRenderService
{
    public function __construct(
        private VariableResolver $resolver,
        private TemplateVariableCatalog $catalog,
        private ContentTypeRegistry $registry,
    ) {}

    /**
     * Render every part of $contentType's recipe against the sample $slotValues (keyed by slot name) + the
     * workspace globals, returning `{parts:{<key>:…}}`. An unknown content type yields no parts (fail-soft).
     *
     * $parts overrides WHICH parts are rendered. It defaults to the LIVE registry definition's parts, which
     * is right for the template editor's preview (it previews the template being authored). A SNAPSHOT-driven
     * caller — the creative-direction derivation, which previews an already-created session's stored recipe —
     * must instead pass {@see ContentTypeRegistry::partsForSnapshot}, or a snapshot whose composition has
     * since changed (a pre-Phase-B `video_script` still carrying `script`/`scene_plan`) renders as EMPTY
     * while the executor happily renders those legacy parts. The rendering itself is kind-driven, so a legacy
     * part needs nothing else.
     *
     * @param  array<string, mixed>  $content  the per-part authored content map
     * @param  array<int, mixed>  $slots  the declared slots (for the runtime type map)
     * @param  array<string, mixed>  $slotValues  the sample values keyed by slot name
     * @param  array<int, ContentTypePart>|null  $parts  the parts to render (default: the definition's)
     * @return array{parts: array<string, array<string, mixed>>}
     */
    public function render(string $contentType, array $content, array $slots, array $slotValues, ?array $parts = null): array
    {
        $definition = $this->registry->find($contentType);

        if ($definition === null) {
            return ['parts' => []];
        }

        // Build the resolver context ONCE (the same for every part) through the SHARED builder a real
        // generation session also uses — so a preview and a run can never diverge on what a slot /
        // global / custom function resolves to (context-building lives in ONE place, not duplicated).
        ['context' => $execContext, 'typeMap' => $typeMap] = $this->catalog->executionContext($slots, $slotValues);

        $rendered = [];

        foreach ($parts ?? $definition->parts as $part) {
            $rendered[$part->key] = $this->renderPart($part, $content[$part->key] ?? null, $execContext, $typeMap);
        }

        return ['parts' => $rendered];
    }

    /**
     * Render one part by its kind.
     *
     * @param  array<string, mixed>  $execContext
     * @param  array<string, mixed>  $typeMap
     * @return array<string, mixed>
     */
    private function renderPart(ContentTypePart $part, mixed $content, array $execContext, array $typeMap): array
    {
        return match ($part->kind) {
            PartKind::TEXT_BODY, PartKind::SCRIPT => ['rendered' => $this->renderBody($this->bodyMarkdown($content), $execContext, $typeMap)],
            PartKind::IMAGE_PLAN => ['plan' => $this->imagePlanSummary($content, $execContext, $typeMap)],
            PartKind::SCENE_PLAN => ['plan' => $this->scenePlanSummary($content, $execContext, $typeMap)],
            // Phase B: a shot_list preview resolves the creative BRIEF (no AI runs in a preview, so the shots
            // themselves are produced only in a real run); a storyboard preview summarizes its resolved style +
            // authored filter chain — the FE labels where the per-shot images will land.
            PartKind::SHOT_LIST => ['brief' => $this->renderBody($this->nestedMarkdown($content, 'brief'), $execContext, $typeMap)],
            PartKind::STORYBOARD => ['plan' => $this->storyboardSummary($content, $execContext, $typeMap)],
        };
    }

    /**
     * A storyboard PLAN SUMMARY (Phase B): the resolved style prompt + the authored per-shot filter chain
     * (every prompt directive-resolved) + the OPTIONAL authored shot cap, so the preview shows how many
     * frames this recipe will produce. NO image is executed. Fail-soft: a malformed config yields empties
     * (`max_shots` is null both when unauthored and when malformed — the write validator is the gate).
     *
     * @param  array<string, mixed>  $execContext
     * @param  array<string, mixed>  $typeMap
     * @return array{style: string, filters: array<int, array<string, mixed>>, max_shots: int|null}
     */
    private function storyboardSummary(mixed $config, array $execContext, array $typeMap): array
    {
        if (!is_array($config)) {
            return ['style' => '', 'filters' => [], 'max_shots' => null];
        }

        return [
            'style' => $this->renderBody($this->nestedMarkdown($config, 'style'), $execContext, $typeMap),
            'filters' => $this->filterSummaries($config['filters'] ?? null, $execContext, $typeMap),
            'max_shots' => is_int($config['max_shots'] ?? null) ? $config['max_shots'] : null,
        ];
    }

    /** The `{<field>:{markdown}}` body of a nested authored field (shot_list `brief`, storyboard `style`), or ''. */
    private function nestedMarkdown(mixed $content, string $field): string
    {
        $nested = is_array($content) && is_array($content[$field] ?? null) ? $content[$field] : null;

        return $nested !== null ? $this->stringOrEmpty($nested['markdown'] ?? null) : '';
    }

    /**
     * Resolve a markdown body to its rendered string, fail-soft to '' on any resolution error (a hard
     * assert_present degrades to an empty preview rather than a 500 — a preview is advisory, never a run).
     *
     * @param  array<string, mixed>  $execContext
     * @param  array<string, mixed>  $typeMap
     */
    private function renderBody(string $markdown, array $execContext, array $typeMap): string
    {
        if ($markdown === '') {
            return '';
        }

        try {
            return $this->stringify($this->resolver->resolveString($markdown, $execContext, $typeMap));
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * A faithful IMAGE-PLAN summary: the base + the ordered filter chain, every prompt string
     * directive-resolved through the same resolver. NO image is executed. Fail-soft: a malformed config
     * yields an empty plan.
     *
     * @param  array<string, mixed>  $execContext
     * @param  array<string, mixed>  $typeMap
     * @return array{base: array<string, mixed>|null, filters: array<int, array<string, mixed>>}
     */
    private function imagePlanSummary(mixed $config, array $execContext, array $typeMap): array
    {
        if (!is_array($config)) {
            return ['base' => null, 'filters' => []];
        }

        return [
            'base' => $this->baseSummary($config['base'] ?? null, $execContext, $typeMap),
            'filters' => $this->filterSummaries($config['filters'] ?? null, $execContext, $typeMap),
        ];
    }

    /**
     * The base summary: its kind + a human label, with the ai_generate prompt directive-resolved and the
     * from_slot / disk_file references surfaced.
     *
     * @param  array<string, mixed>  $execContext
     * @param  array<string, mixed>  $typeMap
     * @return array<string, mixed>|null
     */
    private function baseSummary(mixed $base, array $execContext, array $typeMap): ?array
    {
        if (!is_array($base)) {
            return null;
        }

        $kind = is_string($base['kind'] ?? null) ? $base['kind'] : null;

        return match ($kind) {
            'disk_file' => ['kind' => 'disk_file', 'label' => 'Disk file', 'file' => $base['file'] ?? null],
            'from_slot' => ['kind' => 'from_slot', 'label' => 'From slot: ' . (is_string($base['slot'] ?? null) ? $base['slot'] : '?'), 'slot' => $base['slot'] ?? null],
            'ai_generate' => ['kind' => 'ai_generate', 'label' => 'AI image', 'prompt' => $this->renderBody($this->stringOrEmpty($base['prompt'] ?? null), $execContext, $typeMap)],
            default => ['kind' => $kind, 'label' => 'Unknown base'],
        };
    }

    /**
     * The ordered filter summaries — a pixel op carries its op + params; an ai_edit its directive-resolved
     * prompt. A malformed entry is skipped (fail-soft).
     *
     * @param  array<string, mixed>  $execContext
     * @param  array<string, mixed>  $typeMap
     * @return array<int, array<string, mixed>>
     */
    private function filterSummaries(mixed $filters, array $execContext, array $typeMap): array
    {
        if (!is_array($filters) || !array_is_list($filters)) {
            return [];
        }

        $summaries = [];

        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            $kind = $filter['kind'] ?? null;

            if ($kind === 'pixel') {
                $summaries[] = [
                    'kind' => 'pixel',
                    'op' => $filter['op'] ?? null,
                    'label' => is_string($filter['op'] ?? null) ? $filter['op'] : 'pixel',
                    'params' => is_array($filter['params'] ?? null) ? $filter['params'] : null,
                ];
            } elseif ($kind === 'ai_edit') {
                $summaries[] = [
                    'kind' => 'ai_edit',
                    'label' => 'AI edit',
                    'prompt' => $this->renderBody($this->stringOrEmpty($filter['prompt'] ?? null), $execContext, $typeMap),
                ];
            }
        }

        return $summaries;
    }

    /**
     * A per-scene SCENE-PLAN summary: each scene's resolved narration + its optional image plan (D4).
     *
     * @param  array<string, mixed>  $execContext
     * @param  array<string, mixed>  $typeMap
     * @return array{scenes: array<int, array<string, mixed>>}
     */
    private function scenePlanSummary(mixed $config, array $execContext, array $typeMap): array
    {
        $scenes = is_array($config) ? ($config['scenes'] ?? null) : null;

        if (!is_array($scenes) || !array_is_list($scenes)) {
            return ['scenes' => []];
        }

        $summaries = [];

        foreach ($scenes as $scene) {
            if (!is_array($scene)) {
                continue;
            }

            $narration = is_array($scene['narration'] ?? null) ? ($scene['narration']['markdown'] ?? null) : null;
            $image = ($scene['image_plan'] ?? null) !== null ? $this->imagePlanSummary($scene['image_plan'], $execContext, $typeMap) : null;

            $summaries[] = [
                'narration' => $this->renderBody($this->stringOrEmpty($narration), $execContext, $typeMap),
                'image' => $image,
            ];
        }

        return ['scenes' => $summaries];
    }

    /** The `{markdown}` of a text_body / script part, coerced to a string (absent/malformed → ''). */
    private function bodyMarkdown(mixed $content): string
    {
        return is_array($content) ? $this->stringOrEmpty($content['markdown'] ?? null) : '';
    }

    /** Coerce an optional value to a string ('' when absent/non-string). */
    private function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Coerce the resolver result to a string. resolveString over a markdown body already returns a string
     * (embedded directives stringify); this only guards the rare standalone-typed result (a bare
     * `{{token}}`) so a part always emits `rendered: string`.
     */
    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }
}
