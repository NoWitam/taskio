<?php

namespace App\Modules\Generator\Services;

use App\Modules\Generator\Enums\PartKind;
use App\Modules\Generator\Exceptions\ImageChainException;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Support\ContentTypePart;
use App\Modules\Generator\Support\CreativeDirection;
use App\Modules\Generator\Support\CreativeDirectionContext;
use App\Modules\Generator\Support\SessionVisualIdentity;
use App\Modules\Generator\Support\StoryboardFrame;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Exceptions\AiBudgetExceededException;
use App\Modules\Variables\Services\VariableResolver;
use App\Modules\Variables\Support\AiVoiceContext;
use App\Modules\Variables\Support\MeterContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Renders a generation SESSION's snapshotted recipe into concrete content. For the snapshot's content type it
 * walks each declared PART by KIND and produces a per-part result:
 *   - text_body / script  the markdown BODY resolved through the SAME shared {@see VariableResolver} the
 *                         template PREVIEW uses — but bound (in the module provider) with the REAL
 *                         {@see GeneratorAiTextService}, so `@[ai-text]` runs LIVE (budgeted + metered)
 *                         → `{kind, status:'ok', text, version}`.
 *   - image_plan          EXECUTED server-side (R2 sub-stage 2c): resolve the base → run the filter chain →
 *                         store the produced image as a new VERSION → `{kind, status:'ok',
 *                         image:{mime,width,height,version}, version}` (no bytes in the JSON; the chat previews
 *                         via the serve endpoint), or `{…, status:'failed', error}` fail-soft.
 *   - scene_plan          each scene's narration renders like a body and its OPTIONAL image_plan runs the same
 *                         chain, versioned per scene image → `{kind, status:'ok', scenes:[…]}`.
 *
 * REFINE LOOP (R2 sub-stage 2d): beyond the whole-session {@see execute}, it renders ONE part in isolation for
 * a per-part op — {@see renderPartFromSnapshot} (regenerate: a fresh variation from the SAME snapshot) and
 * {@see renderRefinedPart} (refine: a REVISION of the CURRENT output — an AI text revision, or an AI edit of
 * the current image). The per-part history push / undo bookkeeping lives in {@see GenerationSessionRefiner};
 * this class only RENDERS + stamps each result's version (text: prior+1 from the current result; image: the
 * store's next version), and stores image blobs versioned.
 *
 * DISTRIBUTED STORYBOARD FRAMES: a `storyboard` part is the ONE kind this class no longer renders to
 * completion. Its shot list is text and is produced here as always, but each shot's IMAGE is announced as a
 * `pending` frame ({@see StoryboardFrame}) and rendered by its OWN queue job, because N slow provider calls
 * cannot fit the session job's inviolate 300s window. The frame job re-enters through
 * {@see renderPartFromSnapshot} with the shot's `storyboard.<i>` key, so there is exactly ONE per-shot render
 * path shared by the whole run and the per-shot refine ops.
 *
 * SNAPSHOT-AUTHORITATIVE: it reads ONLY `recipe_snapshot` (+ the session's `slot_values`), never the live
 * template — so a later template edit/delete cannot change a session's output. The exec context + typeMap
 * are built through the SHARED {@see TemplateVariableCatalog::executionContext} (never re-derived here).
 *
 * FAIL-SOFT PER PART: a resolution / base / provider error on ONE part becomes `{status:'failed', error}` with
 * a LOCALIZED, NON-SECRET message (never the prompt, which may carry slot values / the instruction; never a
 * provider body); every OTHER part still runs, and the session as a whole ends `ready`. Only a throw ESCAPING
 * execute() (an infra error) fails the whole run.
 *
 * METER WIRING: the ambient {@see MeterContext} session id is set for the WHOLE execution scope (and cleared
 * in a finally) — for the full run AND each part op — so every ai-text AND ai_image_edit spend the shared meter
 * records is tagged with THIS session. The instruction / current content / prompt are NEVER logged.
 *
 * CREATIVE DIRECTION (the direction layer): a FULL run derives ONE creative direction up front
 * ({@see CreativeDirectionService}) — a single extra metered `ai_text` call, OUTSIDE the per-session ai-text
 * part budget — persists it on the session and publishes it for the whole scope; the ISOLATED part ops READ
 * that stored direction and never derive (zero extra cost, zero drift, a coherent refine). It reaches the
 * deep `@[ai-text]` seam through the ambient {@see CreativeDirectionContext} (set + cleared in the SAME
 * finally as the meter/voice tags) and every executor-reachable consumer — the shot-list renderer, the
 * storyboard image composer, and EVERY authored `ai_generate` image base (a plain `image_plan` part and a
 * scene's image, via {@see directedImagePlan}, so a `post_with_image`'s post and its image are one piece) —
 * as an EXPLICIT parameter. `generator.direction.enabled=false` disables the
 * whole layer: nothing is derived, nothing is read, and every composed prompt is byte-identical to a
 * direction-less run.
 *
 * CHARACTER VISUAL IDENTITY (the bot-look phase): a session DELEGATED to an author with a visual identity
 * carries a FROZEN copy of it — the written identity in the `bot_delegation.visual` overlay, the approved
 * likeness's bytes in {@see SessionIdentityImageStore} — and this class draws from it:
 *   - the SHOT LIST is told who the on-screen creator is, and returns a per-shot `features_character` flag;
 *   - a flagged storyboard frame (and, unless the part says `character: 'never'`, an authored image) is
 *     produced by EDITING that likeness rather than generating from text, so the same person appears twice;
 *   - the character's aesthetic + prohibitions ride EVERY image of the session, flagged or not.
 * Read through ONE gate ({@see visualIdentityFor}) that honours the `generator.visual_identity.enabled` kill
 * switch AND the `enabled` frozen in the overlay (never the live author), and that requires a frozen IMAGE —
 * so a non-delegated run, a delegation without a likeness, and a disabled layer are all byte-identical to a
 * run from before this layer existed.
 */
class GenerationSessionExecutor
{
    public function __construct(
        private VariableResolver $resolver,
        private TemplateVariableCatalog $catalog,
        private ContentTypeRegistry $registry,
        private MeterContext $meterContext,
        private AiVoiceContext $voiceContext,
        private ImageChainExecutor $imageChain,
        private GeneratedImageStore $images,
        private GeneratorAiTextService $aiText,
        private ShotListRenderer $shotList,
        private CreativeDirectionContext $directionContext,
        private CreativeDirectionService $direction,
        private SessionImageBudget $budget,
        private SessionIdentityImageStore $identityImages,
    ) {}

    /**
     * Render every part of the session's snapshotted recipe (a WHOLE-session run), returning the per-part
     * result map. An unknown content type yields an empty map (fail-soft). The meter session tag is set for the
     * whole scope and cleared afterwards.
     *
     * @return array<string, array<string, mixed>>
     */
    public function execute(GenerationSession $session): array
    {
        $this->meterContext->setSession($session->id);
        // ACTOR (R2 sub-stage 4): a queued run has no auth()/run of its own, so tag the meter EXPLICITLY
        // with the session's actor — the bot author when delegated, else the human owner — so every ai-text
        // AND image spend inside the run attributes correctly. Cleared in the SAME finally as the tags below.
        [$meterActorType, $meterActorId] = $session->meterActor();
        $this->meterContext->setActor($meterActorType, $meterActorId);
        // VOICE (R2 sub-stage 3): a delegated session renders every text part + refine + the shot_list
        // voiceover in the bot's SNAPSHOTTED voice; an undelegated one sets null (no change). Cleared in
        // the SAME finally as the meter tag (leak-proof — a later non-delegated spend sees no voice).
        $this->voiceContext->setDirective($session->botVoice());
        // PER-BLOCK AUTHORS: the recipe's authors were resolved ONCE and FROZEN into the snapshot at
        // CREATION, so this is a pure read — no lookup, no live bot, nothing a later bot edit could change.
        // AiVoiceContext ranks a block's own author ABOVE this session voice; a block with no author (every
        // pre-feature recipe) is unaffected. Cleared by the SAME finally — clear() resets BOTH fields.
        $this->voiceContext->setAuthorVoices($session->authorVoices());
        // IMAGE BUDGET: bind the run's PERSISTED `ai_generate`/`ai_edit` ledger for this whole scope. Since a
        // storyboard run is now MANY jobs, an in-process counter could no longer bound it (each frame job
        // would start at zero), so the ceilings are charged against the session row — see SessionImageBudget.
        // Cleared in the SAME finally as the meter/voice/direction tags.
        $this->budget->bind($session->id);

        try {
            // DIRECTION: derived ONCE per full run (or reused if this run already stored one — a redelivery),
            // INSIDE the meter/actor scope so its single ai_text call is session-tagged + actor-attributed.
            $this->directionContext->set($this->directionForFullRun($session));

            return $this->renderParts($session);
        } finally {
            $this->meterContext->clearSession();
            $this->meterContext->clearActor();
            $this->voiceContext->clear();
            $this->directionContext->clear();
            $this->budget->clear();
        }
    }

    /**
     * Render ONE part FRESH from the snapshot recipe + slot values (the regenerate op — a new variation). The
     * result carries a NEW version (text: the current result's version + 1; image: the store's next version,
     * with the produced blob written). Returns null when the content type / part key is unknown (a defensive
     * no-op the refiner leaves the current state untouched for). Meter-scoped like {@see execute}.
     *
     * @return array<string, mixed>|null
     */
    public function renderPartFromSnapshot(GenerationSession $session, string $partKey): ?array
    {
        $this->meterContext->setSession($session->id);
        // ACTOR (R2 sub-stage 4): a queued run has no auth()/run of its own, so tag the meter EXPLICITLY
        // with the session's actor — the bot author when delegated, else the human owner — so every ai-text
        // AND image spend inside the run attributes correctly. Cleared in the SAME finally as the tags below.
        [$meterActorType, $meterActorId] = $session->meterActor();
        $this->meterContext->setActor($meterActorType, $meterActorId);
        // VOICE (R2 sub-stage 3): a delegated session renders every text part + refine + the shot_list
        // voiceover in the bot's SNAPSHOTTED voice; an undelegated one sets null (no change). Cleared in
        // the SAME finally as the meter tag (leak-proof — a later non-delegated spend sees no voice).
        $this->voiceContext->setDirective($session->botVoice());
        // PER-BLOCK AUTHORS: the recipe's authors were resolved ONCE and FROZEN into the snapshot at
        // CREATION, so this is a pure read — no lookup, no live bot, nothing a later bot edit could change.
        // AiVoiceContext ranks a block's own author ABOVE this session voice; a block with no author (every
        // pre-feature recipe) is unaffected. Cleared by the SAME finally — clear() resets BOTH fields.
        $this->voiceContext->setAuthorVoices($session->authorVoices());
        // IMAGE BUDGET: bind the run's PERSISTED `ai_generate`/`ai_edit` ledger for this whole scope. Since a
        // storyboard run is now MANY jobs, an in-process counter could no longer bound it (each frame job
        // would start at zero), so the ceilings are charged against the session row — see SessionImageBudget.
        // Cleared in the SAME finally as the meter/voice/direction tags.
        $this->budget->bind($session->id);

        try {
            // DIRECTION: an isolated op READS the run's stored direction — it never derives (no extra spend,
            // and a refine stays inside the SAME creative frame the full run established).
            $this->directionContext->set($this->storedDirection($session));

            $ctx = $this->renderContext($session, $partKey);

            if ($ctx === null) {
                return null;
            }

            // A `storyboard.<i>` sub-key regenerates ONLY shot i's image (a nested per-shot op — the bare
            // storyboard part regenerates ALL shots through the normal path below).
            $shotRef = $this->storyboardShotRef($ctx['parts'], $partKey);

            if ($shotRef !== null) {
                return $this->renderStoryboardShotFromSnapshot($session, $shotRef['key'], $shotRef['index'], $ctx);
            }

            $part = $this->partOf($ctx['parts'], $partKey);

            if ($part === null) {
                return null;
            }

            return $this->executePart(
                $session,
                $part,
                $ctx['content'][$partKey] ?? null,
                $ctx['execContext'],
                $ctx['typeMap'],
                $ctx['slotValues'],
                $ctx['resolvePrompt'],
                $ctx['parts'],
                [],
                $ctx['shotCap'],
                $ctx['direction'],
            );
        } finally {
            $this->meterContext->clearSession();
            $this->meterContext->clearActor();
            $this->voiceContext->clear();
            $this->directionContext->clear();
            $this->budget->clear();
        }
    }

    /**
     * Render ONE part as a REVISION of its CURRENT output, guided by a free-text instruction (the refine op):
     * a text part → an AI text revision (current text + instruction as DATA); an image part → an AI edit of the
     * current produced image bytes. Returns the new part result (with a NEW version) or null when the part is
     * unknown / a scene_plan (free-text refine of a multi-scene plan is rejected at the request layer, so this
     * is a defensive no-op). Meter-scoped like {@see execute}; the instruction is resolved through the shared
     * resolver so `@[variable]`/slots in it still work, and is NEVER logged.
     *
     * @return array<string, mixed>|null
     */
    public function renderRefinedPart(GenerationSession $session, string $partKey, string $instruction): ?array
    {
        $this->meterContext->setSession($session->id);
        // ACTOR (R2 sub-stage 4): a queued run has no auth()/run of its own, so tag the meter EXPLICITLY
        // with the session's actor — the bot author when delegated, else the human owner — so every ai-text
        // AND image spend inside the run attributes correctly. Cleared in the SAME finally as the tags below.
        [$meterActorType, $meterActorId] = $session->meterActor();
        $this->meterContext->setActor($meterActorType, $meterActorId);
        // VOICE (R2 sub-stage 3): a delegated session renders every text part + refine + the shot_list
        // voiceover in the bot's SNAPSHOTTED voice; an undelegated one sets null (no change). Cleared in
        // the SAME finally as the meter tag (leak-proof — a later non-delegated spend sees no voice).
        $this->voiceContext->setDirective($session->botVoice());
        // PER-BLOCK AUTHORS: the recipe's authors were resolved ONCE and FROZEN into the snapshot at
        // CREATION, so this is a pure read — no lookup, no live bot, nothing a later bot edit could change.
        // AiVoiceContext ranks a block's own author ABOVE this session voice; a block with no author (every
        // pre-feature recipe) is unaffected. Cleared by the SAME finally — clear() resets BOTH fields.
        $this->voiceContext->setAuthorVoices($session->authorVoices());
        // IMAGE BUDGET: bind the run's PERSISTED `ai_generate`/`ai_edit` ledger for this whole scope. Since a
        // storyboard run is now MANY jobs, an in-process counter could no longer bound it (each frame job
        // would start at zero), so the ceilings are charged against the session row — see SessionImageBudget.
        // Cleared in the SAME finally as the meter/voice/direction tags.
        $this->budget->bind($session->id);

        try {
            // DIRECTION: read-only, exactly like the regenerate path — a refine never derives.
            $this->directionContext->set($this->storedDirection($session));

            $ctx = $this->renderContext($session, $partKey);

            if ($ctx === null) {
                return null;
            }

            // A `storyboard.<i>` sub-key refine = an AI edit of shot i's CURRENT image (reuses the shared
            // image-edit seam on the nested blob).
            if ($this->storyboardShotRef($ctx['parts'], $partKey) !== null) {
                return $this->editImageAt($session, $partKey, $instruction, $ctx['resolvePrompt']);
            }

            $part = $this->partOf($ctx['parts'], $partKey);

            if ($part === null) {
                return null;
            }

            return match ($part->kind) {
                PartKind::TEXT_BODY, PartKind::SCRIPT => $this->refineTextPart($session, $part, $instruction, $ctx['resolvePrompt'], $this->partAuthorId($ctx['content'][$part->key] ?? null)),
                PartKind::IMAGE_PLAN => $this->editImageAt($session, $partKey, $instruction, $ctx['resolvePrompt']),
                PartKind::SHOT_LIST => $this->refineShotList($session, $part, $instruction, $ctx['resolvePrompt'], $ctx['shotCap'], $ctx['direction']),
                // A bare scene_plan / storyboard is a multi-item composite — free-text refine is rejected at the
                // request layer (assertRefinablePart 422s it); this is a defensive no-op.
                PartKind::SCENE_PLAN, PartKind::STORYBOARD => null,
            };
        } finally {
            $this->meterContext->clearSession();
            $this->meterContext->clearActor();
            $this->voiceContext->clear();
            $this->directionContext->clear();
            $this->budget->clear();
        }
    }

    /**
     * The per-part render loop of a whole-session run, driven by the snapshot's content type. Reads the recipe
     * from the immutable `recipe_snapshot` (the type id from the column) + the filled `slot_values`.
     *
     * CROSS-PART CONTEXT (Phase A): parts render in DECLARED ORDER (as they always have), and AFTER each part
     * renders its STRING contribution ({@see partContribution}) is accumulated into a `parts` map that is
     * injected into the exec context + typeMap BEFORE the next part runs. So part 2's body/prompt can reference
     * `parts.<part1key>` — resolved EXACTLY like a `globals.<key>` value (a stored post-render string riding the
     * NUL-mask). Declared-order accumulation is ACYCLIC BY CONSTRUCTION: a part can only ever see EARLIER parts.
     * The accumulation starts EMPTY (a whole run rebuilds every part fresh, so the seed from prior results is
     * discarded here — the coherent run is authored top-to-bottom); the isolated per-part ops seed it instead
     * (see {@see renderContext}).
     *
     * @return array<string, array<string, mixed>>
     */
    private function renderParts(GenerationSession $session): array
    {
        $ctx = $this->renderContext($session);

        if ($ctx === null) {
            return [];
        }

        $results = [];
        $parts = [];

        foreach ($ctx['parts'] as $part) {
            $execContext = $this->withParts($ctx['execContext'], $parts);
            $typeMap = $this->withPartTypes($ctx['typeMap'], $parts);
            $resolvePrompt = fn (string $markdown): string => $this->renderMarkdown($markdown, $execContext, $typeMap);

            // The ACCUMULATING $results is threaded in so a later part can read an earlier part's structured
            // result by INTRA-COMPOSITION — the storyboard reads the just-rendered shot_list's shots directly
            // (declared AFTER it, so it's present), independent of the TEXT-only `parts.*` root above.
            $result = $this->executePart(
                $session,
                $part,
                $ctx['content'][$part->key] ?? null,
                $execContext,
                $typeMap,
                $ctx['slotValues'],
                $resolvePrompt,
                $ctx['parts'],
                $results,
                $ctx['shotCap'],
                $ctx['direction'],
            );

            $results[$part->key] = $result;

            $contribution = $this->partContribution($result);

            if ($contribution !== null) {
                $parts[$part->key] = $contribution;
            }
        }

        return $results;
    }

    /**
     * Build the shared render context for a session (the SINGLE place the snapshot + slot values are decoded and
     * the exec context / typeMap / prompt resolver are composed) — used by the whole-session loop AND both
     * per-part ops so they resolve identically. Null when the snapshot's content type is unknown.
     *
     * $targetPartKey is the ISOLATED per-part op's target (null for the whole-session loop, which seeds nothing
     * and accumulates instead); it scopes the `parts` seed to the parts STRICTLY EARLIER than the target.
     *
     * It also resolves the two RUN-WIDE constants every downstream consumer must agree on: the EFFECTIVE shot
     * cap ({@see effectiveShotCap} — ONE value for the agent instruction, the parse clamp and the storyboard
     * fan-out) and the run's creative DIRECTION (already published on the ambient context by the entry point;
     * carried here so the executor-reachable consumers take it as an EXPLICIT parameter).
     *
     * @return array{definition: \App\Modules\Generator\Support\ContentTypeDefinition, parts: array<int, ContentTypePart>, content: array<string, mixed>, slotValues: array<string, mixed>, execContext: array<string, mixed>, typeMap: array<string, mixed>, resolvePrompt: callable(string): string, shotCap: int, direction: ?CreativeDirection}|null
     */
    private function renderContext(GenerationSession $session, ?string $targetPartKey = null): ?array
    {
        $snapshot = is_array($session->recipe_snapshot) ? $session->recipe_snapshot : [];
        $content = is_array($snapshot['content'] ?? null) ? $snapshot['content'] : [];
        $slots = is_array($snapshot['slots'] ?? null) ? $snapshot['slots'] : [];
        $slotValues = is_array($session->slot_values) ? $session->slot_values : [];

        $definition = $this->registry->find((string) $session->content_type);

        if ($definition === null) {
            return null;
        }

        // SNAPSHOT-AUTHORITATIVE parts (Phase B back-compat): a legacy [script, scene_plan] snapshot still
        // renders its own parts even though video_script now composes [shot_list, storyboard] — a registry
        // recomposition never changes what an existing session renders.
        $parts = $this->registry->partsForSnapshot((string) $session->content_type, $content);

        ['context' => $execContext, 'typeMap' => $typeMap] = $this->catalog->executionContext($slots, $slotValues);

        // REFINE-TIME SEEDING (the #1 correctness risk): the ISOLATED per-part ops (regenerate /
        // refine — {@see renderPartFromSnapshot}, {@see renderRefinedPart}) render ONE part against THIS
        // context, so it must already carry the CURRENT outputs of the earlier parts. We SEED the `parts`
        // map from the session's ALREADY-STORED results ({@see seedParts}) — NOT by re-running upstream —
        // so a refine of part 2 sees part 1's current text (coherent), never an empty map (which would
        // reproduce the incoherence a whole-run avoids by accumulation). The seed is scoped to the parts
        // STRICTLY EARLIER than $targetPartKey (defense-in-depth, C2): a forward/self `parts.*` ref resolves
        // EMPTY exactly as it does in a full run (where the target renders before the later parts exist),
        // INDEPENDENT of the write-time earlier-only gate — never against a later part's stored output.
        $seed = $this->seedParts($session, $this->earlierPartKeys($parts, $targetPartKey));
        $execContext = $this->withParts($execContext, $seed);
        $typeMap = $this->withPartTypes($typeMap, $seed);

        // A prompt (ai_edit / base / a refine instruction) is markdown carrying the SAME directives a body does
        // — resolve it through the SAME resolver + context so `w stylu {slot}` type-flows and executes
        // identically to a body. It sees the seeded `parts` map too, so a refine instruction / image prompt
        // may reference an earlier part's current output.
        $resolvePrompt = fn (string $markdown): string => $this->renderMarkdown($markdown, $execContext, $typeMap);

        return [
            'definition' => $definition,
            'parts' => $parts,
            'content' => $content,
            'slotValues' => $slotValues,
            'execContext' => $execContext,
            'typeMap' => $typeMap,
            'resolvePrompt' => $resolvePrompt,
            'shotCap' => $this->effectiveShotCap($content, $parts),
            'direction' => $this->directionContext->direction(),
        ];
    }

    /**
     * THE run's creative direction for a FULL run: reuse an already-stored one (a redelivered/re-run session
     * must not pay twice), else DERIVE it once and PERSIST it, so every later isolated part op refines inside
     * the same frame. Disabled by the kill switch. Fail-soft by construction — the service never throws and
     * returns null on any failure, and a null direction simply renders the run exactly as before this layer.
     */
    private function directionForFullRun(GenerationSession $session): ?CreativeDirection
    {
        if (!$this->directionEnabled()) {
            return null;
        }

        $stored = $session->creativeDirection();

        if ($stored !== null) {
            return $stored;
        }

        $direction = $this->direction->derive($session);

        if ($direction !== null) {
            $session->update(['creative_direction' => $direction->toArray()]);
        }

        return $direction;
    }

    /** The session's STORED direction (an isolated op never derives), or null when the layer is disabled. */
    private function storedDirection(GenerationSession $session): ?CreativeDirection
    {
        return $this->directionEnabled() ? $session->creativeDirection() : null;
    }

    /** The creative-direction KILL SWITCH: false → nothing is derived, read, or injected anywhere. */
    private function directionEnabled(): bool
    {
        return (bool) config('generator.direction.enabled', true);
    }

    /**
     * THE run's EFFECTIVE shot cap — the SINGLE source of truth for every shot bound in the run:
     * `min(authored content.<storyboard>.max_shots ?? ceiling, ceiling)`, floored at 1. The AUTHOR may only
     * ever tighten the platform ceiling (`generator.storyboard_max_shots`), never raise it, and the value is
     * threaded to BOTH the shot-list side (the agent's instructed bound + the parse clamp) and the storyboard
     * fan-out ({@see resolveShotListShots}) — so an author asking for 3 beats gets a shot list WRITTEN for 3,
     * not an 8-beat list silently truncated to 3.
     *
     * Snapshot-authoritative: the cap is read from the snapshot's storyboard content like every other
     * authored value. A snapshot with no storyboard part (a `post`, a legacy [script, scene_plan]) simply
     * uses the ceiling.
     *
     * @param  array<string, mixed>  $content  the snapshot's per-part authored content map
     * @param  array<int, ContentTypePart>  $parts  the snapshot's ordered parts
     */
    private function effectiveShotCap(array $content, array $parts): int
    {
        $ceiling = max(1, (int) config('generator.storyboard_max_shots', 8));
        $authored = null;

        foreach ($parts as $part) {
            if ($part->kind !== PartKind::STORYBOARD) {
                continue;
            }

            $value = is_array($content[$part->key] ?? null) ? ($content[$part->key]['max_shots'] ?? null) : null;

            if (is_int($value) && $value >= 1) {
                $authored = $value;
            }

            break;
        }

        return max(1, min($authored ?? $ceiling, $ceiling));
    }

    /**
     * Seed the cross-part `parts` map for an ISOLATED per-part op ($targetPartKey) from the session's
     * ALREADY-STORED results — the CURRENT outputs of the parts STRICTLY EARLIER than the target (via
     * {@see ContentTypeRegistry::partKeysBefore}), so a refine/regenerate of a later part resolves
     * `parts.<earlierKey>` against real text (the #1 correctness invariant) while a forward/self `parts.*` ref
     * resolves EMPTY exactly as it does in a full run — NEVER against a later part's stored output (C2 defense-
     * in-depth, independent of write validation). Only a strictly-earlier part whose stored result makes a
     * string contribution ({@see partContribution} — an ok text_body/script) is seeded; image/scene parts (and
     * failed/never-run parts) contribute nothing, IDENTICAL to the whole-run accumulation path. A null target
     * (the whole-session loop) seeds nothing — that path accumulates from empty instead.
     *
     * @param  array<int, string>  $earlierKeys  the parts declared STRICTLY EARLIER than the isolated target
     * @return array<string, string>
     */
    private function seedParts(GenerationSession $session, array $earlierKeys): array
    {
        if ($earlierKeys === []) {
            return [];
        }

        $results = is_array($session->results) ? $session->results : [];
        $parts = [];

        foreach ($earlierKeys as $key) {
            $contribution = $this->partContribution(is_array($results[$key] ?? null) ? $results[$key] : null);

            if ($contribution !== null) {
                $parts[$key] = $contribution;
            }
        }

        return $parts;
    }

    /**
     * The keys of the parts declared STRICTLY EARLIER than $targetPartKey in the SNAPSHOT's ordered parts (the
     * cross-part seed scope for an isolated per-part op). A null target (the whole-session loop) or a target
     * that names no snapshot part yields an empty list.
     *
     * A NESTED `<storyboardKey>.<i>` target scopes to its BASE part. This matters now that every storyboard
     * image renders through the nested path: in a whole run the storyboard's authored style resolved against
     * the parts accumulated BEFORE it (its sibling shot_list among them), so scoping a frame to nothing would
     * silently resolve an authored `parts.shot_list` reference to empty and change what the run draws.
     * Reducing the sub-key to its base makes the frame's seed IDENTICAL to what the inline loop had — and
     * fixes the same latent gap in the per-shot regenerate op, which shares this path.
     *
     * @param  array<int, ContentTypePart>  $parts
     * @return array<int, string>
     */
    private function earlierPartKeys(array $parts, ?string $targetPartKey): array
    {
        if ($targetPartKey === null) {
            return [];
        }

        $shotRef = $this->storyboardShotRef($parts, $targetPartKey);
        $targetPartKey = $shotRef['key'] ?? $targetPartKey;

        $keys = array_map(fn (ContentTypePart $part): string => $part->key, $parts);
        $index = array_search($targetPartKey, $keys, true);

        return $index === false ? [] : array_slice($keys, 0, $index);
    }

    /**
     * A part result's STRING contribution to the `parts` map (the per-kind stringifier). ONLY a text part
     * (text_body / script) that rendered `ok` contributes — its rendered `text` verbatim. An image_plan and
     * a scene_plan contribute NOTHING (null): cross-part context is TEXT-ONLY (owner decision), and there is
     * no trivial flattened text for a media plan. A failed / non-ok result contributes nothing.
     *
     * @param  array<string, mixed>|null  $result
     */
    private function partContribution(?array $result): ?string
    {
        if ($result === null || ($result['status'] ?? null) !== 'ok') {
            return null;
        }

        $kind = $result['kind'] ?? null;

        // A text_body / script contributes its rendered text; a shot_list contributes its readable flattening
        // (its `text`), so a hypothetical later text part could reference the script via `parts.shot_list`.
        if ($kind === PartKind::TEXT_BODY->value || $kind === PartKind::SCRIPT->value || $kind === PartKind::SHOT_LIST->value) {
            return is_string($result['text'] ?? null) ? $result['text'] : null;
        }

        return null;
    }

    /**
     * Inject the accumulated/seeded `parts` map into a copy of the exec context under the `parts` root — the
     * ONE place the resolver reads it (a plain whitelisted dotted lookup, like `globals`). A full replace, so
     * the whole-run accumulation overrides any seed baked by {@see renderContext}.
     *
     * @param  array<string, mixed>  $execContext
     * @param  array<string, string>  $parts
     * @return array<string, mixed>
     */
    private function withParts(array $execContext, array $parts): array
    {
        $execContext['parts'] = $parts;

        return $execContext;
    }

    /**
     * Extend a copy of the runtime typeMap with a `parts.<key> => TEXT` entry for each populated part, so a
     * directive pipeline over a `parts.<key>` reference type-flows from TEXT (cross-part refs are TEXT-ONLY).
     * An identity embed needs no type entry (it stringifies the looked-up value directly); this only matters
     * for a piped part reference.
     *
     * @param  array<string, mixed>  $typeMap
     * @param  array<string, string>  $parts
     * @return array<string, mixed>
     */
    private function withPartTypes(array $typeMap, array $parts): array
    {
        foreach (array_keys($parts) as $key) {
            $typeMap['parts.' . $key] = VariableType::TEXT;
        }

        return $typeMap;
    }

    /**
     * One part by its kind.
     *
     * @param  array<string, mixed>  $execContext
     * @param  array<string, mixed>  $typeMap
     * @param  array<string, mixed>  $slotValues
     * @param  callable(string): string  $resolvePrompt
     * @param  array<int, ContentTypePart>  $definitionParts  the snapshot's ordered parts (for the storyboard's
     *                                                        sibling shot_list lookup)
     * @param  array<string, mixed>  $priorResults  the ACCUMULATING results of a whole run (empty for an
     *                                              isolated op — the storyboard reads the STORED shot_list then)
     * @param  int  $shotCap  the run's EFFECTIVE shot cap (one value for the shot list AND the storyboard)
     * @param  CreativeDirection|null  $direction  the run's creative direction, or null when there is none
     * @return array<string, mixed>
     */
    private function executePart(
        GenerationSession $session,
        ContentTypePart $part,
        mixed $content,
        array $execContext,
        array $typeMap,
        array $slotValues,
        callable $resolvePrompt,
        array $definitionParts,
        array $priorResults,
        int $shotCap,
        ?CreativeDirection $direction,
    ): array {
        return match ($part->kind) {
            PartKind::TEXT_BODY, PartKind::SCRIPT => $this->executeTextPart($session, $part, $content, $execContext, $typeMap),
            PartKind::IMAGE_PLAN => $this->executeImagePart($session, $part, $part->key, $content, $slotValues, $resolvePrompt, $direction),
            PartKind::SCENE_PLAN => $this->executeScenePart($session, $part, $content, $execContext, $typeMap, $slotValues, $resolvePrompt, $direction),
            PartKind::SHOT_LIST => $this->executeShotListPart($session, $part, $content, $resolvePrompt, $shotCap, $direction),
            PartKind::STORYBOARD => $this->executeStoryboardPart($session, $part, $content, $slotValues, $resolvePrompt, $definitionParts, $priorResults, $shotCap, $direction),
        };
    }

    /**
     * Resolve a text body through the shared resolver (with the LIVE ai-text generator). Fail-soft: a
     * resolution error (an opt-in assert_present over an empty value) becomes a `failed` result carrying a
     * LOCALIZED, NON-SECRET message — never the prompt. An empty body is a valid `ok` empty string. The `ok`
     * result carries a `version` (the current result's version + 1 — 1 on a fresh whole-run since claim cleared
     * results); a `failed` result carries none.
     *
     * @param  array<string, mixed>  $execContext
     * @param  array<string, mixed>  $typeMap
     * @return array<string, mixed>
     */
    private function executeTextPart(GenerationSession $session, ContentTypePart $part, mixed $content, array $execContext, array $typeMap): array
    {
        try {
            $text = $this->renderMarkdown($this->markdownOf($content), $execContext, $typeMap);

            return ['kind' => $part->kind->value, 'status' => 'ok', 'text' => $text, 'version' => $this->currentVersionOf($session, $part->key) + 1];
        } catch (Throwable $e) {
            $this->logPartFailure('text', $session, $part->key, $e);

            return ['kind' => $part->kind->value, 'status' => 'failed', 'error' => __('generator.sessions.part_failed')];
        }
    }

    /**
     * Execute an image_plan part (R2 sub-stage 2c): run the chain, store the produced bytes as a new VERSION,
     * and emit `{status:'ok', image:{mime,width,height,version}, version}` — NO bytes in the JSON. Fail-soft: a
     * domain failure (missing base, exhausted per-run ai_edit/ai_generate budget) uses that exception's
     * localized message; an over-monthly-cap BUDGET stop (AiBudgetExceededException) surfaces the budget
     * message (not the generic image one); any other error falls to a generic localized image message. The
     * prompt/base config is NEVER logged or surfaced.
     *
     * DIRECTION: an `ai_generate` base takes the run's visual ANCHOR ahead of the authored prompt (see
     * {@see directedImagePlan}) — a `post_with_image` is one piece, so its image must be drawn in the same
     * world its body was written to.
     *
     * @param  array<string, mixed>  $slotValues
     * @param  callable(string): string  $resolvePrompt
     * @return array<string, mixed>
     */
    private function executeImagePart(GenerationSession $session, ContentTypePart $part, string $partKey, mixed $content, array $slotValues, callable $resolvePrompt, ?CreativeDirection $direction): array
    {
        try {
            ['plan' => $plan, 'resolve' => $resolve, 'reference' => $reference] = $this->directedImagePlan($session, is_array($content) ? $content : [], $direction, $resolvePrompt);
            $image = $this->produceImage($session, $partKey, $plan, $slotValues, $resolve, $reference);

            return ['kind' => $part->kind->value, 'status' => 'ok', 'image' => $image, 'version' => $image['version']];
        } catch (ImageChainException $e) {
            // A DOMAIN chain failure (missing base, exhausted per-run budget, unsupported base) already carries a
            // clear localized messageKey for the user; log it too (class + which key) so the trail shows WHICH.
            $this->logPartFailure('image_chain', $session, $partKey, $e);

            return ['kind' => $part->kind->value, 'status' => 'failed', 'error' => __($e->messageKey())] + $this->errorCodeOf($e, 'error_code');
        } catch (AiBudgetExceededException $e) {
            // A workspace monthly $ cost-cap stop (from the metered ai_generate/ai_edit gate-before-spend) is a
            // BUDGET failure, not a bad base/filters — surface the budget message, not the generic image_failed
            // ("check its base and filters"). Mirrors the refine path (refineImagePart) and stays fail-soft.
            return ['kind' => $part->kind->value, 'status' => 'failed', 'error' => __('generator.sessions.image_budget')];
        } catch (Throwable $e) {
            $this->logPartFailure('image', $session, $partKey, $e);

            return ['kind' => $part->kind->value, 'status' => 'failed', 'error' => __('generator.sessions.image_failed')];
        }
    }

    /**
     * Execute a scene_plan part: for each scene render its narration (like a body) and run its OPTIONAL
     * image_plan through the same chain (stored versioned under `scene_plan.<i>`). Per-scene fail-soft — a
     * broken scene narration/image never sinks the others; the part itself stays `ok`.
     *
     * @param  array<string, mixed>  $execContext
     * @param  array<string, mixed>  $typeMap
     * @param  array<string, mixed>  $slotValues
     * @param  callable(string): string  $resolvePrompt
     * @return array<string, mixed>
     */
    private function executeScenePart(GenerationSession $session, ContentTypePart $part, mixed $content, array $execContext, array $typeMap, array $slotValues, callable $resolvePrompt, ?CreativeDirection $direction): array
    {
        $scenes = is_array($content) && is_array($content['scenes'] ?? null) ? $content['scenes'] : [];
        $rendered = [];

        foreach (array_values($scenes) as $i => $scene) {
            $scene = is_array($scene) ? $scene : [];
            $narration = is_array($scene['narration'] ?? null) ? $scene['narration'] : null;

            $rendered[] = ['narration' => $this->renderNarration($session, 'scene_plan.' . $i . '.narration', $narration, $execContext, $typeMap)]
                + $this->renderSceneImage($session, 'scene_plan.' . $i, $scene['image_plan'] ?? null, $slotValues, $resolvePrompt, $direction);
        }

        return ['kind' => $part->kind->value, 'status' => 'ok', 'scenes' => $rendered];
    }

    /**
     * Run one scene's OPTIONAL image_plan. An absent plan → `{image_status:'none'}`; a produced image →
     * `{image_status:'ok', image:{…,version}, part_key}` (part_key lets the chat build the serve URL, version
     * lets it resolve the current blob); a failure → `{image_status:'failed', image_error}`. Fail-soft,
     * non-secret — like {@see executeImagePart}.
     *
     * @param  array<string, mixed>  $slotValues
     * @param  callable(string): string  $resolvePrompt
     * @return array<string, mixed>
     */
    private function renderSceneImage(GenerationSession $session, string $partKey, mixed $plan, array $slotValues, callable $resolvePrompt, ?CreativeDirection $direction): array
    {
        if ($plan === null) {
            return ['image_status' => 'none'];
        }

        try {
            ['plan' => $directed, 'resolve' => $resolve, 'reference' => $reference] = $this->directedImagePlan($session, is_array($plan) ? $plan : [], $direction, $resolvePrompt);
            $image = $this->produceImage($session, $partKey, $directed, $slotValues, $resolve, $reference);

            return ['image_status' => 'ok', 'image' => $image, 'part_key' => $partKey];
        } catch (ImageChainException $e) {
            $this->logPartFailure('scene_image_chain', $session, $partKey, $e);

            return ['image_status' => 'failed', 'image_error' => __($e->messageKey())] + $this->errorCodeOf($e, 'image_error_code');
        } catch (AiBudgetExceededException $e) {
            // An over-cap BUDGET stop (not a bad base/filters) — surface the budget message, mirroring
            // executeImagePart / refineImagePart. Fail-soft: this scene's image fails, the others still run.
            return ['image_status' => 'failed', 'image_error' => __('generator.sessions.image_budget')];
        } catch (Throwable $e) {
            $this->logPartFailure('scene_image', $session, $partKey, $e);

            return ['image_status' => 'failed', 'image_error' => __('generator.sessions.image_failed')];
        }
    }

    /**
     * Execute a shot_list part (video_script rework Phase B): resolve the authored creative BRIEF like a body
     * (so slots / `parts.*` / if-blocks work), then ONE metered structured AI call → a parsed shot list. A
     * blank/failed model reply (the renderer returns null) fails the part soft; a parsed list is `ok` with
     * `{hook, shots, cta, text, parse_ok, version}` (version = prior + 1 on a regenerate). Fail-soft on any
     * Throwable — never the brief/prompt/output, only the failure fact.
     *
     * @param  callable(string): string  $resolvePrompt
     * @return array<string, mixed>
     */
    private function executeShotListPart(GenerationSession $session, ContentTypePart $part, mixed $content, callable $resolvePrompt, int $shotCap, ?CreativeDirection $direction): array
    {
        try {
            $brief = $resolvePrompt($this->briefMarkdownOf($content));
            $shotList = $this->shotList->generate($brief, $shotCap, $direction, $this->characterDescriptorFor($session));

            if ($shotList === null) {
                return ['kind' => $part->kind->value, 'status' => 'failed', 'error' => __('generator.sessions.part_failed')];
            }

            return $this->shotListResult($session, $part, $shotList);
        } catch (Throwable $e) {
            $this->logPartFailure('shot_list', $session, $part->key, $e);

            return ['kind' => $part->kind->value, 'status' => 'failed', 'error' => __('generator.sessions.part_failed')];
        }
    }

    /**
     * A directed REVISION of the shot_list's CURRENT structured output (the shot_list refine op): feed the
     * current shot list + the (resolver-expanded) instruction as DATA to the renderer's revise mode → a NEW
     * structured list. A blank OR unparseable revision is a FAILED no-op (applyPartOp preserves the current
     * good list, no history churn) — symmetric with the text-refine fail-closed posture.
     *
     * @param  callable(string): string  $resolvePrompt
     * @return array<string, mixed>
     */
    private function refineShotList(GenerationSession $session, ContentTypePart $part, string $instruction, callable $resolvePrompt, int $shotCap, ?CreativeDirection $direction): array
    {
        try {
            $results = is_array($session->results) ? $session->results : [];
            $current = is_array($results[$part->key] ?? null) ? $results[$part->key] : [];
            $revised = $this->shotList->revise($current, $resolvePrompt($instruction), $shotCap, $direction, $this->characterDescriptorFor($session));

            if ($revised === null || $revised['parse_ok'] === false) {
                return ['kind' => $part->kind->value, 'status' => 'failed', 'error' => __('generator.sessions.part_failed')];
            }

            return $this->shotListResult($session, $part, $revised);
        } catch (Throwable $e) {
            $this->logPartFailure('shot_list_refine', $session, $part->key, $e);

            return ['kind' => $part->kind->value, 'status' => 'failed', 'error' => __('generator.sessions.part_failed')];
        }
    }

    /**
     * The `ok` shot_list result wire shape — the normalized renderer output + a next `version`.
     *
     * @param  array{hook: string, shots: array<int, array<string, mixed>>, cta: string, text: string, parse_ok: bool}  $shotList
     * @return array<string, mixed>
     */
    private function shotListResult(GenerationSession $session, ContentTypePart $part, array $shotList): array
    {
        return [
            'kind' => $part->kind->value,
            'status' => 'ok',
            'hook' => $shotList['hook'],
            'shots' => $shotList['shots'],
            'cta' => $shotList['cta'],
            'text' => $shotList['text'],
            'parse_ok' => $shotList['parse_ok'],
            'version' => $this->currentVersionOf($session, $part->key) + 1,
        ];
    }

    /**
     * Execute a storyboard part (Phase B): read the sibling shot_list's STRUCTURED shots (the in-progress
     * whole-run map preferred, else the STORED result) and ANNOUNCE one frame PER shot, NESTED under
     * `storyboard.<i>` exactly like `scene_plan.<i>`. No shots (missing/empty/failed shot_list) →
     * `{status:'ok', shots:[]}`, which settles the run immediately.
     *
     * THE IMAGES ARE NOT PRODUCED HERE ANY MORE (the distributed-frames stage). Each shot's image is a slow,
     * independent provider call — measured at ~33s to generate and ~58s to edit with a reference — and the
     * session job's SIGALRM window is 300s and INVIOLATE (the whole lock/reaper chain is ordered on top of
     * it). Eight shots therefore could not fit, and even the generate-only case was already at the edge
     * BEFORE the text parts and the direction derivation were paid for. So this method now emits each shot as
     * a `pending` frame carrying a correlation token ({@see StoryboardFrame}), the run manager queues one job
     * per frame once these results are persisted, and each frame gets a whole job's budget to itself. Frames
     * then render in PARALLEL across workers, and one failing frame burns its own job instead of the run's.
     *
     * The per-shot rendering machinery is NOT duplicated: a frame job renders through the very same
     * {@see renderStoryboardShotFromSnapshot} path the per-shot regenerate op already used.
     *
     * @param  array<int, ContentTypePart>  $definitionParts
     * @param  array<string, mixed>  $slotValues
     * @param  callable(string): string  $resolvePrompt
     * @param  array<string, mixed>  $priorResults
     * @return array<string, mixed>
     */
    private function executeStoryboardPart(GenerationSession $session, ContentTypePart $part, mixed $content, array $slotValues, callable $resolvePrompt, array $definitionParts, array $priorResults, int $shotCap, ?CreativeDirection $direction): array
    {
        $shotListKey = $this->firstShotListKeyBefore($definitionParts, $part->key);
        $shots = $shotListKey === null ? [] : $this->resolveShotListShots($session, $priorResults, $shotListKey, $shotCap);

        $announced = [];

        foreach (array_values($shots) as $i => $shot) {
            $announced[] = StoryboardFrame::pending(
                $this->storyboardShotMeta($i, is_array($shot) ? $shot : []),
                $part->key . '.' . $i,
                (string) Str::uuid(),
            );
        }

        return ['kind' => $part->kind->value, 'status' => 'ok', 'shots' => $announced];
    }

    /**
     * The DESCRIPTIVE fields of one storyboard shot ({index, visual, voiceover, seconds, features_character})
     * — what the FE shows for a beat whether or not its image exists yet, so they ride the entry from
     * announcement through to either terminal state.
     *
     * `features_character` is carried here (not only on the shot_list result) because it describes THIS
     * frame: it is why a frame took the reference path, and it is what a surface showing the storyboard needs
     * in order to say so. Absent on a shot list written before the flag existed → false.
     *
     * @param  array<string, mixed>  $shot  a normalized shot_list shot ({visual, voiceover, seconds, …})
     * @return array<string, mixed>
     */
    private function storyboardShotMeta(int $i, array $shot): array
    {
        return [
            'index' => $i,
            'visual' => is_string($shot['visual'] ?? null) ? $shot['visual'] : '',
            'voiceover' => is_string($shot['voiceover'] ?? null) ? $shot['voiceover'] : '',
            'seconds' => is_int($shot['seconds'] ?? null) ? $shot['seconds'] : 0,
            'features_character' => ($shot['features_character'] ?? null) === true,
        ];
    }

    /**
     * Regenerate ONE storyboard shot's image (the `storyboard.<i>` regenerate op): read shot i's `visual` from
     * the CURRENT stored shot_list result + the storyboard's authored style/filters → produce a FRESH image →
     * return the per-shot image sub-result `{kind:'image_plan', status:'ok', image:{…,version}, version}` (so
     * the refiner's nested write-back merges it into the shot). No shot / no visual → a failed no-op. Fail-soft.
     *
     * @param  array{parts: array<int, ContentTypePart>, content: array<string, mixed>, slotValues: array<string, mixed>, resolvePrompt: callable(string): string, shotCap: int, direction: ?CreativeDirection}  $ctx
     * @return array<string, mixed>
     */
    private function renderStoryboardShotFromSnapshot(GenerationSession $session, string $storyboardKey, int $i, array $ctx): array
    {
        $partKey = $storyboardKey . '.' . $i;
        $shotListKey = $this->firstShotListKeyBefore($ctx['parts'], $storyboardKey);
        $shots = $shotListKey === null ? [] : $this->resolveShotListShots($session, [], $shotListKey, $ctx['shotCap']);
        $shot = is_array($shots[$i] ?? null) ? $shots[$i] : null;
        $visual = is_string($shot['visual'] ?? null) ? $shot['visual'] : '';

        if ($shot === null || $visual === '') {
            return ['kind' => 'image_plan', 'status' => 'failed', 'error' => __('generator.sessions.image_failed')];
        }

        $content = is_array($ctx['content'][$storyboardKey] ?? null) ? $ctx['content'][$storyboardKey] : [];

        // WHETHER THIS BEAT SHOWS THE CREATOR — read off the stored shot the same way its `visual` is, so a
        // regenerate of frame i draws the same KIND of frame the run originally announced. A shot list
        // written before the flag existed (or by a model that omitted it) simply reads false.
        $featuresCharacter = ($shot['features_character'] ?? null) === true;

        try {
            // The regenerated frame keeps its place in the SAME set (frame i of N), so its continuity clause
            // and direction anchor match the frames around it — a re-shot beat must not drift in style.
            $image = $this->produceStoryboardShotImage($session, $partKey, $i, count($shots), $visual, $content, $ctx['slotValues'], $ctx['resolvePrompt'], $ctx['direction'], $featuresCharacter);

            return ['kind' => 'image_plan', 'status' => 'ok', 'image' => $image, 'version' => $image['version']];
        } catch (ImageChainException $e) {
            $this->logPartFailure('storyboard_image_chain', $session, $partKey, $e);

            return ['kind' => 'image_plan', 'status' => 'failed', 'error' => __($e->messageKey())] + $this->errorCodeOf($e, 'error_code');
        } catch (AiBudgetExceededException $e) {
            return ['kind' => 'image_plan', 'status' => 'failed', 'error' => __('generator.sessions.image_budget')];
        } catch (Throwable $e) {
            $this->logPartFailure('storyboard_image', $session, $partKey, $e);

            return ['kind' => 'image_plan', 'status' => 'failed', 'error' => __('generator.sessions.image_failed')];
        }
    }

    /**
     * Build + run the ai_generate image plan for one storyboard shot and store it versioned under $partKey.
     *
     * The base prompt is composed, IN ORDER, from:
     *   1. the CONTINUITY clause — "Frame i of N from the SAME film/production …" — which is what stops five
     *      shots from looking like five different films (the owner's complaint). App-authored, trusted text.
     *   2. the run's DIRECTION anchor ({@see CreativeDirection::forImage}: art direction + the recurring
     *      subject + continuity notes) when the run has one — the shared world every frame is drawn in.
     *   3. the frozen CHARACTER's guardrails (aesthetic + prohibitions) when the session has one.
     *   4. the resolved authored STYLE (the template's own art direction, which therefore comes LAST of the
     *      framing and can override the derived anchor above it).
     *   5. the shot's VISUAL — what THIS frame shows.
     *
     * THE CHARACTER slots into (2) and (3): when the run has a frozen character AND this beat is flagged as
     * showing it, the anchor's SUBJECT becomes the character's own description (the derived subject is what a
     * model guessed from the recipe; the character is what the human configured, and a frame has only one
     * recurring subject), and the character's reference BYTES are handed to the chain so the base is drawn
     * FROM that likeness. The character's aesthetic + prohibitions ride EVERY frame of the session, flagged
     * or not — a set made for one creator has to look like one set, and "never show alcohol" is about the
     * picture, not about who is in it.
     *
     * The visual is the shot_list AI's own output and the direction anchor is likewise model-derived — both
     * are DATA to draw, NEVER re-interpreted as directives (injection invariant). So the chain resolver is
     * IDENTITY for this WHOLE already-composed base string (the closure compares against the FINAL $composed,
     * whatever it contains), while authored FILTER prompts still resolve through the real session resolver.
     * The identity pieces are composed INTO that final string and therefore never round-trip the directive
     * resolver either — a descriptor containing `@[ai-text]{…}` is drawn, never executed.
     * An over-budget generate / provider failure throws for the caller's per-shot fail-soft catch.
     *
     * @param  array<string, mixed>  $storyboardContent
     * @param  array<string, mixed>  $slotValues
     * @param  callable(string): string  $resolvePrompt
     * @return array{mime: string, width: int, height: int, version: int}
     */
    private function produceStoryboardShotImage(GenerationSession $session, string $partKey, int $index, int $total, string $visual, array $storyboardContent, array $slotValues, callable $resolvePrompt, ?CreativeDirection $direction, bool $featuresCharacter = false): array
    {
        $style = trim($resolvePrompt($this->styleMarkdownOf($storyboardContent)));

        $identity = $this->visualIdentityFor($session);
        $drawsCharacter = $identity !== null && $featuresCharacter;

        $composed = implode("\n\n", array_values(array_filter([
            $this->continuityClause($index, $total),
            $this->imageAnchor($direction, $drawsCharacter ? $identity?->subjectDescription() : null),
            $identity?->guardrails(),
            $style,
            $visual,
        ], fn (?string $piece): bool => $piece !== null && $piece !== '')));

        // IDENTITY for the composed base (neither the visual nor the derived anchor may round-trip the
        // directive resolver), real resolver for everything else (the authored ai_edit filter prompts).
        $chainResolve = fn (string $markdown): string => $markdown === $composed ? $composed : $resolvePrompt($markdown);

        $plan = [
            'base' => ['kind' => 'ai_generate', 'prompt' => $composed],
            'filters' => is_array($storyboardContent['filters'] ?? null) ? $storyboardContent['filters'] : [],
        ];

        return $this->produceImage(
            $session,
            $partKey,
            $plan,
            $slotValues,
            $chainResolve,
            $drawsCharacter ? $this->characterReferenceFor($session, $identity) : null,
        );
    }

    /**
     * The session's FROZEN character identity, or null when this run has no character to draw.
     *
     * A PURE read of the already-hydrated row (plus one config check) — no query, no provider call — which is
     * why it is resolved at each point of use rather than threaded through the render context the way the
     * creative DIRECTION is. The direction is threaded because deriving it costs a provider call and must
     * happen exactly once; this costs nothing, and keeping it out of eleven signatures is worth more than the
     * symmetry.
     *
     * THREE gates, and each rules out a different mistake:
     *   1. the KILL SWITCH, so the whole layer can be turned off without touching a session;
     *   2. `enabled` as FROZEN IN THE OVERLAY ({@see SessionVisualIdentity::fromOverlay}) — never the live
     *      author's toggle, which may have been flipped after this session was delegated and half-rendered;
     *   3. a frozen character IMAGE. Without one there is nothing to draw the person FROM, so injecting the
     *      written identity would change every prompt in exchange for a likeness we cannot deliver — and
     *      would break the guarantee that delegating a bot with no approved likeness renders exactly as it
     *      did before this layer existed.
     */
    private function visualIdentityFor(GenerationSession $session): ?SessionVisualIdentity
    {
        if (!(bool) config('generator.visual_identity.enabled', true)) {
            return null;
        }

        $identity = SessionVisualIdentity::fromOverlay($session->botVisualIdentity());

        return $identity !== null && $identity->hasCharacterImage() ? $identity : null;
    }

    /**
     * The frozen character's reference BYTES for this session, or null when the layer does not apply here.
     * Read from the identity store — deliberately a DIFFERENT prefix from the produced images, so a full
     * re-run (which wipes the produced-image prefix) can never destroy the reference it is about to draw
     * from.
     *
     * MISSING BYTES ARE LOGGED, not silently absorbed. The identity only exists at all once the overlay says
     * a likeness was frozen ({@see visualIdentityFor}), so a store that then has nothing means the two halves
     * of the snapshot disagree — a half-committed delegation, a storage outage, an operator deleting the
     * prefix. The render still degrades gracefully (it generates from text rather than failing the part),
     * but that degradation is expensive and invisible in the output, so it leaves a trail. The FACT only:
     * the session and the character KEY, never the bytes, the prompt or a word of the identity.
     */
    private function characterReferenceFor(GenerationSession $session, ?SessionVisualIdentity $identity): ?string
    {
        if ($identity === null) {
            return null;
        }

        $reference = $this->identityImages->forSession($session);

        if ($reference === null) {
            Log::warning('Generation session claims a frozen character image but its bytes are missing; drawing without the reference.', [
                'session_id' => $session->id,
                'character_key' => $session->characterImageKey(),
            ]);
        }

        return $reference;
    }

    /**
     * The written description of this run's on-screen creator for the SHOT-LIST writer, or null.
     *
     * It is what makes the per-shot `features_character` flag answerable: the model can only say which beats
     * show the person if it knows who the person is. Null on every run without a drawable character, which is
     * what keeps the shot-list instruction byte-identical to the pre-feature one.
     */
    private function characterDescriptorFor(GenerationSession $session): ?string
    {
        return $this->visualIdentityFor($session)?->subjectDescription();
    }

    /**
     * The IMAGE ANCHOR for one image: the run's direction projection with the character substituted in as the
     * recurring subject, or — when there is no direction, or its direction carries no visual guidance at all
     * — the character's subject line on its own. Null when neither has anything to say.
     *
     * Both paths go through {@see CreativeDirection}, which owns the wording of that line (including the
     * precedence sentence that settles a disagreement between the anchor and the shot's own visual text), so
     * a character-anchored prompt reads identically whether or not the run happened to derive a direction.
     */
    private function imageAnchor(?CreativeDirection $direction, ?string $subjectOverride): ?string
    {
        $anchor = $direction?->forImage($subjectOverride);

        if ($anchor !== null || $subjectOverride === null || $subjectOverride === '') {
            return $anchor;
        }

        return CreativeDirection::imageSubjectAnchor($subjectOverride);
    }

    /**
     * The per-frame CONTINUITY clause that leads every storyboard image prompt: it tells the image model this
     * frame belongs to ONE production with the others, which (together with the direction's shared visual
     * anchor) is what makes N independent text→image calls read as one film. App-authored + trusted, but it
     * still rides the composed base string, so it is identity-resolved with the rest.
     */
    private function continuityClause(int $index, int $total): string
    {
        return 'Frame ' . ($index + 1) . ' of ' . max(1, $total) . ' from the SAME film/production — consistent '
            . 'world, palette, medium, lighting and subject across all frames.';
    }

    /**
     * The sibling shot_list's shots for the storyboard: prefer the in-progress whole-run map (present because
     * the storyboard is declared AFTER the shot_list), else the STORED result (an isolated storyboard op).
     * Only an `ok` shot_list contributes shots; the list is CLAMPED to the run's EFFECTIVE shot cap — the SAME
     * value the shot list itself was written and parsed against (the real cost bound for the storyboard's
     * `ai_generate` calls), so the two can never disagree about how many beats exist.
     *
     * @param  array<string, mixed>  $priorResults
     * @return array<int, mixed>
     */
    private function resolveShotListShots(GenerationSession $session, array $priorResults, string $shotListKey, int $shotCap): array
    {
        $stored = is_array($session->results) ? $session->results : [];
        $result = array_key_exists($shotListKey, $priorResults) ? $priorResults[$shotListKey] : ($stored[$shotListKey] ?? null);

        if (!is_array($result) || ($result['status'] ?? null) !== 'ok' || !is_array($result['shots'] ?? null)) {
            return [];
        }

        return array_slice(array_values($result['shots']), 0, max(1, $shotCap));
    }

    /**
     * The key of the FIRST shot_list-kind part declared BEFORE $storyboardKey in the snapshot's ordered parts
     * — the storyboard's intra-composition source (video_script declares exactly one). Null when none.
     *
     * @param  array<int, ContentTypePart>  $parts
     */
    private function firstShotListKeyBefore(array $parts, string $storyboardKey): ?string
    {
        foreach ($parts as $part) {
            if ($part->key === $storyboardKey) {
                break;
            }

            if ($part->kind === PartKind::SHOT_LIST) {
                return $part->key;
            }
        }

        return null;
    }

    /**
     * Resolve a `<storyboardKey>.<i>` sub-key against the snapshot's ordered parts: `{key, index}` when the
     * base part is STORYBOARD-kind, else null (a bare part or an unrelated dotted key).
     *
     * @param  array<int, ContentTypePart>  $parts
     * @return array{key: string, index: int}|null
     */
    private function storyboardShotRef(array $parts, string $partKey): ?array
    {
        if (preg_match('/^(.+)\.(\d+)$/', $partKey, $m) !== 1) {
            return null;
        }

        $base = $this->partOf($parts, $m[1]);

        return $base !== null && $base->kind === PartKind::STORYBOARD ? ['key' => $m[1], 'index' => (int) $m[2]] : null;
    }

    /**
     * The snapshot part under $partKey in the ordered parts list, or null.
     *
     * @param  array<int, ContentTypePart>  $parts
     */
    private function partOf(array $parts, string $partKey): ?ContentTypePart
    {
        foreach ($parts as $part) {
            if ($part->key === $partKey) {
                return $part;
            }
        }

        return null;
    }

    /**
     * The `@[ai-text]` AUTHOR that owns a part's authored content, or null when it names none — the voice a
     * REFINE of that part must keep speaking in. Every string leaf of the (nested) part content is scanned
     * through the SHARED {@see VariableResolver::collectAiTextAuthorIds}, the same scanner the snapshot was
     * frozen with, so a refine can never look up an author the freeze did not capture.
     *
     * EXACTLY ONE AUTHOR, OR NONE. A refine is ONE revision of the part's WHOLE current output, so it has
     * exactly one voice to speak in — and when the part's blocks name SEVERAL DIFFERENT authors, no single
     * one of them is that voice. Handing the part to whichever author the walk reached FIRST would rewrite
     * another author's text in a stranger's tone with nothing in the UI hinting at it, so an AMBIGUOUS part
     * returns null and the voice holder falls back to the run-wide session voice and then the persona —
     * the same ladder an unresolvable author already takes (fail-SAFE, identical to a fresh render).
     *
     * DELIBERATE LIMITATION worth naming: the shared scanner reports only the authors that ARE named, so a
     * part mixing ONE authored block with author-LESS ones counts as unambiguous and refines in that one
     * author's voice. Distinguishing "one author + plain blocks" from "one author only" would mean threading
     * a per-BLOCK sink through {@see VariableResolver}'s whole scan walk — a much wider change to the most
     * delicate shared code for a materially rarer case than two competing authors.
     */
    private function partAuthorId(mixed $content): ?string
    {
        $ids = array_values(array_unique($this->authorIdsIn($content)));

        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * Every author id named anywhere in a (possibly nested) authored content value, in walk order.
     *
     * @return array<int, string>
     */
    private function authorIdsIn(mixed $content): array
    {
        if (is_array($content)) {
            $ids = [];

            foreach ($content as $value) {
                foreach ($this->authorIdsIn($value) as $id) {
                    $ids[] = $id;
                }
            }

            return $ids;
        }

        return is_string($content) && $content !== '' ? $this->resolver->collectAiTextAuthorIds($content) : [];
    }

    /** The `{brief:{markdown}}` creative brief of a shot_list part, coerced to a string (absent/malformed → ''). */
    private function briefMarkdownOf(mixed $content): string
    {
        $brief = is_array($content) && is_array($content['brief'] ?? null) ? $content['brief'] : null;

        return $brief !== null && is_string($brief['markdown'] ?? null) ? $brief['markdown'] : '';
    }

    /** The `{style:{markdown}}` optional style prompt of a storyboard part, coerced to a string (absent → ''). */
    private function styleMarkdownOf(array $content): string
    {
        $style = is_array($content['style'] ?? null) ? $content['style'] : null;

        return $style !== null && is_string($style['markdown'] ?? null) ? $style['markdown'] : '';
    }

    /**
     * A text REVISION of the part's CURRENT output (the refine op): compose a revision prompt from the current
     * text + the (resolver-expanded) instruction — both as DATA — and run the SAME live ai-text generator
     * (metered, session-tagged, injection-hardened). Fail-soft to a localized non-secret message. The `ok`
     * result carries the next version.
     *
     * $authorId is the PART's own `@[ai-text]` author ({@see partAuthorId}). Without it a refine of an
     * author-written part would silently drop back to the persona/session tone — the text would stop
     * sounding like its author the moment anyone touched it, which is the one thing a per-block author must
     * survive. A part with no author passes null, exactly as before.
     *
     * @param  callable(string): string  $resolvePrompt
     * @return array<string, mixed>
     */
    private function refineTextPart(GenerationSession $session, ContentTypePart $part, string $instruction, callable $resolvePrompt, ?string $authorId = null): array
    {
        try {
            $current = $this->currentTextOf($session, $part->key);
            $revised = $this->aiText->generate($this->revisionPrompt($resolvePrompt($instruction), $current), null, $authorId);

            // A BLANK revision is the ai-text generator's FAIL-CLOSED signal (any provider/transport/over-cap
            // error resolves to '', never throws). Treat it as a FAILED op so applyPartOp no-ops and the
            // CURRENT good text is PRESERVED (symmetric with the image-refine no-op) — never overwrite a good
            // post with an empty one and churn history. A legitimately empty revision is never desired here.
            if (trim($revised) === '') {
                return ['kind' => $part->kind->value, 'status' => 'failed', 'error' => __('generator.sessions.part_failed')];
            }

            return ['kind' => $part->kind->value, 'status' => 'ok', 'text' => $revised, 'version' => $this->currentVersionOf($session, $part->key) + 1];
        } catch (Throwable $e) {
            $this->logPartFailure('text_refine', $session, $part->key, $e);

            return ['kind' => $part->kind->value, 'status' => 'failed', 'error' => __('generator.sessions.part_failed')];
        }
    }

    /**
     * An AI EDIT of the CURRENT produced image at $partKey (the refine op): read the current version's bytes
     * and edit them with the (resolver-expanded) instruction as the prompt through the SAME metered
     * `ai_image_edit` seam, storing the result as a new version. Serves BOTH a top-level image_plan part AND a
     * nested `storyboard.<i>` shot (its current version resolves through {@see currentProducedVersion}) — the
     * result is `{kind:'image_plan', …}` either way, so the refiner's existing image machinery + blob GC
     * apply. No current produced image (never generated / failed) → a localized soft fail. Budget/provider
     * failures fail soft, non-secret.
     *
     * @param  callable(string): string  $resolvePrompt
     * @return array<string, mixed>
     */
    private function editImageAt(GenerationSession $session, string $partKey, string $instruction, callable $resolvePrompt): array
    {
        try {
            $currentVersion = $this->currentProducedVersion($session, $partKey);
            $bytes = $currentVersion > 0 ? $this->images->get($session->id, $partKey, $currentVersion) : null;

            if ($bytes === null) {
                return ['kind' => 'image_plan', 'status' => 'failed', 'error' => __('generator.sessions.refine_no_image')];
            }

            $produced = $this->imageChain->editImage($bytes, $resolvePrompt($instruction));
            $version = $this->images->storeVersion($session->id, $partKey, $produced['bytes']);
            $image = ['mime' => $produced['mime'], 'width' => $produced['width'], 'height' => $produced['height'], 'version' => $version];

            return ['kind' => 'image_plan', 'status' => 'ok', 'image' => $image, 'version' => $version];
        } catch (ImageChainException $e) {
            $this->logPartFailure('image_refine_chain', $session, $partKey, $e);

            return ['kind' => 'image_plan', 'status' => 'failed', 'error' => __($e->messageKey())] + $this->errorCodeOf($e, 'error_code');
        } catch (AiBudgetExceededException $e) {
            // A workspace monthly $ cost-cap stop is a BUDGET failure, not a bad base/filters — surface the
            // budget message (aligned with the ImageChainExecutor per-session budget path), not image_failed.
            return ['kind' => 'image_plan', 'status' => 'failed', 'error' => __('generator.sessions.image_budget')];
        } catch (Throwable $e) {
            $this->logPartFailure('image_refine', $session, $partKey, $e);

            return ['kind' => 'image_plan', 'status' => 'failed', 'error' => __('generator.sessions.image_failed')];
        }
    }

    /**
     * The current produced-image VERSION at $partKey, for an image refine — a top-level image_plan part (via
     * {@see currentVersionOf}) OR a nested `storyboard.<i>` shot (read from the storyboard result's shots). 0
     * when there is no current produced image (a failed / never-imaged shot, a non-image part).
     */
    private function currentProducedVersion(GenerationSession $session, string $partKey): int
    {
        $results = is_array($session->results) ? $session->results : [];

        if (preg_match('/^(.+)\.(\d+)$/', $partKey, $m) === 1) {
            $base = $results[$m[1]] ?? null;

            if (is_array($base) && ($base['kind'] ?? null) === PartKind::STORYBOARD->value) {
                $shot = (is_array($base['shots'] ?? null) ? array_values($base['shots']) : [])[(int) $m[2]] ?? null;

                if (is_array($shot) && ($shot['image_status'] ?? null) === 'ok') {
                    $version = $shot['image']['version'] ?? null;

                    return is_int($version) && $version > 0 ? $version : 0;
                }
            }

            return 0;
        }

        return $this->currentVersionOf($session, $partKey);
    }

    /**
     * The revision prompt for a text refine: the instruction + the current text framed EXPLICITLY as DATA. The
     * shared {@see \App\Modules\Variables\Agents\AiTextAgent} already treats the whole request as data-to-write-
     * about (injection-hardened), so an instruction embedded here cannot seize control; it only guides the
     * rewrite of the current text.
     */
    private function revisionPrompt(string $instruction, string $current): string
    {
        return 'Revise the text below according to the instruction. Return ONLY the revised text, with no '
            . "preamble, explanation, or quotes.\n\n"
            . "INSTRUCTION:\n" . $instruction . "\n\n"
            . "CURRENT TEXT:\n" . $current;
    }

    /**
     * Compose the run's DIRECTION anchor + the session's frozen CHARACTER into an AUTHORED image plan — the
     * plain-image counterpart of {@see produceStoryboardShotImage}'s composition, shared by an `image_plan`
     * part and a scene's image.
     *
     * Only an `ai_generate` base is touched: a `disk_file` / `from_slot` base has no prompt to anchor and
     * already HAS its image, so there is nothing for a character reference to substitute either.
     *
     * WHETHER THE CHARACTER APPEARS. A storyboard knows per shot (the shot list says so); a single authored
     * image has no such signal, so the default is that a delegated session's image DOES show its creator —
     * which is what "generate my post image" means when the whole session was handed to a persona. The
     * author overrides that per part with `character: 'never'` ({@see ImagePlanValidator}), for the product
     * shot / logo / chart case. There is no `always`: nothing to force, since auto already means yes.
     *
     * The authored prompt is resolved FIRST (its slots/directives must still expand), then the anchor +
     * guardrails are placed AHEAD of it — so the author's own prompt comes last and still has the final word,
     * exactly as the storyboard orders style vs. anchor.
     *
     * The returned resolver is IDENTITY for the FINAL composed string (the anchor is model-derived content
     * and the identity is frozen human content: both are DATA to draw, never round-tripped through the
     * directive resolver) and the REAL resolver for everything else, so the authored `ai_edit` filter prompts
     * still resolve normally. With no direction, no character and a non-ai_generate base, BOTH the plan and
     * the resolver are returned UNCHANGED and no reference is produced — such a run is byte-identical.
     *
     * The REFERENCE is independent of all of that: it follows only from whether the character is drawn (see
     * the note at the early exit).
     *
     * @param  array<string, mixed>  $plan
     * @param  callable(string): string  $resolvePrompt
     * @return array{plan: array<string, mixed>, resolve: callable(string): string, reference: string|null}
     */
    private function directedImagePlan(GenerationSession $session, array $plan, ?CreativeDirection $direction, callable $resolvePrompt): array
    {
        $base = is_array($plan['base'] ?? null) ? $plan['base'] : null;
        $isGenerated = $base !== null && ($base['kind'] ?? null) === 'ai_generate';

        $identity = $isGenerated ? $this->visualIdentityFor($session) : null;
        $drawsCharacter = $identity !== null && ($plan['character'] ?? 'auto') !== 'never';

        $anchor = $this->imageAnchor($direction, $drawsCharacter ? $identity?->subjectDescription() : null);
        $guardrails = $identity?->guardrails();

        // The REFERENCE is decided by whether the character is DRAWN, never by whether there was any text to
        // prepend. A likeness-only identity (generated from a one-off instruction, every written field left
        // blank) has no subject line and no guardrail to compose, yet the picture is then the WHOLE
        // description — so the early exit below must still hand the frozen bytes over, exactly as the
        // storyboard path does. Resolving it BEFORE the exit is what keeps the two paths honest; with no
        // character it stays null and such a run is byte-identical.
        $reference = $drawsCharacter ? $this->characterReferenceFor($session, $identity) : null;

        if (!$isGenerated || ($anchor === null && $guardrails === null)) {
            return ['plan' => $plan, 'resolve' => $resolvePrompt, 'reference' => $reference];
        }

        $authored = trim($resolvePrompt(is_string($base['prompt'] ?? null) ? $base['prompt'] : ''));

        $composed = implode("\n\n", array_values(array_filter(
            [$anchor, $guardrails, $authored],
            fn (?string $piece): bool => $piece !== null && $piece !== '',
        )));

        $base['prompt'] = $composed;
        $plan['base'] = $base;

        return [
            'plan' => $plan,
            'resolve' => fn (string $markdown): string => $markdown === $composed ? $composed : $resolvePrompt($markdown),
            'reference' => $reference,
        ];
    }

    /**
     * Run the chain for one image plan and store the produced bytes as a new VERSION under $partKey, returning
     * the wire image meta (`{mime,width,height,version}`) — the shared body of {@see executeImagePart} +
     * {@see renderSceneImage}. The plan/resolver it receives are already direction- and character-composed by
     * the caller (see {@see directedImagePlan}), so this stays a pure "run it and store it" seam.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $slotValues
     * @param  callable(string): string  $resolvePrompt
     * @param  string|null  $characterReference  the frozen likeness the base is drawn FROM, or null
     * @return array{mime: string, width: int, height: int, version: int}
     */
    private function produceImage(GenerationSession $session, string $partKey, array $plan, array $slotValues, callable $resolvePrompt, ?string $characterReference = null): array
    {
        $produced = $this->imageChain->execute($plan, $slotValues, $resolvePrompt, $characterReference);
        $version = $this->images->storeVersion($session->id, $partKey, $produced['bytes']);

        return ['mime' => $produced['mime'], 'width' => $produced['width'], 'height' => $produced['height'], 'version' => $version];
    }

    /**
     * Resolve a scene's narration, fail-soft to '' (an unresolvable narration must not sink the scene's image
     * or the other scenes; the FACT is logged, never the body).
     *
     * @param  array<string, mixed>|null  $narration
     * @param  array<string, mixed>  $execContext
     * @param  array<string, mixed>  $typeMap
     */
    private function renderNarration(GenerationSession $session, string $partKey, ?array $narration, array $execContext, array $typeMap): string
    {
        try {
            return $this->renderMarkdown($this->markdownOf($narration), $execContext, $typeMap);
        } catch (Throwable $e) {
            $this->logPartFailure('scene_narration', $session, $partKey, $e);

            return '';
        }
    }

    /**
     * The optional MACHINE-readable reason of a chain failure, as a fragment to merge into the result — an
     * empty array when the failure has none ({@see ImageChainException::errorCode}). Union-merged rather
     * than assigned so the key is genuinely ABSENT for every failure that has nothing specific to say, which
     * is what lets a consumer treat "has a code" as meaningful. $key is the result shape's own name for it:
     * a part result says `error_code` beside `error`, a per-item image says `image_error_code` beside
     * `image_error`.
     *
     * @return array<string, string>
     */
    private function errorCodeOf(ImageChainException $e, string $key): array
    {
        $code = $e->errorCode();

        return $code === null ? [] : [$key => $code];
    }

    /**
     * Log a fail-soft PART failure with diagnostic CONTEXT — the exception CLASS + message + source location +
     * the part it happened on (a `stage` label + partKey), so a `failed` part (or a `ready` run hiding one) is
     * traceable in the log. NEVER logs the prompt / slot values / instruction / produced content (only the
     * exception's own message, which carries no recipe content). Warning level: a per-part failure is
     * recoverable (the author can fix inputs and regenerate).
     */
    private function logPartFailure(string $stage, GenerationSession $session, ?string $partKey, Throwable $e): void
    {
        Log::warning('Generation session part failed', [
            'stage' => $stage,
            'session_id' => $session->id,
            'part_key' => $partKey,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'at' => $e->getFile() . ':' . $e->getLine(),
        ]);
    }

    /**
     * The current stored VERSION of a part (the `version` on its current `ok` result), or 0 when the part has
     * no current versioned result (a fresh whole-run's cleared results, or a failed/never-run part). Drives the
     * next-version stamp for text (image versions come from the store).
     */
    private function currentVersionOf(GenerationSession $session, string $partKey): int
    {
        $results = is_array($session->results) ? $session->results : [];
        $version = $results[$partKey]['version'] ?? null;

        return is_int($version) && $version > 0 ? $version : 0;
    }

    /** The current text of a part (its `text` on the current result), or '' when none — the refine base. */
    private function currentTextOf(GenerationSession $session, string $partKey): string
    {
        $results = is_array($session->results) ? $session->results : [];
        $text = $results[$partKey]['text'] ?? null;

        return is_string($text) ? $text : '';
    }

    /**
     * Resolve a markdown body/prompt to a string through the shared resolver (may throw on an assert_present
     * hard failure — the caller decides fail-soft handling).
     *
     * @param  array<string, mixed>  $execContext
     * @param  array<string, mixed>  $typeMap
     */
    private function renderMarkdown(string $markdown, array $execContext, array $typeMap): string
    {
        return $this->stringify($this->resolver->resolveString($markdown, $execContext, $typeMap));
    }

    /** The `{markdown}` string of a body-shaped part (text_body / script / a scene narration), or ''. */
    private function markdownOf(mixed $content): string
    {
        return is_array($content) && is_string($content['markdown'] ?? null) ? $content['markdown'] : '';
    }

    /**
     * Coerce the resolver result to a string. resolveString over a markdown body already returns a string;
     * this only guards the rare standalone-typed result (a bare `{{token}}` / directive) so a text part always
     * emits `text: string`.
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
