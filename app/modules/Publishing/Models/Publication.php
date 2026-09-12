<?php

namespace App\Modules\Publishing\Models;

use App\Models\AbstractModel;
use App\Modules\Approvals\DTOs\ApprovalQueueItem;
use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Approvals\Interfaces\Approvable;
use App\Modules\Approvals\Models\ApprovalProcess;
use App\Modules\Approvals\Traits\HasApprovalPipeline;
use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Events\PublicationConcluded;
use App\Modules\Publishing\Exceptions\PublicationTransitionRefused;
use App\Modules\Publishing\Managers\PublicationManager;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * IT IS `Approvable` (B6), AND THAT IS A DECISION ABOUT WHAT AN APPROVAL IS FOR
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A publication may be gated on a review, through the SAME Approvals pipelines a task uses — the second
 * implementation of {@see Approvable} in the product, and deliberately shaped like the first.
 *
 * It is an APPROVABLE and NOT a trigger. A publication does not start a workflow when it is approved; it
 * is the SUBJECT of one. (A trigger with that semantics existed and was removed in Etap 5.1; nothing
 * here brings it back.) What approval gates is the ARMING — see {@see onApprovalCompleted()} — because
 * arming is the act with consequences outside this application, and it is the only one worth holding.
 *
 * THE POINT OF REVIEWING A PUBLICATION IS THAT YOU APPROVE EXACTLY WHAT GOES OUT. So the snapshot the
 * process carries ({@see getApprovalContext()}) and the card an approver reads
 * ({@see toApprovalQueueItem()}) are both the publication AS IT WILL BE SENT: the caption, the ordered
 * media ids, the destination and the account behind it, and the moment it is meant to go out. That is
 * also why editing is refused while a review is live (`PublicationPolicy`): an edit under review would
 * make the approval a statement about something nobody approved.
 *
 * @property PublicationStatus $status
 * @property PublishingPlatform $platform
 * @property array<int, string> $media
 */
class Publication extends AbstractModel implements Approvable
{
    use HasApprovalPipeline, HasCreator, HasFactory, HasUuids, SoftDeletes, TenantAware;

    /**
     * How many media items one publication may carry.
     *
     * A PAYLOAD GUARD, NOT A PLATFORM LIMIT. The real caps are per-destination (an Instagram carousel
     * is ten, a YouTube video is one) and they belong to the adapters that know them — claiming to know
     * them here, before either adapter exists, would be inventing a fact. This number only stops a
     * request from carrying an unbounded array; B2 tightens it per platform where the knowledge is.
     */
    public const MEDIA_MAX = 10;

    /**
     * Relations a publication needs hydrated before it is rendered, so a list page answers
     * `is_in_approval` / `approval_state` from memory instead of two queries per row.
     *
     * A method rather than a const because the same list is wanted in three places (the list query, the
     * detail load, the approval queue's batch load) and a copy in each is a copy that drifts.
     *
     * @return array<int, string>
     */
    public static function readRelations(): array
    {
        return ['creator', 'pendingApprovalProcess', 'latestApprovalProcess'];
    }

    protected $table = 'publications';

    protected $fillable = [
        'title',
        'body',
        'platform',
        'platform_connection_id',
        // NO `status`. The Manager owns it and writes it with forceFill — see the class docblock.
        // WRITABLE FROM HTTP (a person attaches a review to their own draft) and from the automation
        // seam. The column that gates arming; `PublicationPolicy` is what stops it changing under a
        // live review.
        'approval_pipeline_id',
        // The moment a passed review arms this publication for. NEVER reachable from an HTTP payload:
        // `PublicationDTO` does not carry it and `PublicationService` does not write it. Exactly two
        // things write it — the module's own automation seam sets it, and `onApprovalCompleted()`
        // CLEARS it once it has armed something. See the B6 migration for why it is not `scheduled_at`.
        'arm_on_approval_at',
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
        'arm_on_approval_at' => 'datetime',
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

    /**
     * The account this goes out on, INCLUDING a disconnected one.
     *
     * `withTrashed()` is the whole reason this relation is spelled out rather than inferred: disconnecting
     * an account is a SOFT delete precisely so a publication that already went out keeps resolving to the
     * account it went out on. A relation that hid trashed rows would make a published record forget whose
     * voice it was, which is the one fact the record exists to preserve.
     */
    public function platformConnection(): BelongsTo
    {
        return $this->belongsTo(PlatformConnection::class, 'platform_connection_id')->withTrashed();
    }

    // ══════════════════════════════════════════════════════════════════════════════════════════════
    // THE APPROVABLE HALF (B6)
    // ══════════════════════════════════════════════════════════════════════════════════════════════

    /**
     * The most recent approval process of ANY status — what makes a concluded review readable.
     *
     * `pendingApprovalProcess` (from the trait) answers "is a review live", and that is all a task ever
     * needed. A publication needs one thing more: a REJECTED review leaves the row a draft, looking
     * exactly like a draft nobody ever reviewed, so without this relation "it was turned down" would be
     * invisible on every screen and to the workflow run waiting on it.
     *
     * Eager-loadable (see {@see readRelations()}), which matters: the list endpoint renders this for
     * every row and a lazy read would be a query per publication.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * IT ORDERS BY `id` AS WELL, BECAUSE `created_at` TIES AND THE TIE IS NOT RARE
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * A bare `latest()` is `ORDER BY created_at DESC LIMIT 1`, and two processes of one approval RUN
     * are written within the same request: `ApprovalService::advance()` inserts the next stage's process
     * in the same transaction that decided the previous one. On a multi-stage pipeline those rows
     * routinely share a `created_at` to the microsecond, and a tie leaves the winner to the planner —
     * so "the latest decision" would be whichever row Postgres happened to hand back first, which in
     * practice is the OLDEST.
     *
     * The cost of guessing wrong is specific: a two-stage review whose first stage approved and whose
     * second REJECTED would report `approved` on every screen and to {@see approvalWasRejected()}, and
     * the workflow run waiting on it would carry on as though the publication had been cleared to go
     * out. `id` is a uuid7 (Laravel's `HasUuids`), so it sorts chronologically and breaks the tie in the
     * one direction that is true.
     */
    public function latestApprovalProcess(): MorphOne
    {
        return $this->morphOne(ApprovalProcess::class, 'approvable')
            ->latest('created_at')
            ->latest('id');
    }

    /**
     * Whether a review looked at this publication and said NO — and nothing has re-opened one since.
     *
     * The two halves are both load-bearing. "The latest process is rejected" alone would still answer
     * true while a SECOND review is already running (a person fixed the caption and sent it back), and
     * the run waiting on it would then conclude from a decision that has been superseded.
     */
    public function approvalWasRejected(): bool
    {
        return !$this->isInApproval()
            && $this->latestApprovalProcess?->status === ApprovalProcessStatus::Rejected;
    }

    /**
     * APPROVED — and for an automated publication this is the arming.
     *
     * The gate the whole B6 batch exists for. A publication under review is a draft with a moment parked
     * on it (`arm_on_approval_at`); the last approver saying yes is what moves that moment onto
     * `scheduled_at` through the Manager, and from there the ordinary due-sweep does everything else. No
     * publish happens here, synchronously or otherwise — this method arms a row and returns.
     *
     * A NULL `arm_on_approval_at` MEANS NOTHING HAPPENS, and that is the manual path's guarantee. A person
     * whose draft was sent to review by machinery it does not gate gets an approved draft with a Schedule
     * button; approval does not decide on anybody's behalf that it is time to publish. The column is
     * written only by something that CHOSE a moment: the `publish` workflow step at creation, and — since
     * the B6 fix round — a person's own Schedule press on a review-gated draft, which submits with the
     * chosen moment ({@see \App\Modules\Publishing\Services\PublicationService::schedule()}). In both
     * cases the intent is the author's; the approval merely executes it. See the B6 migration for why the
     * flag and the moment are one column.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * THE ARMING HAPPENS **AFTER** THE DECISION IS COMMITTED, AND THAT ORDER IS THE WHOLE POINT
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * `ApprovalService::decide()` opens `DB::transaction()` — on the DEFAULT connection, because that is
     * what a bare `DB::` resolves to — and calls this method from inside it. A publication, however, is
     * `TenantAware`: for an own-database workspace its writes go to the TENANT connection, which that
     * transaction does not cover. Arming inline therefore produced a pairing with no name for it: the
     * arming committed into the tenant database while a later throw rolled the DECISION back centrally,
     * leaving a publication armed for a review that, as far as the record goes, never concluded. The
     * sweep would then publish it — a public artifact standing behind an approval that was undone.
     *
     * So the effect is deferred with {@see \Illuminate\Support\Facades\DB::afterCommit()}: it runs when
     * the decision's transaction COMMITS, and not at all if it rolls back. With no transaction open
     * (the AI evaluation job, a console call) the callback executes immediately, so the single-statement
     * path is unchanged.
     *
     * It also fixes a second, quieter defect. `config/queue.php` sets `after_commit => false`, so
     * `PublicationConcluded` fired inside the transaction could reach a worker that read the approval
     * process while it was still `Pending` — the waiting run's fast path then failed to find its answer
     * and fell back to the sweep, losing minutes, at random, depending on worker timing.
     *
     * A REFUSED ARMING IS SWALLOWED, deliberately — and now it is swallowed AFTER a commit, which is a
     * different statement from the one this paragraph used to make. It can no longer roll anything back:
     * the decision is already durable. What a refusal means is that the row moved between the review and
     * this write (somebody deleted it, disarmed it, a second decision raced this one), and the honest
     * outcome is an APPROVED PUBLICATION THAT IS NOT ARMED: it stays a draft with a Schedule button, the
     * warning below is the record of why, and a workflow run parked on it waits out
     * `workflows.wait_timeout` rather than being told something false. Nothing goes out, which is the
     * direction to fail in.
     *
     * THE INTENT IS CONSUMED ON USE. `arm_on_approval_at` is cleared once it has armed something, because
     * it describes ONE moment somebody chose for ONE decision. Left behind, it would re-arm a publication
     * that was disarmed and sent back for a second review — for the original, by then long-past instant —
     * and "publish immediately" is not what either approver said.
     */
    public function onApprovalCompleted(ApprovalProcess $process): void
    {
        // ONE STEP OF THE CONNECTION REASONING IS EASY TO MISS, so it is stated: `DB::afterCommit()`
        // binds to the DEEPEST OPEN TRANSACTION ON ANY CONNECTION — today that is decide()'s, on the
        // default connection, which is exactly right. A future caller that reaches this hook from
        // inside a TENANT-connection transaction (the shape `PublicationAutomationService::transaction()`
        // uses) would silently bind the arming to that one instead. Nothing does that today; if
        // something must, it has to think about which commit the arming should survive.
        $armAt = $this->arm_on_approval_at;

        if ($armAt === null) {
            return;
        }

        DB::afterCommit(function () use ($armAt): void {
            try {
                app(PublicationManager::class)->arm($this, $armAt);
            } catch (PublicationTransitionRefused $e) {
                Log::warning('An approved publication could not be armed; it stays a draft.', [
                    'publication' => $this->id,
                    'reason' => $e->reason,
                ]);

                return;
            }

            // Consumed. `forceFill` for the same reason the Manager uses it — this is the module
            // writing its own bookkeeping column, not a mass assignment from a payload.
            $this->forceFill(['arm_on_approval_at' => null])->save();
        });
    }

    /**
     * REJECTED — the publication stays exactly where it is, a draft.
     *
     * Nothing is written, and the absence of a write is the decision: there is no `rejected` publication
     * status and there must not be one. A rejected publication is a draft somebody may fix and send back,
     * and inventing an eighth status would have meant inventing its edges too — including an edge out of
     * it into `scheduled`, which is the one thing a rejection is supposed to prevent.
     *
     * What DOES happen is the announcement: anything waiting on this publication's outcome (a suspended
     * workflow run) has just had its answer, and a rejection is not a transition, so the Manager's own
     * announcement cannot carry it. This is the second and only other place {@see PublicationConcluded}
     * is raised.
     *
     * DEFERRED PAST THE COMMIT, for the reason spelled out on {@see onApprovalCompleted()}: the listener
     * this wakes dispatches a job, `config/queue.php` sets `after_commit => false`, and a worker that
     * picked it up before `decide()` committed would read the process as still `Pending` and conclude
     * nothing. The run then sat parked until the sweep — not broken, but slow, intermittently, and for a
     * reason nothing on any screen could explain. A rolled-back decision now announces nothing at all,
     * which is the other half of the same guarantee.
     */
    public function onApprovalRejected(ApprovalProcess $process): void
    {
        DB::afterCommit(fn () => PublicationConcluded::fromRejectedReview($this));
    }

    /**
     * @return array<int, string>
     */
    public function approvalQueueRelations(): array
    {
        return ['platformConnection'];
    }

    /**
     * THE CARD AN APPROVER READS — the publication as it will be sent, and nothing else.
     *
     * Every field here is something whose value would change what the world sees. The caption is the
     * description because the caption IS the post; the destination and the ACCOUNT are named (a uuid
     * tells an approver nothing about whose feed this appears on); the moment is stated because
     * approving a post for Friday 09:00 is not the same act as approving one for right now; and the
     * media are COUNTED rather than listed, because their ids are machine identifiers with no meaning
     * to a reader. The ids themselves are in the persisted snapshot — see {@see getApprovalContext()}.
     *
     * `form` is null and `comments_url` is null, and both are true rather than unfinished: a publication
     * has no form to fill in, and this module does not compose `HasComments`, so there is no thread to
     * link to. Offering a dead link on the one screen where a person is deciding whether something goes
     * public would be worse than offering none.
     */
    public function toApprovalQueueItem(): ApprovalQueueItem
    {
        $this->loadMissing($this->approvalQueueRelations());

        $fields = [
            [
                'label' => __('publishing.approval.fields.platform'),
                'value' => $this->platform->label(),
                'icon' => 'send',
            ],
            [
                'label' => __('publishing.approval.fields.account'),
                // A dry-run publication genuinely has no account, and so does one whose connection was
                // deleted outright rather than disconnected. Both read as "no account" rather than blank.
                'value' => $this->platformConnection?->account_name ?? __('publishing.approval.no_account'),
                'icon' => 'user',
            ],
            [
                'label' => __('publishing.approval.fields.planned_for'),
                'value' => $this->intendedPublishAt()?->toDateTimeString() ?? __('publishing.approval.no_moment'),
                'icon' => 'calendar',
            ],
            [
                'label' => __('publishing.approval.fields.media'),
                'value' => (string) count($this->media ?? []),
                'icon' => 'image',
            ],
        ];

        return new ApprovalQueueItem(
            type_label: __('approvals.entity_types.publication'),
            type_icon: 'send',
            name: $this->title,
            description: $this->body,
            extra_fields: $fields,
            form: null,
            comments_url: null,
        );
    }

    /**
     * THE SNAPSHOT THE PROCESS CARRIES — what was approved, recorded at the moment it was submitted.
     *
     * Per ADR-0009 §1 this payload is a snapshot of the ENTITY, never a map of somebody's answers. For a
     * task that snapshot exists so a rejection can put the original assignee back; here nothing is
     * restored from it, and it is written for a different and sharper reason: a publication is the one
     * approvable whose approval authorizes something IRREVERSIBLE, so "what exactly did the approver say
     * yes to" has to be answerable afterwards from a row rather than from a screenshot.
     *
     * The ordered media ids ARE included here although they are only counted on the card. This is the
     * copy that has to be exact: swapping one video for another changes everything about the post and
     * nothing about its caption.
     *
     * @return array<string, mixed>
     */
    public function getApprovalContext(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'platform' => $this->platform->value,
            'platform_connection_id' => $this->platform_connection_id,
            // ORDERED, and re-indexed for the same reason the write path re-indexes it: a carousel is
            // its sequence, and a json object with numeric keys is not a sequence.
            'media' => array_values($this->media ?? []),
            'intended_publish_at' => $this->intendedPublishAt()?->toISOString(),
        ];
    }

    /**
     * When this publication means to go out, whichever half of its life it is in.
     *
     * `scheduled_at` once it is armed; the parked intent while a review holds it. One question, one
     * answer, so a card and a snapshot cannot describe two different moments.
     */
    public function intendedPublishAt(): ?CarbonInterface
    {
        return $this->scheduled_at ?? $this->arm_on_approval_at;
    }

    protected static function newFactory()
    {
        return \Database\Factories\PublicationFactory::new();
    }
}
