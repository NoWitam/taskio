<?php

namespace App\Modules\Generator\Services;

use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Enums\PartKind;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Support\ContentTypePart;

/**
 * Projects a finished session's per-part `results` into ONE assembled TEXT — the "gotowy post" as a string.
 *
 * Until now the final piece was composed ONLY in the FE (`resources/js/next/pages/generator/session/FinalPostBody.vue`),
 * which is fine for a human reading a card but useless to a SERVER-SIDE consumer (an automated run that must
 * output the produced copy, a future publisher). This is the server-side mirror of that component's
 * COMPOSITION rules — its SIBLING, which must stay in sync (a fixture test pins the two together; change one
 * and update the other).
 *
 * THE RULES — CONTENT-EQUIVALENT to the component for every CURRENT composition, and SNAPSHOT-AUTHORITATIVE
 * where the FE is CATALOG-authoritative (rule 2). The two are not literally identical: for a LEGACY snapshot
 * whose parts a later recomposition changed — a pre-rework `video_script`, say — the FE renders nothing while
 * this projects the parts the session was actually created with, which is the better answer for a server-side
 * consumer. Treat the component as the sibling to keep in sync, not as a byte-for-byte contract:
 *   1. Nothing is assembled unless the session is READY and carries results — exactly the component's
 *      `isReady` gate (a draft/generating/failed session renders its empty state) → ''.
 *   2. Parts are walked in DECLARED ORDER, from the SNAPSHOT ({@see ContentTypeRegistry::partsForSnapshot}),
 *      so a legacy snapshot projects the parts it was created with — never a later recomposition's.
 *   3. Only a part that actually PRODUCED a result contributes (the component's `blocks` filter).
 *   4. Per KIND, the TEXT-BEARING contribution:
 *        - text_body / script  the rendered `text` (the component's MarkdownViewer body),
 *        - shot_list           the readable flattening `text` (hook / `Shot n: visual / voiceover (Ns)` / cta),
 *        - scene_plan          each scene's `narration`, in order (the component renders every narration),
 *        - image_plan          NOTHING — an image-only part,
 *        - storyboard          NOTHING — image-only; its readable script is its sibling shot_list's `text`,
 *                              so including the per-shot visuals would duplicate it.
 *   5. FAIL-SOFT: a missing, `failed` or empty part is SKIPPED, never a throw and never an error string —
 *      the component shows failures as UI prose (in red), which is chrome, not content.
 *   6. Each block is TRIMMED and the blocks join with a BLANK LINE (so no part leaves dangling whitespace).
 *
 * The one DELIBERATE divergence from the component is CHROME: the FE wraps each block in a localized section
 * heading ("POST BODY") and per-item captions ("Scene 2", "Shot 3") because it is a visual card. A projection
 * consumed by another system must be the CONTENT only — a `post`'s projection is its body, nothing else.
 *
 * A pure read (no query, no write, no AI): everything it needs is already hydrated on the row.
 */
class SessionContentProjector
{
    public function __construct(
        private ContentTypeRegistry $registry,
    ) {}

    /**
     * The assembled final text of $session, or '' when there is nothing to assemble (not ready, no results,
     * or every part image-only/failed). Never throws.
     */
    public function project(GenerationSession $session): string
    {
        if ($session->status !== GenerationSessionStatus::Ready || !is_array($session->results)) {
            return '';
        }

        $results = $session->results;
        $snapshot = is_array($session->recipe_snapshot) ? $session->recipe_snapshot : [];
        $content = is_array($snapshot['content'] ?? null) ? $snapshot['content'] : [];

        $blocks = [];

        foreach ($this->registry->partsForSnapshot((string) $session->content_type, $content) as $part) {
            $result = $results[$part->key] ?? null;

            if (!is_array($result)) {
                continue;
            }

            $block = trim($this->contribution($part, $result));

            if ($block !== '') {
                $blocks[] = $block;
            }
        }

        return implode("\n\n", $blocks);
    }

    /**
     * One part's TEXT contribution (see the class rules), or '' when the kind is image-only or the result is
     * not `ok`.
     *
     * @param  array<string, mixed>  $result
     */
    private function contribution(ContentTypePart $part, array $result): string
    {
        if (($result['status'] ?? null) !== 'ok') {
            return '';
        }

        return match ($part->kind) {
            PartKind::TEXT_BODY, PartKind::SCRIPT, PartKind::SHOT_LIST => is_string($result['text'] ?? null) ? $result['text'] : '',
            PartKind::SCENE_PLAN => $this->scenesText($result),
            // Image-only kinds contribute no text (the storyboard's script IS the shot_list's flattening).
            PartKind::IMAGE_PLAN, PartKind::STORYBOARD => '',
        };
    }

    /**
     * A scene_plan's narrations in scene order, blank-line separated — the component renders every scene's
     * narration body. A scene whose narration failed resolved to '' at render time and is simply skipped.
     * (A scene_plan part is `ok` as a whole even when individual scenes failed — per-scene fail-soft.)
     *
     * @param  array<string, mixed>  $result
     */
    private function scenesText(array $result): string
    {
        $narrations = [];

        foreach (is_array($result['scenes'] ?? null) ? $result['scenes'] : [] as $scene) {
            $narration = is_array($scene) && is_string($scene['narration'] ?? null) ? trim($scene['narration']) : '';

            if ($narration !== '') {
                $narrations[] = $narration;
            }
        }

        return implode("\n\n", $narrations);
    }
}
