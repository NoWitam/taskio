<?php

namespace App\Modules\Generator\Enums;

/**
 * The KIND of a content-type PART — the CLOSED behavioral vocabulary a Template's content recipe is
 * built from (D8). ALL editing / validation / rendering switches on this kind, NEVER on the
 * content-type id: a user-created content type only RECOMBINES these kinds, so it costs the
 * editor/validators/renderer ZERO rework. A genuinely new kind is a deliberate future change.
 *
 *   - text_body   authored POST body: static text + slot values + inline `@[ai-text]` blocks
 *                 (the whole thing is markdown carrying the SAME `@[variable]` directives).
 *   - image_plan  a MEDIA plan for one image: a base (disk_file / from_slot / ai_generate) + an
 *                 ordered FILTER CHAIN (pixel ops ∪ ai_edit prompts). Mirrors the variable pipeline.
 *   - script      a video SCENARIO text — a text_body-like markdown body (own kind so the editor can
 *                 label + arrange it distinctly from a post body).
 *   - scene_plan  an ORDERED list of scenes, each = narration (text_body-like) + an OPTIONAL image_plan
 *                 (D4). LEGACY: no longer authored (dropped from the video_script composition), but existing
 *                 session snapshots still reference it, so the executor/refiner keep rendering it.
 *   - shot_list   the STRUCTURED short-video script (video_script rework Phase B): an authored creative
 *                 `{brief:{markdown}}` (a text_body-like body — static text + slot values + `parts.*` +
 *                 if-blocks) is fed to ONE structured AI call that returns a coherent HOOK, an ORDERED list
 *                 of SHOTS (each with an on-screen VISUAL + spoken VOICEOVER + a SECONDS integer) and a CTA.
 *                 Parsed to JSON, defensively + fail-soft; the flattened readable text is its `parts.*`
 *                 contribution. Not a plain body (isBody() = false) — it renders through the ShotListRenderer.
 *   - storyboard  the executor-iterated image plan of a shot_list (video_script rework Phase B): ONE
 *                 AI-generated image PER shot, NESTED under `storyboard.<i>` exactly like `scene_plan.<i>`.
 *                 Reads the sibling shot_list's STRUCTURED shots by INTRA-COMPOSITION (a direct result read,
 *                 not via `parts.*`); each shot image = an `ai_generate` base (authored `style` + the shot's
 *                 `visual`) + an OPTIONAL authored filter chain. Per-shot fail-soft + per-shot refinable.
 *
 * A string-backed enum so the value is the wire id the FE mirrors and the `content` map / registry store.
 */
enum PartKind: string
{
    case TEXT_BODY = 'text_body';
    case IMAGE_PLAN = 'image_plan';
    case SCRIPT = 'script';
    case SCENE_PLAN = 'scene_plan';
    case SHOT_LIST = 'shot_list';
    case STORYBOARD = 'storyboard';

    /**
     * Whether this kind carries a text_body-like markdown body (resolved through the shared resolver).
     * `script` renders exactly like a `text_body`; the two share the body-directive validation + render.
     */
    public function isBody(): bool
    {
        return $this === self::TEXT_BODY || $this === self::SCRIPT;
    }
}
