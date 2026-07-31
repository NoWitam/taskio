<?php

namespace App\Modules\Generator\Services;

use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Enums\PartKind;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Support\ContentTypePart;
use App\Modules\Generator\Support\StoryboardFrame;
use App\Modules\Variables\Services\VariableResolver;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The per-part REFINE loop bookkeeping (R2 sub-stage 2d). The async claim/job/tenancy/meter machinery is reused
 * unchanged ({@see GenerationSessionRunManager} + {@see \App\Modules\Generator\Jobs\RunGenerationSessionJob});
 * this service owns the HISTORY + VERSION semantics that ride on top of a claimed part op:
 *
 *   regenerate / refine  (async, driven from the run manager): the {@see GenerationSessionExecutor} RENDERS the
 *                        one part (a fresh snapshot variation, or a revision of the current output); THIS
 *                        service then pushes the PRIOR result onto that part's bounded history stack and merges
 *                        the new result. A part op that could not render or that FAILED leaves the current good
 *                        state UNTOUCHED — a failed refine never clobbers a working result or churns history.
 *   undo                 (synchronous, no AI): pop the part's history top → make it current, DELETE the
 *                        just-undone version's blob(s). Empty history → 409. Persists directly.
 *
 * The bounded stack lives in the session's `history` json column: `{ "<partKey>": [ <priorResultSnapshot>, … ] }`
 * (most-recent last), capped at `config('generator.history_max_versions')`; dropping the oldest prior also GCs
 * its produced-image blob(s) via {@see GeneratedImageStore::deleteVersion}, so history never grows unbounded in
 * the row or on disk. Produced-image versions and their blobs stay in lock-step so an image undo restores real
 * bytes.
 *
 * Part keys are validated against the snapshot's content-type parts (an unknown key 404s); free-text refine is
 * only meaningful for a text/image part (a multi-scene scene_plan 422s). No secret (instruction / current
 * content / prompt) is ever surfaced or logged here.
 */
class GenerationSessionRefiner
{
    public function __construct(
        private GenerationSessionExecutor $executor,
        private GeneratedImageStore $images,
        private ContentTypeRegistry $registry,
        private VariableResolver $resolver,
    ) {}

    /**
     * The SNAPSHOT PART for $partKey (snapshot-authoritative, so a legacy script/scene_plan snapshot still
     * resolves after the Phase B recomposition), or abort 404 — the write-side guard the regenerate / undo
     * endpoints run before claiming. A `storyboard.<i>` sub-key resolves to the base STORYBOARD part when i is
     * within the CURRENT storyboard result's shots range (an out-of-range or non-storyboard dotted key 404s).
     */
    public function assertPart(GenerationSession $session, string $partKey): ContentTypePart
    {
        $part = $this->resolveAssertedPart($session, $partKey);

        abort_if($part === null, Response::HTTP_NOT_FOUND);

        return $part;
    }

    /**
     * Like {@see assertPart}, but also require the part to be REFINABLE by free text — a text body/script, an
     * image plan, a shot_list (structured revision), or a `storyboard.<i>` shot (an image edit). A BARE
     * scene_plan / storyboard (a multi-item composite) has no single current output to revise → 422 (regenerate
     * + undo still work for it).
     */
    public function assertRefinablePart(GenerationSession $session, string $partKey): ContentTypePart
    {
        $part = $this->assertPart($session, $partKey);

        // Only a storyboard SUB-key can reach here as a dotted key (assertPart 404s any other dotted key), and
        // a shot image IS refinable — so a resolved dotted key is refinable by construction.
        if (preg_match('/^(.+)\.(\d+)$/', $partKey) === 1) {
            return $part;
        }

        abort_unless($this->isRefinable($part->kind), Response::HTTP_UNPROCESSABLE_ENTITY, __('generator.sessions.refine_not_supported'));

        return $part;
    }

    /**
     * Resolve $partKey to a snapshot part, or null (→ 404). A bare key → the snapshot part (definition ∪
     * legacy). A `<key>.<i>` sub-key → the base part only when it is STORYBOARD-kind AND shot i exists in the
     * current storyboard result (Phase B per-shot addressing); any other dotted key is not addressable.
     */
    private function resolveAssertedPart(GenerationSession $session, string $partKey): ?ContentTypePart
    {
        $content = $this->snapshotContent($session);
        $contentType = (string) $session->content_type;

        if (preg_match('/^(.+)\.(\d+)$/', $partKey, $m) === 1) {
            $base = $this->registry->partForSnapshot($contentType, $content, $m[1]);

            if ($base === null || $base->kind !== PartKind::STORYBOARD) {
                return null;
            }

            return $this->storyboardShotExists($session, $m[1], (int) $m[2]) ? $base : null;
        }

        return $this->registry->partForSnapshot($contentType, $content, $partKey);
    }

    /** Whether shot $i exists in the storyboard result stored under $baseKey (the sub-key range check). */
    private function storyboardShotExists(GenerationSession $session, string $baseKey, int $i): bool
    {
        $results = is_array($session->results) ? $session->results : [];
        $base = $results[$baseKey] ?? null;
        $shots = is_array($base['shots'] ?? null) ? array_values($base['shots']) : [];

        return array_key_exists($i, $shots);
    }

    /**
     * Regenerate ONE part (async run-manager path): render a fresh snapshot variation, push the prior result to
     * history, merge the new one. Returns the updated `{results, history}` for the run manager to persist.
     *
     * @return array{results: array<string, mixed>, history: array<string, mixed>}
     */
    public function regenerate(GenerationSession $session, string $partKey): array
    {
        return $this->applyPartOp($session, $partKey, $this->executor->renderPartFromSnapshot($session, $partKey));
    }

    /**
     * Refine ONE part (async run-manager path): render a revision of the current output guided by $instruction,
     * push the prior result to history, merge the new one. Returns the updated `{results, history}`.
     *
     * @return array{results: array<string, mixed>, history: array<string, mixed>}
     */
    public function refine(GenerationSession $session, string $partKey, string $instruction): array
    {
        return $this->applyPartOp($session, $partKey, $this->executor->renderRefinedPart($session, $partKey, $instruction));
    }

    /**
     * UNDO one part (synchronous, no AI): restore the part's previous version and discard the just-undone one.
     * Pops the part's history top → sets it as the current result, and DELETES the previously-current version's
     * produced-image blob(s) (no redo in v1). An empty history → 409. Persists results + history on the session.
     *
     * A2 (concurrency): undo runs inside a DB TRANSACTION over a FRESH lockForUpdate() reload, re-checking
     * `status === ready` (and a non-empty history) UNDER the lock before mutating — so two racing undos
     * (double-click / two tabs) or an undo racing a completing refine can't collapse results/history or delete
     * a blob the winner still points at (409 when the re-check fails).
     * A3 (crash-consistency): the DB pointer COMMITS first; the just-undone blob(s) are GC'd only AFTER (a
     * crash in between then leaves a benign ORPHAN, not a dangling reference the serve endpoint 404s on).
     */
    public function undo(GenerationSession $session, string $partKey): void
    {
        $toGc = DB::transaction(function () use ($session, $partKey): array {
            $locked = GenerationSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->first();

            abort_if($locked === null, Response::HTTP_NOT_FOUND);
            abort_if($locked->status === GenerationSessionStatus::Generating, Response::HTTP_CONFLICT, __('generator.sessions.already_generating'));

            $history = is_array($locked->history) ? $locked->history : [];
            $stack = is_array($history[$partKey] ?? null) ? $history[$partKey] : [];

            // Undo is only valid on a READY session that still has a prior version under the lock.
            abort_if($locked->status !== GenerationSessionStatus::Ready || $stack === [], Response::HTTP_CONFLICT, __('generator.sessions.nothing_to_undo'));

            $results = is_array($locked->results) ? $locked->results : [];
            // Nested-aware: a `storyboard.<i>` undo restores the shot's prior sub-result into the storyboard's
            // shots array; a top-level key restores results[partKey] directly.
            $undone = $this->currentResultAt($results, $partKey);

            $results = $this->setResultAt($results, $partKey, array_pop($stack));

            if ($stack === []) {
                unset($history[$partKey]);
            } else {
                $history[$partKey] = $stack;
            }

            $locked->update(['results' => $results, 'history' => $history]);

            // A3: return the just-undone blob(s) so the caller GCs them AFTER this commit; the restored prior's
            // blobs stay live.
            return $undone === null ? [] : $this->blobRefsOf($partKey, $undone);
        });

        $this->deleteBlobs($session, $toGc);
    }

    /**
     * The CURRENT produced-image version for a part key — for the version-aware serve + save endpoints. Resolves
     * a top-level image part (`results[partKey]`) OR a scene image (`scene_plan.<i>` nested in the scene_plan
     * part's scenes). Null when there is no current produced image (a produced image without a version, a
     * failed/never-run part, or a non-image part) → the caller 404s.
     */
    public function currentImageVersion(GenerationSession $session, string $partKey): ?int
    {
        $results = is_array($session->results) ? $session->results : [];

        $top = $results[$partKey] ?? null;
        if (is_array($top) && ($top['kind'] ?? null) === 'image_plan' && ($top['status'] ?? null) === 'ok') {
            return $this->positiveIntOrNull($top['version'] ?? ($top['image']['version'] ?? null));
        }

        // A NESTED per-item image: `scene_plan.<i>` (scenes) OR `storyboard.<i>` (shots) — both keep one image
        // per item under the base part's list, addressed identically. The base result's kind selects the list.
        if (preg_match('/^(.+)\.(\d+)$/', $partKey, $matches) === 1) {
            $base = $results[$matches[1]] ?? null;
            $listKey = $this->nestedImageListKey(is_array($base) ? ($base['kind'] ?? null) : null);

            if ($listKey !== null) {
                $items = is_array($base[$listKey] ?? null) ? array_values($base[$listKey]) : [];
                $item = $items[(int) $matches[2]] ?? null;

                if (is_array($item) && ($item['image_status'] ?? null) === 'ok') {
                    return $this->positiveIntOrNull($item['image']['version'] ?? null);
                }
            }
        }

        return null;
    }

    /** The list key holding a nested per-item image result — scene_plan → `scenes`, storyboard → `shots`. */
    private function nestedImageListKey(mixed $kind): ?string
    {
        return match ($kind) {
            'scene_plan' => 'scenes',
            'storyboard' => 'shots',
            default => null,
        };
    }

    /**
     * Merge a rendered part result into the session's results, pushing the prior to history, and RETURN the op
     * OUTCOME the run manager persists alongside `ready`. A null result (unknown part) or a NON-`ok` result (a
     * failed regenerate/refine) is a content NO-OP — the current good result + history are returned unchanged so
     * a failed op never clobbers working content — but its `last_op_status:'failed'` + localized non-secret
     * `last_op_error` are surfaced so the FE can toast the failure instead of silently accepting an identical
     * result. An ok op reports `last_op_status:'ok'`. `gc_blobs` carries any history-cap-dropped blob(s) for the
     * run manager to GC AFTER the update commits (A3).
     *
     * @param  array<string, mixed>|null  $new
     * @return array{results: array<string, mixed>, history: array<string, mixed>, last_op_status: string, last_op_error: string|null, gc_blobs: array<int, array{partKey: string, version: int}>}
     */
    private function applyPartOp(GenerationSession $session, string $partKey, ?array $new): array
    {
        // A `storyboard.<i>` op writes back into the NESTED shot, not a top-level results key.
        if ($this->storyboardShotOp($session, $partKey) !== null) {
            return $this->applyStoryboardShotOp($session, $partKey, $new);
        }

        $results = is_array($session->results) ? $session->results : [];
        $history = is_array($session->history) ? $session->history : [];

        if ($new === null || ($new['status'] ?? null) !== 'ok') {
            $error = is_array($new) && is_string($new['error'] ?? null) ? $new['error'] : __('generator.sessions.part_failed');

            return ['results' => $results, 'history' => $history, 'last_op_status' => 'failed', 'last_op_error' => $error, 'gc_blobs' => []];
        }

        $current = is_array($results[$partKey] ?? null) ? $results[$partKey] : null;

        $gcBlobs = [];
        $history = $this->pushHistory($history, $partKey, $current, $gcBlobs);
        $results[$partKey] = $new;

        // Cross-part staleness (Phase A): $partKey's output just CHANGED, so any DOWNSTREAM part that
        // references `parts.<partKey>` in its authored recipe is now potentially incoherent. Mark those
        // downstream results `stale: true` (a FE hint) — NEVER auto-cascade a re-run (cost). A full generate
        // rebuilds every part fresh, clearing the flag; regenerating the downstream part itself replaces its
        // result (no stale). Phase B's storyboard-staleness reuses this exact dependency hook.
        $results = $this->markDownstreamStale($session, $results, $partKey);

        return ['results' => $results, 'history' => $history, 'last_op_status' => 'ok', 'last_op_error' => null, 'gc_blobs' => $gcBlobs];
    }

    /**
     * Apply a `storyboard.<i>` per-shot image op (Phase B): merge the new image sub-result into the NESTED
     * `results[<storyboardKey>][shots][i]` (its image/image_status/version), push the PRIOR shot sub-result
     * onto `history['storyboard.<i>']`, and GC on cap — the nested parallel of the top-level {@see applyPartOp}
     * merge. A null / non-ok sub-result is a content NO-OP (the current shot is preserved) with a surfaced
     * `last_op_status:'failed'`. Storyboard is terminal (nothing references it) so no downstream staleness runs.
     *
     * @param  array<string, mixed>|null  $new
     * @return array{results: array<string, mixed>, history: array<string, mixed>, last_op_status: string, last_op_error: string|null, gc_blobs: array<int, array{partKey: string, version: int}>}
     */
    private function applyStoryboardShotOp(GenerationSession $session, string $partKey, ?array $new): array
    {
        $results = is_array($session->results) ? $session->results : [];
        $history = is_array($session->history) ? $session->history : [];
        $ref = $this->storyboardShotOp($session, $partKey);

        if ($ref === null || $new === null || ($new['status'] ?? null) !== 'ok') {
            $error = is_array($new) && is_string($new['error'] ?? null) ? $new['error'] : __('generator.sessions.part_failed');

            return ['results' => $results, 'history' => $history, 'last_op_status' => 'failed', 'last_op_error' => $error, 'gc_blobs' => []];
        }

        $storyboard = is_array($results[$ref['key']] ?? null) ? $results[$ref['key']] : [];
        $shots = is_array($storyboard['shots'] ?? null) ? array_values($storyboard['shots']) : [];
        $current = is_array($shots[$ref['index']] ?? null) ? $shots[$ref['index']] : null;

        $gcBlobs = [];
        $history = $this->pushHistory($history, $partKey, $current, $gcBlobs);

        $shots[$ref['index']] = $this->mergeShotImage($current, $new, $partKey);
        $storyboard['shots'] = $shots;
        $results[$ref['key']] = $storyboard;

        return ['results' => $results, 'history' => $history, 'last_op_status' => 'ok', 'last_op_error' => null, 'gc_blobs' => $gcBlobs];
    }

    /**
     * Resolve a `storyboard.<i>` op to `{key, index}` when $partKey addresses a shot of a STORYBOARD result
     * (the base result kind is `storyboard`), else null. Result-kind-driven, so it works uniformly for the
     * regenerate / refine / undo paths without re-reading the snapshot.
     *
     * @return array{key: string, index: int}|null
     */
    private function storyboardShotOp(GenerationSession $session, string $partKey): ?array
    {
        if (preg_match('/^(.+)\.(\d+)$/', $partKey, $m) !== 1) {
            return null;
        }

        $results = is_array($session->results) ? $session->results : [];
        $base = $results[$m[1]] ?? null;

        return is_array($base) && ($base['kind'] ?? null) === 'storyboard' ? ['key' => $m[1], 'index' => (int) $m[2]] : null;
    }

    /**
     * Merge a fresh image sub-result (`{kind:'image_plan', image, version}`) into a storyboard SHOT entry:
     * flip it to `image_status:'ok'` with the new image + part_key, preserving the shot's descriptive fields
     * (index/visual/voiceover/seconds) and clearing any prior `image_error`.
     *
     * Delegates to the SHARED terminal-shot shape ({@see StoryboardFrame::settledOk}) so a shot settled by a
     * refine and a shot settled by a frame job are indistinguishable — including dropping any in-flight
     * frame bookkeeping, which a refine should never be able to leave behind on a shot it rewrote.
     *
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>  $new
     * @return array<string, mixed>
     */
    private function mergeShotImage(?array $current, array $new, string $partKey): array
    {
        return StoryboardFrame::settledOk(
            is_array($current) ? $current : [],
            is_array($new['image'] ?? null) ? $new['image'] : [],
            $partKey,
        );
    }

    /**
     * Push $current onto $partKey's bounded history stack (most-recent last). When the stack exceeds
     * `history_max_versions`, drop the OLDEST prior and COLLECT its produced-image blob ref(s) into $gcBlobs. A
     * null current (the part had no prior result) pushes nothing.
     *
     * A3: the dropped blob(s) are NOT deleted here — they are handed back so the run manager GCs them only
     * AFTER the new history/results pointer commits (a crash before the GC then leaves a benign ORPHAN, not a
     * dangling reference).
     *
     * @param  array<string, mixed>  $history
     * @param  array<string, mixed>|null  $current
     * @param  array<int, array{partKey: string, version: int}>  $gcBlobs  accumulator for cap-dropped blob refs
     * @return array<string, mixed>
     */
    private function pushHistory(array $history, string $partKey, ?array $current, array &$gcBlobs): array
    {
        if ($current === null) {
            return $history;
        }

        $stack = is_array($history[$partKey] ?? null) ? $history[$partKey] : [];
        $stack[] = $current;

        $cap = max(1, (int) config('generator.history_max_versions', 20));

        while (count($stack) > $cap) {
            $dropped = array_shift($stack);

            foreach ($this->blobRefsOf($partKey, is_array($dropped) ? $dropped : []) as $ref) {
                $gcBlobs[] = $ref;
            }
        }

        $history[$partKey] = $stack;

        return $history;
    }

    /**
     * GC produced-image blob(s) dropped by a history-cap overflow — called by the run manager AFTER the session
     * update persists the new pointer (A3: commit the pointer first so a crash leaves a benign orphan rather
     * than a dangling reference). A no-op for a text-only op ($refs empty).
     *
     * @param  array<int, array{partKey: string, version: int}>  $refs
     */
    public function gcDroppedBlobs(GenerationSession $session, array $refs): void
    {
        $this->deleteBlobs($session, $refs);
    }

    /**
     * Mark every DOWNSTREAM part that references `parts.<changedKey>` as `stale: true` in $results (cross-part
     * staleness, Phase A). The dependency map is computed from the immutable snapshot's authored content
     * ({@see crossPartDependents}) — content-authoritative, independent of the run outputs — so it is stable
     * for the life of the session. Only a part with an EXISTING result is marked (a never-generated downstream
     * has nothing to stale); the `stale` flag rides in the result dict the Resource emits verbatim.
     *
     * @param  array<string, mixed>  $results
     * @return array<string, mixed>
     */
    private function markDownstreamStale(GenerationSession $session, array $results, string $changedKey): array
    {
        foreach ($this->crossPartDependents($session, $changedKey) as $dependentKey) {
            if (is_array($results[$dependentKey] ?? null)) {
                $results[$dependentKey]['stale'] = true;
            }
        }

        return $results;
    }

    /**
     * The OTHER part keys whose authored snapshot content references `parts.<changedKey>` — the downstream
     * dependents of the just-changed part. Walks the snapshot's per-part content through the shared resolver
     * scanner, so a `parts.<changedKey>` reference is found WHEREVER it resolves — a directive, an ai-text
     * prompt, or an if-block condition/body. A part never depends on itself (write-blocked earlier-only anyway).
     *
     * @return array<int, string>
     */
    private function crossPartDependents(GenerationSession $session, string $changedKey): array
    {
        $content = $this->snapshotContent($session);
        $parts = $this->registry->partsForSnapshot((string) $session->content_type, $content);

        $dependents = [];

        foreach ($parts as $part) {
            if ($part->key === $changedKey) {
                continue;
            }

            // (a) an authored `parts.<changedKey>` TEXT reference in this part's content (Phase A scan).
            if (in_array($changedKey, $this->referencedPartKeys($content[$part->key] ?? null), true)) {
                $dependents[] = $part->key;

                continue;
            }

            // (b) STRUCTURAL intra-composition dependency (Phase B): a storyboard reads its sibling shot_list's
            // STRUCTURED shots DIRECTLY (not via `parts.*`, so the Phase-A scan can't see it), so a change to
            // that shot_list makes the storyboard stale. NEVER auto-regenerated — only flagged (cost).
            if ($part->kind === PartKind::STORYBOARD && $this->firstShotListKeyBefore($parts, $part->key) === $changedKey) {
                $dependents[] = $part->key;
            }
        }

        return $dependents;
    }

    /** The snapshot's per-part authored content map (defensively shaped). */
    private function snapshotContent(GenerationSession $session): array
    {
        $snapshot = is_array($session->recipe_snapshot) ? $session->recipe_snapshot : [];

        return is_array($snapshot['content'] ?? null) ? $snapshot['content'] : [];
    }

    /**
     * The key of the FIRST shot_list-kind part declared BEFORE $storyboardKey in the ordered parts — the
     * storyboard's intra-composition source (mirrors the executor's resolution). Null when none.
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
     * The CURRENT result at $partKey — a top-level `results[partKey]`, or a NESTED `storyboard.<i>` shot
     * (`results[<storyboardKey>][shots][i]`). Null when absent. Used by undo to read the sub-result it discards.
     *
     * @param  array<string, mixed>  $results
     * @return array<string, mixed>|null
     */
    private function currentResultAt(array $results, string $partKey): ?array
    {
        if (preg_match('/^(.+)\.(\d+)$/', $partKey, $m) === 1) {
            $base = $results[$m[1]] ?? null;

            if (is_array($base) && ($base['kind'] ?? null) === 'storyboard') {
                $shots = is_array($base['shots'] ?? null) ? array_values($base['shots']) : [];

                return is_array($shots[(int) $m[2]] ?? null) ? $shots[(int) $m[2]] : null;
            }
        }

        return is_array($results[$partKey] ?? null) ? $results[$partKey] : null;
    }

    /**
     * Set the result at $partKey — a top-level key, or a NESTED `storyboard.<i>` shot — returning the updated
     * results. The nested write parallels {@see applyStoryboardShotOp}'s merge (used by undo's restore).
     *
     * @param  array<string, mixed>  $results
     * @return array<string, mixed>
     */
    private function setResultAt(array $results, string $partKey, mixed $value): array
    {
        if (preg_match('/^(.+)\.(\d+)$/', $partKey, $m) === 1) {
            $base = $results[$m[1]] ?? null;

            if (is_array($base) && ($base['kind'] ?? null) === 'storyboard') {
                $shots = is_array($base['shots'] ?? null) ? array_values($base['shots']) : [];
                $shots[(int) $m[2]] = $value;
                $base['shots'] = $shots;
                $results[$m[1]] = $base;

                return $results;
            }
        }

        $results[$partKey] = $value;

        return $results;
    }

    /**
     * The set of part keys a piece of authored content references via `parts.<key>` — walking EVERY string
     * leaf of the (nested) part content through the shared {@see VariableResolver::collectReferenceIds}
     * scanner, so a `parts.<key>` reference is found WHEREVER the resolver would resolve one: a top-level
     * `@[variable]` directive, an `@[ai-text]` prompt, or an if-block CONDITION/BODY — not just the flat
     * top-level directives a plain regex sees (this is the C1 fix — the staleness scan can no longer
     * UNDER-mark a downstream dependent whose `parts.<key>` ref is nested). Keeps the FIRST path segment
     * after the `parts.` root (cross-part refs are TEXT-ONLY — no meaningful subfields).
     *
     * @return array<int, string>
     */
    private function referencedPartKeys(mixed $content): array
    {
        $keys = [];

        foreach ($this->referenceIds($content) as $id) {
            if (preg_match('/^parts\.([a-zA-Z_][a-zA-Z0-9_]*)/', $id, $m) === 1) {
                $keys[] = $m[1];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Recursively collect every referenced variable id from a (possibly nested) content value — the markdown
     * strings of a body, a scene narration, an image-plan prompt, etc. Each STRING leaf is scanned through the
     * shared resolver scanner (single source of truth with runtime resolution — it descends into ai-text
     * prompts + if-block markers), so this can NEVER drift from what the resolver actually resolves.
     *
     * @return array<int, string>
     */
    private function referenceIds(mixed $content): array
    {
        if (is_array($content)) {
            $ids = [];

            foreach ($content as $value) {
                foreach ($this->referenceIds($value) as $id) {
                    $ids[] = $id;
                }
            }

            return $ids;
        }

        if (!is_string($content) || $content === '') {
            return [];
        }

        return $this->resolver->collectReferenceIds($content);
    }

    /**
     * The produced-image blob references a part result owns — `[{partKey, version}, …]`. A top-level image part
     * → its own `(partKey, version)`; a scene_plan → each ok scene image's `(scene_plan.<i>, version)`; a text
     * part → none. Drives the version-blob GC on history-cap drop + undo discard.
     *
     * PUBLIC because it is also the single AUTHORITY for "which part keys of this session currently carry a
     * produced image" — the question {@see GeneratedImageExporter::producedImagePartKeys} answers for a
     * SERVER-SIDE caller that must export a whole run's images (R2 sub-stage 5). Re-deriving that walk
     * elsewhere would fork the per-kind shape knowledge (`image_plan` vs `scenes`/`shots`), so the enumeration
     * reads it from here. Still a PURE read over an already-hydrated result — no query, no write.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array{partKey: string, version: int}>
     */
    public function blobRefsOf(string $partKey, array $result): array
    {
        $kind = $result['kind'] ?? null;

        if ($kind === 'image_plan' && ($result['status'] ?? null) === 'ok') {
            $version = $this->positiveIntOrNull($result['version'] ?? ($result['image']['version'] ?? null));

            return $version === null ? [] : [['partKey' => $partKey, 'version' => $version]];
        }

        if ($kind === 'scene_plan' || $kind === 'storyboard') {
            $listKey = $kind === 'storyboard' ? 'shots' : 'scenes';
            $refs = [];

            foreach (array_values(is_array($result[$listKey] ?? null) ? $result[$listKey] : []) as $i => $item) {
                if (is_array($item) && ($item['image_status'] ?? null) === 'ok') {
                    $version = $this->positiveIntOrNull($item['image']['version'] ?? null);

                    if ($version !== null) {
                        $refs[] = ['partKey' => $partKey . '.' . $i, 'version' => $version];
                    }
                }
            }

            return $refs;
        }

        // A storyboard SHOT-ENTRY snapshot (a nested `storyboard.<i>` op's history/undo item): no `kind`, an
        // `image_status:'ok'` with its own image version stored under $partKey (the `storyboard.<i>` key).
        if ($kind === null && ($result['image_status'] ?? null) === 'ok') {
            $version = $this->positiveIntOrNull($result['image']['version'] ?? null);

            return $version === null ? [] : [['partKey' => $partKey, 'version' => $version]];
        }

        return [];
    }

    /**
     * Delete every produced-image blob in $refs.
     *
     * @param  array<int, array{partKey: string, version: int}>  $refs
     */
    private function deleteBlobs(GenerationSession $session, array $refs): void
    {
        foreach ($refs as $ref) {
            $this->images->deleteVersion($session->id, $ref['partKey'], $ref['version']);
        }
    }

    private function isRefinable(PartKind $kind): bool
    {
        return $kind === PartKind::TEXT_BODY
            || $kind === PartKind::SCRIPT
            || $kind === PartKind::IMAGE_PLAN
            || $kind === PartKind::SHOT_LIST;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }
}
