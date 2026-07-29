<?php

namespace App\Modules\Generator\Models;

use App\Models\AbstractModel;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Support\CreativeDirection;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A generation SESSION: ONE execution of a Template RECIPE into concrete content (R2 sub-stage 2b).
 *
 * It SNAPSHOTS the template at creation — `recipe_snapshot` = the full `{content_type, slots, content}`
 * — so the run is reproducible and survives a later template edit/delete; `template_id` is PROVENANCE
 * only (nullable, no FK). The user fills `slot_values`, then GENERATES: each TEXT part (text_body/script)
 * is rendered through the SHARED engine with the REAL `@[ai-text]` generator live (budgeted + metered),
 * image/scene parts produce a DEFERRED marker in 2b (executed in 2c). The per-part outcome lands in
 * `results` ({@see \App\Modules\Generator\Services\GenerationSessionExecutor}); `status` drives the
 * async claim/run state machine ({@see GenerationSessionStatus}).
 *
 * WorkspaceScope/TenantAware posture copied VERBATIM from {@see Template} (workspace-scoped in shared
 * mode, the tenant connection in own mode). `HasCreator` attributes the run polymorphically (a user now;
 * a workflow_run/bot later — the `generation_session` morph alias is registered in the module provider).
 * SoftDeletes gives a trash the 2d reaper will purge. `history` + `archived_at` are RESERVED for 2d.
 */
class GenerationSession extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, SoftDeletes, TenantAware;

    protected $table = 'generation_sessions';

    protected $fillable = [
        'template_id',
        'name',
        'content_type',
        'recipe_snapshot',
        'slot_values',
        'results',
        'status',
        'history',
        'last_op_status',
        'last_op_error',
        'archived_at',
        'bot_author_id',
        'bot_delegation',
        'creative_direction',
        'creator_id',
    ];

    protected $casts = [
        'recipe_snapshot' => 'array',
        'slot_values' => 'array',
        'results' => 'array',
        'history' => 'array',
        'bot_delegation' => 'array',
        'creative_direction' => 'array',
        'status' => GenerationSessionStatus::class,
        'archived_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function newFactory()
    {
        return \Database\Factories\GenerationSessionFactory::new();
    }

    // ---- Bot-author delegation overlay (R2 sub-stage 3) -------------------------------
    //
    // A REVERSIBLE, SNAPSHOTTED handoff: a bot becomes the content AUTHOR (its voice colors every
    // rendered text part + the shot_list voiceover) while the human `creator` stays the OWNER (full
    // refine/undo/delete). The overlay is read SNAPSHOT-not-live — the executor reads these columns,
    // never the bots table — so editing/deleting the bot never changes a delegated session's voice.

    /** Whether a bot currently authors this session (the delegation overlay is present). */
    public function isDelegated(): bool
    {
        return $this->bot_author_id !== null && is_array($this->bot_delegation);
    }

    /**
     * The polymorphic METER actor a queued run's spend attributes to (R2 sub-stage 4): the BOT AUTHOR when
     * delegated (the bot drove the content), else the human OWNER (the session's HasCreator). A queued job
     * has no auth()/run, so the executor sets this EXPLICITLY on the MeterContext around the run; the meter's
     * default resolution can't see the originator. `bot` is the registered morph alias (Bot module).
     *
     * @return array{0: ?string, 1: ?string} [actorType, actorId]
     */
    public function meterActor(): array
    {
        if ($this->isDelegated()) {
            return ['bot', $this->bot_author_id];
        }

        return [$this->creator_type, $this->creator_id];
    }

    /**
     * The SNAPSHOTTED opaque voice directive the delegated content renders in, or null when undelegated
     * (the human's own voice). Read straight off the overlay — never re-derived from the live bot.
     */
    public function botVoice(): ?string
    {
        $voice = is_array($this->bot_delegation) ? ($this->bot_delegation['voice'] ?? null) : null;

        return is_string($voice) && $voice !== '' ? $voice : null;
    }

    /**
     * The denormalized bot-author snapshot `{id, name, icon}` captured at delegation (for the wire/FE
     * "authored by bot" badge), or null when undelegated. Snapshotted so a later bot edit/delete cannot
     * change what a delegated session shows.
     *
     * @return array<string, mixed>|null
     */
    public function botAuthor(): ?array
    {
        $author = is_array($this->bot_delegation) ? ($this->bot_delegation['author'] ?? null) : null;

        return is_array($author) ? $author : null;
    }

    // ---- Creative direction (the direction layer) -------------------------------------

    /**
     * The run's stored CREATIVE DIRECTION — the shared creative frame derived ONCE per FULL run
     * ({@see \App\Modules\Generator\Services\CreativeDirectionService}) and reused by every later per-part
     * op, so a refine stays inside the frame the run established. Null when the run derived none (the layer
     * is off, the derivation failed, or the session has not run yet); nulled again on the next FULL claim.
     *
     * Always re-normalized on the way OUT ({@see CreativeDirection::fromArray} is the security boundary), so
     * a hand-edited or legacy column value can never reach a prompt or the API unvetted.
     */
    public function creativeDirection(): ?CreativeDirection
    {
        return CreativeDirection::fromArray($this->creative_direction);
    }

    /** Sessions authored by a given bot (provenance filter on the indexed `bot_author_id`). */
    public function scopeDelegatedTo(Builder $query, string $botId): void
    {
        $query->where('bot_author_id', $botId);
    }

    /**
     * The required slots still without a usable value — the SOFT delegation signal the session wire surfaces
     * (NOT a hard generate-gate). Required = the snapshot descriptor is NOT top-level `nullable`; "usable" =
     * present + non-null + not an empty string / empty list. A PURE read over the ALREADY-HYDRATED
     * `recipe_snapshot` + `slot_values` — no service resolve, no query — so the sessions LIST resource can
     * compute it per row cheaply. Required FILE / composite slots are reported too (they can't be bot-filled, so
     * a required one stays reported). Single source of truth mirrored by
     * {@see \App\Modules\Generator\Services\SessionDelegationService::unfilledRequiredSlots}.
     *
     * @return array<int, string>
     */
    public function unfilledRequiredSlots(): array
    {
        $snapshot = is_array($this->recipe_snapshot) ? $this->recipe_snapshot : [];
        $slots = is_array($snapshot['slots'] ?? null) ? $snapshot['slots'] : [];
        $values = is_array($this->slot_values) ? $this->slot_values : [];

        $out = [];

        foreach ($slots as $slot) {
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
     * Whether ANY part of this run's produced content failed, even though the run itself finished `ready`
     * (per-part fail-soft: the executor marks a part `failed` and keeps going, and a per-item image may fail
     * inside an otherwise-ok scene_plan/storyboard). The SERVER-SIDE signal a non-interactive consumer needs
     * to tell "complete" from "partial" — the human FE shows the failures as per-part UI prose instead.
     *
     * A PURE read over the already-hydrated `results`, mirroring {@see unfilledRequiredSlots}: no query, no
     * service. Never throws — a malformed/legacy result simply does not count as a failure.
     */
    public function hasFailedParts(): bool
    {
        foreach (is_array($this->results) ? $this->results : [] as $result) {
            if (!is_array($result)) {
                continue;
            }

            if (($result['status'] ?? null) === 'failed') {
                return true;
            }

            // A per-item image failure inside an otherwise-ok list part (scene_plan → scenes,
            // storyboard → shots). Both lists are walked without keying on the part kind, so a future
            // list-bearing kind is covered by construction.
            foreach (['scenes', 'shots'] as $listKey) {
                foreach (is_array($result[$listKey] ?? null) ? $result[$listKey] : [] as $item) {
                    if (is_array($item) && ($item['image_status'] ?? null) === 'failed') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    // ---- Lifecycle reaper scopes (R2 sub-stage 2d) -----------------------------------
    //
    // Windows for the scheduled `generator:reap-sessions` sweep. Each runs on the CURRENTLY ACTIVE
    // connection (WorkspaceScope self-disables when no workspace is active, so the shared-DB pass sees
    // every shared row unscoped, exactly like the Disk-AI reaper). The command supplies the cutoff.

    /**
     * Sessions stranded in `generating` past the stale cutoff — a worker SIGKILL/OOM never reached the
     * job's failed() hook. The cutoff EXCEEDS the job's whole retry/lock budget (config note), so a
     * slow-but-alive run is never matched. Archived sessions are EXEMPT: archive is a blanket "freeze"
     * that opts a session out of EVERY reaper action (stale-fail, trash, purge), so an archived row is
     * never auto-failed.
     */
    public function scopeStaleGenerating(Builder $query, DateTimeInterface $cutoff): void
    {
        $query->where('status', GenerationSessionStatus::Generating->value)
            ->whereNull('archived_at')
            ->where('updated_at', '<', $cutoff);
    }

    /**
     * LIVE (not-yet-trashed) sessions eligible for the trash step: NON-archived and idle (updated_at)
     * past the trash cutoff. Archived sessions are EXEMPT from all cleanup, so `archived_at` gates them
     * out. The SoftDeletes global scope already excludes rows in the trash, so this only ever matches
     * live rows.
     */
    public function scopeTrashable(Builder $query, DateTimeInterface $cutoff): void
    {
        $query->whereNull('archived_at')
            ->where('updated_at', '<', $cutoff);
    }

    /**
     * TRASHED sessions eligible for the purge step: soft-deleted, NON-archived, and past the purge
     * cutoff (measured from when they were trashed — `deleted_at`). onlyTrashed() lifts the SoftDeletes
     * scope; `archived_at` keeps an archived-then-trashed session EXEMPT.
     */
    public function scopePurgable(Builder $query, DateTimeInterface $cutoff): void
    {
        $query->onlyTrashed()
            ->whereNull('archived_at')
            ->where('deleted_at', '<', $cutoff);
    }
}
