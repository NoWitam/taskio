<?php

namespace App\Modules\Generator\Http\Resources;

use App\Http\Resources\CreatorResource;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Support\StoryboardFrame;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A generation SESSION: its identity + provenance (`template_id`), the snapshotted `content_type`, the
 * async `status`, the user's filled `slot_values`, the per-part `results` map, the per-part refine
 * `part_history`, the creator, and server-authoritative capability flags (mirrors the Template / Constant /
 * Workflow convention).
 *
 * The recipe SNAPSHOT is intentionally NOT emitted — it is a large immutable blob the FE re-reads via the
 * content-types catalog + the source template; the session wire carries only what the chat surface needs
 * (inputs + per-part outputs + state). `results` is null until a run has produced it; each ok result now
 * carries a `version` the chat renders as "Wersja N". The bounded `history` stack is NOT emitted (it can
 * hold many prior snapshots); `part_history` exposes only its lean, per-part shape — the undo affordance.
 *
 * @property \App\Modules\Generator\Models\GenerationSession $resource
 */
class GenerationSessionResource extends JsonResource
{
    /**
     * Whether this instance is a LIST row (built by {@see lean}). A list row omits the detail-only fields
     * that cost real work per row and that no list screen reads. Declared as a real property so the
     * DelegatesToResource `__get` passthrough never sees it.
     */
    private bool $lean = false;

    /**
     * The INDEX projection: the same rows, minus the detail-only payload (today: `creative_direction`, whose
     * getter re-normalizes a stored direction on every access). Used by the index endpoint ONLY — every
     * single-resource response (show/store/update/generate/refine/undo/archive) keeps the full payload.
     */
    public static function lean(mixed $resource): AnonymousResourceCollection
    {
        $collection = static::collection($resource);

        foreach ($collection->collection as $item) {
            $item->lean = true;
        }

        return $collection;
    }

    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canUpdate = $user?->can('update', $this->resource) ?? false;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'template_id' => $this->template_id,
            'content_type' => $this->content_type,
            'status' => $this->status?->value,

            'slot_values' => is_array($this->slot_values) ? $this->slot_values : [],
            // Emitted verbatim EXCEPT for the storyboard's transient frame bookkeeping (see {@see publicResults}),
            // so each part result still carries any per-part cross-part `stale` flag (Phase A): refining an
            // UPSTREAM part sets `results.<downstreamPart>.stale = true` (a FE hint — no auto-cascade; a full
            // generate clears it), which the FE reads straight off `results[partKey].stale`.
            'results' => $this->publicResults(),

            // Per-part refine state (R2 sub-stage 2d): for each part that has a prior version, its undo depth
            // + a server-authoritative `can_undo` (creator AND the session is ready AND there is a prior).
            // The FE reads `results[partKey].version` for the "Wersja N" label and this to gate Undo.
            'part_history' => $this->partHistory($canUpdate),

            // Outcome of the MOST RECENT per-part regenerate/refine (R2 sub-stage 2d hardening): a failed op is
            // otherwise a silent no-op (the poll returns `ready` at the same version), so the FE reads these to
            // toast "Refine failed" instead of silently accepting an identical result. `last_op_status`:
            // 'ok' | 'failed' | null (null = no part op since the last claim / a full generate); `last_op_error`
            // a localized, non-secret message on a failure, else null. Both are cleared at the next claim.
            'last_op_status' => $this->last_op_status,
            'last_op_error' => $this->last_op_error,

            'creator' => CreatorResource::make($this->whenLoaded('creator')),

            // Capability flags (server-authoritative). `can_generate` = may kick off a run (whole-session
            // generate OR a per-part regenerate/refine) AND not already mid-run; `can_edit` = may change
            // inputs AND the state allows it (draft/ready).
            'is_owner' => $this->isOwnedBy($user),
            'can_generate' => $canUpdate && $this->status?->value !== 'generating',
            'can_edit' => $canUpdate && ($this->status?->isEditable() ?? false),
            'can_be_deleted' => $user?->can('delete', $this->resource) ?? false,

            // Bot-author delegation overlay (R2 sub-stage 3). `bot_author` is the SNAPSHOTTED {id,name,icon}
            // (or null) — read off the overlay, never the live bot. `is_delegated` is the flag. `can_delegate`
            // gates the delegate/undo affordance (creator AND editable — the same rights delegate needs).
            // `unfilled_required_slots` is a SOFT signal (the required slots still empty) — NOT a generate-gate.
            // `can_delegate` and `can_undo_delegation` gate DIFFERENT affordances and are NOT symmetric:
            //   - `can_delegate` needs an EDITABLE session (draft/ready) — delegating fills slots, a mutation.
            //   - `can_undo_delegation` mirrors the ACTUAL undo guard in BotSessionDelegationController::destroy:
            //     owner (`$canUpdate`) + already delegated + NOT mid-run. So a delegated `failed` session (or
            //     `draft`/`ready`) can still be reverted; only `generating` (an in-flight run) blocks undo.
            // The run's derived CREATIVE DIRECTION (the direction layer) — the shared frame the whole session
            // was made to, re-NORMALIZED on the way out (so nothing unvetted can reach the wire), or null
            // when the run derived none. Read-only provenance for the chat surface; a FULL run replaces it.
            // DETAIL ONLY: the list never reads it, and re-normalizing a stored direction costs a dozen
            // regex passes PER ROW, so the index projection ({@see lean}) omits the key entirely.
            'creative_direction' => $this->when(!$this->lean, fn (): ?array => $this->resource->creativeDirection()?->toArray()),

            'bot_author' => $this->botAuthor(),
            'is_delegated' => $this->isDelegated(),
            // Whether this session froze a CHARACTER LIKENESS with the delegation — i.e. whether its images
            // are drawn from the author's approved likeness rather than from a description. A flag, never the
            // identity itself: the descriptor / aesthetic / wardrobe / prohibitions are prompt material, and
            // the overlay that holds them is deliberately not on the wire.
            'has_character_image' => $this->resource->hasCharacterImage(),
            'can_delegate' => $canUpdate && ($this->status?->isEditable() ?? false),
            'can_undo_delegation' => $canUpdate && $this->resource->isDelegated() && $this->status !== GenerationSessionStatus::Generating,
            'unfilled_required_slots' => $this->resource->unfilledRequiredSlots(),

            // Archive lifecycle (R2 sub-stage 2d): `is_archived` reflects the marker; `can_archive` gates the
            // archive/unarchive toggle to the creator (same `update` capability as generate/edit). An archived
            // session is exempt from the reaper's trash + purge windows.
            'is_archived' => $this->archived_at !== null,
            'can_archive' => $canUpdate,

            'archived_at' => $this->archived_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * The per-part results as the API exposes them: the stored map minus the storyboard's IN-FLIGHT frame
     * bookkeeping (`frame_token`, `frame_claimed_at`).
     *
     * Those two keys are a correlation token and a claim stamp that exist only BETWEEN a frame being
     * announced and settling — {@see StoryboardFrame} already strips them on settle, so they leak onto the
     * wire only while a run is mid-flight, and only to the session's own owner. Nothing is gained by seeing
     * them (the token authorizes nothing — a frame job matches it against the row it was dispatched for,
     * inside the queue), and a client that learned to read them would be reading a lifetime the server owns.
     * Stripping them makes the wire shape of a settled run and a running one differ only in `image_status`,
     * which is the contract the FE already has.
     *
     * @return array<string, mixed>|null
     */
    private function publicResults(): ?array
    {
        $results = is_array($this->results) ? $this->results : null;

        if ($results === null) {
            return null;
        }

        foreach ($results as $partKey => $result) {
            if (!is_array($result) || !is_array($result['shots'] ?? null)) {
                continue;
            }

            foreach ($result['shots'] as $i => $shot) {
                if (is_array($shot)) {
                    unset($shot[StoryboardFrame::TOKEN_KEY], $shot[StoryboardFrame::CLAIMED_AT_KEY]);
                    $results[$partKey]['shots'][$i] = $shot;
                }
            }
        }

        return $results;
    }

    /**
     * The lean per-part undo shape from the bounded `history` stack: `{ "<partKey>": {can_undo, undo_depth} }`.
     * `undo_depth` = the number of prior versions available to undo to; `can_undo` also requires the caller to
     * be able to `update` the session AND the session to be `ready` (undo is invalid mid-run). Parts with no
     * history are simply absent (the FE treats a missing key as no-undo).
     *
     * @return array<string, array{can_undo: bool, undo_depth: int}>
     */
    private function partHistory(bool $canUpdate): array
    {
        $history = is_array($this->history) ? $this->history : [];
        $isReady = $this->status?->value === 'ready';

        $out = [];

        foreach ($history as $partKey => $stack) {
            if (!is_array($stack) || $stack === []) {
                continue;
            }

            $out[$partKey] = [
                'can_undo' => $canUpdate && $isReady,
                'undo_depth' => count($stack),
            ];
        }

        return $out;
    }
}
