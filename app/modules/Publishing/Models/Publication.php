<?php

namespace App\Modules\Publishing\Models;

use App\Models\AbstractModel;
use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A PUBLICATION — one piece of content, one destination, one moment.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IT IS, STATED HERE SO IT CANNOT DRIFT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A publication is an INTENT TO PUT SOMETHING OUTSIDE THIS APPLICATION, and then the record that it
 * happened. It is deliberately per-destination: "this caption and this video, on YouTube, at 09:00" is
 * one row, and sending the same material to Instagram is another. The alternative — one row fanning out
 * to several platforms — was considered and is wrong, because the states are per-destination in
 * practice. YouTube succeeds and Instagram is rate-limited, and a single `status` then has to describe
 * two different worlds; whatever it says, half of it is false, and the retry button has no idea what it
 * would retry.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * NOTHING HERE TRIGGERS ANYTHING, AND THE ROW EXECUTES NOTHING BY ITSELF
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The same fence `CalendarEvent` carries, in the module most likely to break it. `scheduled_at` is not
 * a cron entry and this table is not a second scheduler: a publication is CLAIMED by a sweep that reads
 * it, and every state change on the way goes through {@see \App\Modules\Publishing\Managers\PublicationManager}.
 * The product already has a scheduler (Workflows) and a cadence engine (App\Support\Recurrence); a
 * repeating publication, when it is wanted, is a Campaign compiling to a workflow (R5) — never a
 * recurrence column here.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE MANAGER OWNS `status`. THIS MODEL DOES NOT — AND IT IS NOT MASS-ASSIGNABLE EITHER.
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Nothing in this module writes `status` except {@see \App\Modules\Publishing\Managers\PublicationManager}.
 * That is not a convention hoped for: it is asserted by {@see \Tests\Feature\PublishingStateMachineTest}
 * over the module's own file bytes, because a service that "just flips it to failed here" is exactly how
 * a state machine stops being one, and it is the kind of line that reads as reasonable in every code
 * review.
 *
 * `status` is DELIBERATELY ABSENT FROM `$fillable`, which is B3's correction of a B1 compromise. B1 kept
 * it fillable "for a default on create and for factories", and neither turned out to need it: the
 * default comes from `$attributes` below (applied by the constructor, before fillable is consulted at
 * all) and Eloquent factories write through `Model::unguarded()`. So the reason was not a reason, and
 * what it left behind was a second door — `fill()`, `create()`, `update()` with a status-shaped key —
 * that the byte scan cannot see, because the offending line would be in a CALLER outside this module.
 *
 * The Manager is unaffected: it writes through `forceFill`, which is mass-assignment-exempt by design
 * and was already chosen for exactly this reason. `PublishingQueueTest` pins the refusal by attempting
 * the mass assignment and asserting the row did not move.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `media` — POINTERS INTO THE DISK, NEVER OWNERSHIP, NEVER DEREFERENCED HERE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * An ORDERED array of `files.id` values. There is no relation to `File` on this model and this module
 * does not name the Disk module at all. The order is the order the platform receives them in; the ids
 * are resolved to bytes exactly once, inside the adapter at publish time, which is also the only moment
 * when a missing file is a failure with a state to go to and a reason to show. See the migration for
 * the full argument and for the cost this accepts (a trashed file leaves a dangling id — loudly, at
 * publish, rather than by silently rewriting what a scheduled post is about).
 *
 * Soft-deleted, and workspace-scoped through TenantAware — so the calendar source needs no workspace
 * predicate of its own, and a trashed publication leaves the grid without leaving the database.
 *
 * @property PublicationStatus $status
 * @property PublishingPlatform $platform
 * @property array<int, string> $media
 */
class Publication extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, SoftDeletes, TenantAware;

    /**
     * How many media items one publication may carry.
     *
     * A PAYLOAD GUARD, NOT A PLATFORM LIMIT. The real caps are per-destination (an Instagram carousel
     * is ten, a YouTube video is one) and they belong to the adapters that know them — claiming to know
     * them here, before either adapter exists, would be inventing a fact. This number only stops a
     * request from carrying an unbounded array; B2 tightens it per platform where the knowledge is.
     */
    public const MEDIA_MAX = 10;

    protected $table = 'publications';

    protected $fillable = [
        'title',
        'body',
        'platform',
        'platform_connection_id',
        // NO `status`. The Manager owns it and writes it with forceFill — see the class docblock.
        'scheduled_at',
        'published_at',
        'media',
        'options',
        'remote_id',
        'remote_draft_id',
        'remote_url',
        'attempts',
        'last_attempt_at',
        'failure_code',
        'failure_context',
        'creator_id',
    ];

    protected $casts = [
        'status' => PublicationStatus::class,
        'platform' => PublishingPlatform::class,
        // INSTANTS, both. Not dates — the hour is the entire content of a posting schedule.
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'media' => 'array',
        'options' => 'array',
        'failure_context' => 'array',
        'attempts' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => PublicationStatus::DRAFT->value,
        'media' => '[]',
        'options' => '{}',
    ];

    /**
     * The evidence trail: every call this publication ever caused, oldest first.
     *
     * NAMED `attemptLog`, NOT `attempts`, AND THE DIFFERENCE IS NOT COSMETIC. `attempts` is already a
     * COLUMN on this row (the counter the Manager bumps on every claim), and Eloquent resolves
     * attributes before relations — so a relation of that name would be silently unreachable through
     * property access. `$publication->attempts` would keep answering the integer even after an eager
     * load, and every caller expecting a collection would get one quietly wrong number instead of an
     * error. The two names now say which of the two things you meant.
     */
    public function attemptLog(): HasMany
    {
        return $this->hasMany(PublicationAttempt::class)->orderBy('created_at');
    }

    /**
     * Everything with a place on a time axis — i.e. everything that is not a draft.
     *
     * Lives on the model rather than in the calendar source because it is a statement about the SUBJECT
     * ("a draft has no moment"), and the counts endpoint and any future queue list want the same
     * answer. The source consuming a scope rather than spelling the status list is what stops the two
     * from drifting when an eighth status is added.
     */
    public function scopeProjectable(Builder $query): void
    {
        $query->whereNotNull('scheduled_at')
            ->whereIn('status', PublicationStatus::projected());
    }

    /**
     * Whether phase 1 has already produced an intermediate artifact on the platform.
     *
     * THE MOST IMPORTANT PREDICATE IN THE MODULE. A publisher that resumed without asking it would
     * create a second container, and a second container publishes just as publicly as the first.
     */
    public function hasRemoteDraft(): bool
    {
        return $this->remote_draft_id !== null && $this->remote_draft_id !== '';
    }

    /** Whether an artifact of this publication demonstrably exists in the world. */
    public function isPublicArtifact(): bool
    {
        return $this->remote_id !== null && $this->remote_id !== '';
    }

    protected static function newFactory()
    {
        return \Database\Factories\PublicationFactory::new();
    }
}
