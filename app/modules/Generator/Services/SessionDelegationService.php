<?php

namespace App\Modules\Generator\Services;

use App\Models\Scopes\WorkspaceScope;
use App\Modules\Disk\Models\File;
use App\Modules\Generator\Enums\SlotScopePolicy;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Services\ConstantTypeValidator;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The Generator-side seams an AUTOMATIC FILLER uses — bot-AGNOSTIC by construction: every method takes the
 * Generator's own {@see GenerationSession} plus PRIMITIVES / opaque strings (never a Bot class), so
 * Generator NEVER imports Bot (the one-way Bot → Generator edge; pinned by the boundary tests). The Bot
 * module composes the voice + author snapshot and calls these; the R2 sub-stage 5 automation seam
 * ({@see SessionAutomationService}) reuses the SAME fill path under a different SCOPE
 * ({@see SlotScopePolicy}) — a parameter, never a fork.
 *
 * Single-row operations, no Action layer (per the backend rules — a Service owns its writes):
 *   - {@see introspectSlots}      the IN-SCOPE typed slot descriptors + current values a filler may fill,
 *   - {@see applySlotValues}      re-validate proposed values per descriptor + scope, drop the rest, persist,
 *   - {@see applyBotSlotValues}   the BOT-scoped wrapper over applySlotValues (R2 sub-stage 3),
 *   - {@see applyDelegation}      stamp the WHOLE overlay (bot author + snapshotted voice), all-or-nothing,
 *   - {@see clearDelegation}      null the WHOLE overlay (undo → the human voice).
 *
 * NEVER logs voice / slot values / bot content (security.md) — these methods surface only structured facts
 * (a per-slot fill report), never the values themselves.
 */
class SessionDelegationService
{
    public function __construct(
        private ConstantTypeValidator $descriptors,
        private TenantContext $tenant,
    ) {}

    /**
     * The slots an automatic filler may fill under $policy: the snapshot's declared slots whose descriptor
     * is IN SCOPE ({@see SlotScopePolicy::accepts}), each with its current value. Always EXCLUDES DEFERRED
     * composites (an `array<object>`, or an object nesting another object/file beyond one level); `file`
     * slots are excluded for a BOT and included for AUTOMATION (D4 — see the policy). Read from the
     * immutable `recipe_snapshot.slots` (never the live template).
     *
     * The policy DEFAULTS to Bot so the existing delegation caller is unchanged.
     *
     * @return array<int, array{name: string, description: string|null, descriptor: array<string, mixed>, value: mixed}>
     */
    public function introspectSlots(GenerationSession $session, SlotScopePolicy $policy = SlotScopePolicy::Bot): array
    {
        $slots = $this->snapshotSlots($session);
        $values = is_array($session->slot_values) ? $session->slot_values : [];

        $out = [];

        foreach ($slots as $slot) {
            if (!is_array($slot) || !is_string($slot['name'] ?? null)) {
                continue;
            }

            $descriptor = is_array($slot['descriptor'] ?? null) ? $slot['descriptor'] : null;

            if ($descriptor === null || !$policy->accepts($descriptor)) {
                continue;
            }

            $out[] = [
                'name' => $slot['name'],
                'description' => is_string($slot['description'] ?? null) ? $slot['description'] : null,
                'descriptor' => $descriptor,
                'value' => $values[$slot['name']] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Persist the bot's proposed slot values — the BOT-scoped wrapper over the shared {@see applySlotValues}
     * (its signature, semantics and return shape are unchanged; the whole bot-delegation suite pins that).
     *
     * @param  array<string, mixed>  $values  the bot's proposed {slotName: value} map (untrusted model output)
     * @return array{filled: array<int, string>, skipped: array<int, array{name: string, reason: string}>, unfilled_required: array<int, string>}
     */
    public function applyBotSlotValues(GenerationSession $session, array $values): array
    {
        return $this->applySlotValues($session, $values, SlotScopePolicy::Bot);
    }

    /**
     * Persist an automatic filler's proposed slot values, but ONLY those that are in scope for $policy AND
     * type-valid. Each value is re-VALIDATED against its slot descriptor through the shared
     * {@see ConstantTypeValidator} (the SAME descriptor authority a constant/slot uses — which also rejects
     * NUL bytes) BEFORE it is written; an unknown / out-of-scope / invalid value is DROPPED (never persisted
     * raw) and reported. The accepted map is MERGED into the existing `slot_values` (a human's prior fills
     * survive). Returns a per-slot FILL REPORT. No-op (nothing persisted) when the session is not editable —
     * a defensive guard on top of the caller's.
     *
     * The SCOPE is the only thing $policy changes (which descriptors are offerable at all — notably FILE
     * slots, D4); the validation authority, the drop-don't-persist posture, the merge and the report shape
     * are IDENTICAL for every scope. A `file` slot's value has no literal semantics in the shared validator
     * (a file is a reference, not a literal), so its authority is the tenant-scoped `File` model itself:
     * see {@see fileSlotSnapshot}.
     *
     * @param  array<string, mixed>  $values  the proposed {slotName: value} map (untrusted input)
     * @return array{filled: array<int, string>, skipped: array<int, array{name: string, reason: string}>, unfilled_required: array<int, string>}
     */
    public function applySlotValues(GenerationSession $session, array $values, SlotScopePolicy $policy): array
    {
        if (!$session->status->isEditable()) {
            return ['filled' => [], 'skipped' => [], 'unfilled_required' => $this->unfilledRequired($session, [])];
        }

        $descriptorsByName = $this->offerableDescriptorsByName($session, $policy);
        $known = $this->descriptorsByName($session);

        $accepted = [];
        $skipped = [];

        foreach ($values as $name => $value) {
            $name = (string) $name;

            if (!array_key_exists($name, $known)) {
                $skipped[] = ['name' => $name, 'reason' => 'unknown_slot'];

                continue;
            }

            if (!array_key_exists($name, $descriptorsByName)) {
                // A declared but out-of-scope slot (a deferred composite; a file for a BOT) — never fillable
                // under this scope.
                $skipped[] = ['name' => $name, 'reason' => 'out_of_scope'];

                continue;
            }

            $descriptor = $descriptorsByName[$name];

            // A FILE slot (reachable only under a policy that allows one) is a REFERENCE: it is accepted
            // only when it resolves through the tenant-scoped File model, and what gets persisted is the
            // SERVER's own snapshot of that row — never the caller's map.
            if (($descriptor['base'] ?? null) === VariableType::FILE->value) {
                ['ok' => $ok, 'value' => $snapshot] = $this->fileSlotSnapshot($session, $descriptor, $value);

                if (!$ok) {
                    $skipped[] = ['name' => $name, 'reason' => 'invalid'];

                    continue;
                }

                $accepted[$name] = $snapshot;

                continue;
            }

            if (!$this->valueMatchesDescriptor($descriptor, $value)) {
                $skipped[] = ['name' => $name, 'reason' => 'invalid'];

                continue;
            }

            $accepted[$name] = $value;
        }

        if ($accepted !== []) {
            $session->slot_values = array_merge(
                is_array($session->slot_values) ? $session->slot_values : [],
                $accepted,
            );
            $session->save();
        }

        return [
            'filled' => array_keys($accepted),
            'skipped' => $skipped,
            'unfilled_required' => $this->unfilledRequired($session, $accepted),
        ];
    }

    /**
     * Stamp the WHOLE bot-author overlay in one save (all-or-nothing): the provenance `bot_author_id`, and
     * `bot_delegation = {author, voice, snapshot_at, slot_values_before}`. Re-delegation OVERWRITES the whole
     * overlay (no stale bleed from a prior bot). The human `creator` is UNCHANGED (still the owner). No-op when
     * not editable (defensive; the controller guards first).
     *
     * REVERSIBILITY (R2 sub-stage 3 hardening): `slot_values_before` SNAPSHOTS the session's slot_values AS
     * THEY ARE AT DELEGATION TIME — the human's own inputs, captured BEFORE the bot's autonomous fill overwrites
     * anything (the controller stamps the overlay FIRST, THEN fills). {@see clearDelegation} restores it, so undo
     * fully reverts the bot's work (including any slot the bot overwrote) with ZERO data loss. Re-delegation
     * snapshots whatever is there when the CURRENT delegation begins (a prior bot's residue if never undone) —
     * undo then reverts to that, which is correct. NEVER logged (slot values are content).
     *
     * @param  array<string, mixed>  $authorSnapshot  the denormalized {id, name, icon} bot snapshot
     */
    public function applyDelegation(GenerationSession $session, string $voice, array $authorSnapshot, string $botAuthorId): void
    {
        if (!$session->status->isEditable()) {
            return;
        }

        $session->bot_author_id = $botAuthorId;
        $session->bot_delegation = [
            'author' => $authorSnapshot,
            'voice' => $voice,
            'snapshot_at' => now()->toISOString(),
            'slot_values_before' => is_array($session->slot_values) ? $session->slot_values : [],
        ];
        $session->save();
    }

    /**
     * Clear the WHOLE overlay (undo → the human's own voice) — RESTORING the pre-delegation slot_values from the
     * overlay's `slot_values_before` snapshot, then nulling both columns, in one save. So a `delegate → undo`
     * fully reverts the bot's autonomous fill (the human's original inputs come back; the bot's fills are
     * discarded) with no data loss. Idempotent (an undelegated session has no snapshot → slot_values untouched);
     * the `creator` (owner) is untouched. NOT status-guarded here — a `failed` (non-editable) session must still
     * be able to undo (the controller only blocks a `generating` one); the restore is the whole point of
     * reversible delegation. NEVER logged (slot values are content).
     */
    public function clearDelegation(GenerationSession $session): void
    {
        $before = is_array($session->bot_delegation) ? ($session->bot_delegation['slot_values_before'] ?? null) : null;

        if (is_array($before)) {
            $session->slot_values = $before;
        }

        $session->bot_author_id = null;
        $session->bot_delegation = null;
        $session->save();
    }

    /**
     * The required slots still empty (the SOFT delegation signal) — a read-only view over the current values,
     * no fills applied. Delegates to the model's PURE {@see GenerationSession::unfilledRequiredSlots} (the single
     * source of truth), so the sessions-LIST resource can compute this straight off the hydrated row WITHOUT
     * resolving this service per row.
     *
     * @return array<int, string>
     */
    public function unfilledRequiredSlots(GenerationSession $session): array
    {
        return $session->unfilledRequiredSlots();
    }

    // ---- internals -------------------------------------------------------------

    /**
     * Type-check ONE value against a slot descriptor via the shared authority (which also rejects NUL
     * bytes). A fresh throwaway Validator collects the granular errors; the value is accepted only when it
     * leaves NO error — the same descriptor authority a human write would use, so a bot can never smuggle a
     * shape the resolver/catalog cannot handle.
     */
    private function valueMatchesDescriptor(array $descriptor, mixed $value): bool
    {
        $validator = Validator::make([], []);
        $this->descriptors->validate($validator, $descriptor, $value);

        return $validator->errors()->isEmpty();
    }

    /**
     * Accept (or refuse) a FILE slot's proposed value and return WHAT to persist. A file slot holds a
     * REFERENCE, not a literal, so the shared descriptor validator has no value semantics for it — the
     * authority here is the tenant-scoped {@see File} model:
     *
     *   - an "empty" proposal (null / '' / []) means NO FILE: accepted only for a NULLABLE slot, and stored
     *     as a plain null (so a required file slot stays reported as unfilled);
     *   - anything else must resolve to a LIVE file in the SESSION's workspace (a bare id string or a
     *     `{id, …}` snapshot — the two shapes the image base resolver already accepts). A foreign-workspace,
     *     deleted, disk-trashed, malformed or invented id simply does not resolve → refused (reported
     *     `invalid`);
     *   - what is PERSISTED is the SERVER's snapshot of the resolved row, never the caller's map — so no
     *     caller-authored string (a forged name/url) can ever reach the render context through a file slot.
     *     The keys mirror the shared resolver's file-snapshot shape ({id, name, mime_type, size, url}),
     *     which is what a `slots.<name>.<subfield>` reference reads.
     *
     * NUL GUARD: the non-file path is NUL-guarded by the shared {@see ConstantTypeValidator} ("the injection
     * mask assumes resolved values are NUL-free"); this branch bypasses that authority BY DESIGN (a file is a
     * reference, not a literal), so it carries its OWN guard. A NUL byte in any string the server copies off
     * the row (notably `name` — {@see \App\Modules\Disk\Http\Requests\UpdateFileRequest} admits it, and a
     * connection on a driver that accepts NUL, a mutator or an imported row can produce one) would collide
     * with the resolver's embedded-directive stash tokens and corrupt a rendered part. Fail CLOSED, exactly
     * like the shared validator: refuse the whole slot rather than sanitize it.
     *
     * @param  array<string, mixed>  $descriptor
     * @return array{ok: bool, value: array<string, mixed>|null}
     */
    private function fileSlotSnapshot(GenerationSession $session, array $descriptor, mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return ['ok' => ($descriptor['nullable'] ?? false) === true, 'value' => null];
        }

        $file = $this->resolveFile($session, $value);

        if ($file === null) {
            return ['ok' => false, 'value' => null];
        }

        $snapshot = [
            'id' => $file->id,
            'name' => $file->name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'url' => $file->serveUrl(),
        ];

        foreach ($snapshot as $field) {
            if (is_string($field) && str_contains($field, "\0")) {
                return ['ok' => false, 'value' => null];
            }
        }

        return ['ok' => true, 'value' => $snapshot];
    }

    /**
     * Resolve a file REFERENCE (a bare id string or a `{id, …}` snapshot) to a live Disk file in the
     * SESSION's OWN workspace, or null. The id is uuid-shape-checked BEFORE the query (the column is a uuid,
     * so a garbage string would otherwise be a database error rather than a clean refusal).
     *
     * The tenant boundary is pinned to the SESSION, not to the ambient context: {@see WorkspaceScope} adds
     * its predicate only while a SHARED workspace is active, and is a documented NO-OP with no active
     * workspace (queue jobs, console) — an ambient-scope-only lookup would then run UNCONSTRAINED and resolve
     * a FOREIGN file. This seam is reachable from a queued step, so the explicit `workspace_id` predicate
     * makes the refusal correct WITH OR WITHOUT a TenantContext. In own-database mode the session carries no
     * `workspace_id` (tenant tables omit the column) and the dedicated connection IS the boundary, so the
     * predicate is only added when the session actually carries one.
     *
     * WHAT IS INTENTIONALLY *NOT* RESTRICTED: the container kind. Any NON-trashed file in the session's
     * workspace resolves — a temp upload (no container yet), a task attachment, a form-submission attachment,
     * a report output, as well as a disk-native file. That is the PRIMARY use case: `{{trigger.fields.attachment}}`
     * hands an automated run a form-submission attachment, which is not disk-native. The workflow author is
     * trusted and the TENANT SCOPE is the boundary — not `fileable_type`.
     *
     * Excluded: soft-deleted rows (SoftDeletes) and DISK-TRASHED ones. The disk-trash MARKER is checked on its
     * own rather than leaning on SoftDeletes to imply it, because the two are not the same thing (detaching a
     * task attachment soft-deletes its file without it ever being disk trash).
     */
    private function resolveFile(GenerationSession $session, mixed $value): ?File
    {
        $id = is_string($value) ? $value : null;

        if ($id === null && is_array($value)) {
            $candidate = $value['id'] ?? null;
            $id = is_string($candidate) ? $candidate : null;
        }

        if ($id === null || !Str::isUuid($id)) {
            return null;
        }

        $query = File::query()->whereNull('disk_trashed_at');

        if (is_string($session->workspace_id) && $session->workspace_id !== '') {
            $query->where($query->getModel()->qualifyColumn(WorkspaceScope::COLUMN), $session->workspace_id);
        }

        return $query->find($id);
    }

    /**
     * The required slots still without a usable value AFTER the accepted fills merge in — the SOFT signal
     * (not a hard generate-gate). Required = the descriptor is NOT nullable at the top level; "usable" =
     * present + non-null + not an empty string / empty list. Covers required composite slots too (a scope
     * that cannot fill them — a bot's file slot — therefore keeps reporting them).
     *
     * @param  array<string, mixed>  $accepted  the just-accepted fills (merged over the current values)
     * @return array<int, string>
     */
    private function unfilledRequired(GenerationSession $session, array $accepted): array
    {
        $values = array_merge(is_array($session->slot_values) ? $session->slot_values : [], $accepted);
        $out = [];

        foreach ($this->snapshotSlots($session) as $slot) {
            if (!is_array($slot) || !is_string($slot['name'] ?? null)) {
                continue;
            }

            $descriptor = is_array($slot['descriptor'] ?? null) ? $slot['descriptor'] : [];

            if (($descriptor['nullable'] ?? false) === true) {
                continue; // optional — never "unfilled required"
            }

            $value = $values[$slot['name']] ?? null;

            if ($value === null || $value === '' || $value === []) {
                $out[] = $slot['name'];
            }
        }

        return $out;
    }

    /**
     * Every DECLARED slot name → descriptor (in-scope or not) — the "known slot" set for the fill report's
     * unknown-vs-out-of-scope distinction.
     *
     * @return array<string, array<string, mixed>>
     */
    private function descriptorsByName(GenerationSession $session): array
    {
        $out = [];

        foreach ($this->snapshotSlots($session) as $slot) {
            if (is_array($slot) && is_string($slot['name'] ?? null) && is_array($slot['descriptor'] ?? null)) {
                $out[$slot['name']] = $slot['descriptor'];
            }
        }

        return $out;
    }

    /**
     * The OFFERABLE (fillable under $policy) slot name → descriptor map.
     *
     * @return array<string, array<string, mixed>>
     */
    private function offerableDescriptorsByName(GenerationSession $session, SlotScopePolicy $policy): array
    {
        $out = [];

        foreach ($this->introspectSlots($session, $policy) as $slot) {
            $out[$slot['name']] = $slot['descriptor'];
        }

        return $out;
    }

    /**
     * The snapshot's declared slots list (the immutable authority — never the live template).
     *
     * @return array<int, mixed>
     */
    private function snapshotSlots(GenerationSession $session): array
    {
        $snapshot = is_array($session->recipe_snapshot) ? $session->recipe_snapshot : [];

        return is_array($snapshot['slots'] ?? null) ? $snapshot['slots'] : [];
    }
}
