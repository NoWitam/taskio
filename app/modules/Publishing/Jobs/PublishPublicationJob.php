<?php

namespace App\Modules\Publishing\Jobs;

use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Exceptions\PublicationTransitionRefused;
use App\Modules\Publishing\Managers\PublicationManager;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Services\PublicationPublisher;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ONE PUBLICATION, TAKEN OUT OF THE BUILDING — the only asynchronous path in this module.
 *
 * The row arrives ALREADY CLAIMED. `DispatchDuePublicationsCommand` took the atomic
 * `scheduled → publishing` edge and only then dispatched this job, so what the worker holds is not a
 * candidate but a decision somebody already won a race for. That ordering is why this job may work from
 * an id and a workspace id rather than a serialized model: the claim, not the payload, is the proof.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * `tries = 1`. THE SINGLE MOST IMPORTANT LINE IN THIS FILE.
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * The queue must never retry a publish. Not with a backoff, not on a 5xx, not "just once more" — and
 * the reason is that the queue CANNOT KNOW what it would be retrying. A job that died between phase 1
 * and phase 2, or during a call the platform had already accepted, leaves a world in which the post may
 * exist; a redelivery in that world is not a retry, it is a coin flip whose losing side is a second
 * public artifact that nothing written in this application can delete.
 *
 * A retry is therefore never automatic here. It is a person, from `failed` — a state that means the
 * platform was asked and PROVED nothing exists — and reaching it requires either an adapter asserting
 * "nothing was created" ({@see \App\Modules\Publishing\Exceptions\PlatformRefused}) or a reconciliation
 * concluding the same. Everything else lands in `needs_reconcile`, which no automatic path leaves.
 *
 * `PublishingQueueTest` READS THIS PROPERTY. That looks like testing a constant and is not: the failure
 * it guards against is somebody raising it to 3 while chasing a flaky platform, which would be an
 * entirely reasonable-looking change with an unrecoverable consequence, and nothing else in the system
 * would go red.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE THREE WAYS OUT, AND WHY THE HOOK PARKS RATHER THAN FAILS
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *   handle() returns          the publisher already recorded the outcome — `published`, `failed` or
 *                             `needs_reconcile`. It classifies; this job does not.
 *   handle() throws           an infrastructure fault below the publisher (the database, the container).
 *                             Routed to failed() by `tries = 1`.
 *   the process dies          SIGKILL, OOM, a `queue:restart` mid-call, the SIGALRM below. failed()
 *                             fires for the alarm and the ordinary throw; a hard kill reaches nothing at
 *                             all, which is what the reaper is for.
 *
 * {@see failed()} moves the row to `needs_reconcile`, NEVER to `failed`, and that asymmetry is the whole
 * doctrine in one method. `failed` is a claim about the world — "nothing was created" — and a job whose
 * process has just died is in no position to make it. Being wrong towards `needs_reconcile` costs a
 * person looking at a row that turned out fine; being wrong the other way authorises a retry that posts
 * something twice.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE TIMEOUT IS ORDERED AGAINST THE QUEUE'S OWN redelivery WINDOW
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * `publishing.queue.publish_timeout` must stay BELOW the connection's `retry_after` (90 by default).
 * Above it, the same payload is redelivered while the original is still talking to a platform — and
 * under `tries = 1` a redelivery is failed BEFORE any middleware runs, so {@see middleware()} never sees
 * it and {@see failed()} fires against a LIVE publish.
 *
 * WHAT SURVIVES THAT, PRECISELY. The hook's status read is not what makes it safe — a read decides
 * nothing about a row that moves a microsecond later. What makes it safe is that every status write in
 * this module is a CONDITIONAL UPDATE carrying the status it decided from
 * ({@see \App\Modules\Publishing\Managers\PublicationManager::transition()}), so of the redelivery's
 * park and the live delivery's own conclusion exactly ONE lands and the loser is told. Both sides catch
 * that refusal — the hook below, and {@see handle()} for the tail of the live publish — so the interleave
 * ends with one honest status and no unhandled exception. `PublishingQueueTest` scripts it.
 *
 * THE ORDERING IS STILL LOAD-BEARING, and this is the sentence the docblock used to get wrong. The
 * conditional write guarantees that one conclusion wins; it cannot guarantee the RIGHT one wins. If the
 * redelivery's park lands first, a publication that went out perfectly well sits in `needs_reconcile`
 * waiting for somebody — recoverable, but somebody's afternoon. So keep the alarm inside the redelivery
 * window and let the guarantee be the backstop it is. See `config/publishing.php`.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * TENANCY IS CARRIED EXPLICITLY, NOT INHERITED
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * `QueueTenancy` stamps the DISPATCHING context onto every job, and for this one that context is
 * usually EMPTY: the sweep's shared pass runs deliberately unscoped so a single query covers every
 * shared workspace at once, and the workspace each due row belongs to is read off the row. So the
 * workspace travels as a constructor argument and is re-applied here — the shape
 * {@see \App\Modules\Generator\Jobs\RunGenerationSessionJob} uses, and for the sharper reason: without
 * it an own-database workspace's publish would read and write the CENTRAL tables.
 */
class PublishPublicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** NEVER raise this. See the class docblock; a test reads it. */
    public int $tries = 1;

    /** From config, so it can be ordered against the queue's retry_after without editing code. */
    public int $timeout;

    public function __construct(
        public string $publicationId,
        public string $workspaceId,
    ) {
        $this->timeout = max(10, (int) config('publishing.queue.publish_timeout', 60));
    }

    /**
     * ONE PUBLISH AT A TIME PER PUBLICATION, and a duplicate is DROPPED rather than released.
     *
     * `dontRelease()` is the deliberate half. Every other job in this codebase releases a blocked
     * delivery back onto the queue to be run once the lock frees — which is right when the work is
     * idempotent and wrong here, because by the time the lock frees the publication has been published
     * and the released job would be a second attempt at an artifact that already exists. Under
     * `tries = 1` a released job is also failed rather than re-run, which would park a perfectly healthy
     * row through the failure hook.
     *
     * The lock is a SECOND guard, not the first: the atomic claim in the sweep is what makes two jobs
     * for one publication rare in the first place. This one covers the case the claim cannot — an
     * at-least-once delivery of the SAME job.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->publicationId))
                ->dontRelease()
                ->expireAfter($this->timeout * 2),
        ];
    }

    public function handle(PublicationPublisher $publisher): void
    {
        $this->activateTenant();

        $publication = Publication::query()->find($this->publicationId);

        if ($publication === null) {
            // Trashed or purged between the claim and the worker picking it up. Nothing to publish and
            // nothing to record — the row that would have carried the record is the one that is gone.
            return;
        }

        // NOT OURS ANY MORE. The claim we were dispatched for has been concluded by something else: a
        // reaper parked it, a person reconciled it, an earlier delivery finished it. Publishing now
        // would be the duplicate this whole module is arranged to prevent, and it is worth a line in
        // the log because it should be rare.
        if ($publication->status !== PublicationStatus::PUBLISHING) {
            Log::info('Publish job found its publication already concluded; doing nothing.', [
                'publication' => $publication->id,
                'status' => $publication->status->value,
            ]);

            return;
        }

        try {
            $publisher->publishClaimed($publication);
        } catch (PublicationTransitionRefused $e) {
            // SOMETHING ELSE CONCLUDED THIS PUBLICATION WHILE WE WERE PUBLISHING IT.
            //
            // The guard above is a check at the START of the work; this is the same question answered
            // at the END of it, by the Manager's conditional write, after a platform round trip during
            // which the row can move. The realistic producer is the redelivery interleave the class
            // docblock describes: our own payload is redelivered, `tries = 1` fails it before any
            // middleware runs, and its `failed()` hook parks the row — while this delivery is still
            // talking to the platform.
            //
            // NOT RE-THROWN. There is nothing this job can do about having been beaten to the
            // conclusion, and throwing would route to failed(), which would then try to have an opinion
            // about a row that is already in exactly the state something better-informed put it in.
            // What the platform actually did is now the reconciliation's question, which is where a
            // publication whose outcome is contested belongs.
            Log::warning('A publish finished, but its publication had already been concluded by something else.', [
                'publication' => $publication->id,
                'reason' => $e->reason,
                'status' => $e->from->value,
            ]);
        }
    }

    /**
     * THE PROCESS DIED, OR SOMETHING BELOW THE PUBLISHER THREW. We do not know what the platform saw.
     *
     * Tenancy is restored FIRST: this hook can run after `QueueTenancy` has already popped this job's
     * context off its stack, and a failure handler that writes to the wrong database is a worse outcome
     * than the failure it is handling.
     *
     * The status guard is what makes the hook safe to fire more than once and safe to fire late. Only a
     * row still sitting in `publishing` is ours to conclude; anything else has already been concluded,
     * by the publisher's own classification or by a reaper, and re-parking it would overwrite a decision
     * made with more information than this hook has.
     */
    public function failed(Throwable $e): void
    {
        $this->activateTenant();

        $publication = Publication::query()->find($this->publicationId);

        if ($publication === null || $publication->status !== PublicationStatus::PUBLISHING) {
            return;
        }

        // Class and location, never the message: this hook sits above a layer that holds credentials
        // while it talks to a platform, and an exception message is the shortest path from a held string
        // to a log file.
        Log::error('A publish job died holding a claimed publication; parking it for reconciliation.', [
            'publication' => $publication->id,
            'platform' => $publication->platform->value,
            'had_remote_draft' => $publication->hasRemoteDraft(),
            'exception' => $e::class,
            'at' => $e->getFile() . ':' . $e->getLine(),
        ]);

        try {
            // NEEDS_RECONCILE, NOT FAILED. See the class docblock — `failed` asserts that nothing was
            // created, and a dead worker cannot assert anything about the world.
            app(PublicationManager::class)->markNeedsReconcile($publication, 'publish_worker_failed', [
                'exception' => $e::class,
            ]);
        } catch (PublicationTransitionRefused) {
            // The status check above is a read; this is the same question asked again by the write, and
            // between the two the live delivery can finish. That is not hypothetical here — this hook
            // fires for a REDELIVERY while the original is still publishing, which is the whole reason
            // the timeout is ordered below the queue's `retry_after`.
            //
            // A failure hook that threw would fail the delivery a second time and log a stack trace for
            // a row that is already correct. Whoever won wrote the better-informed answer.
            Log::info('A publish job\'s failure hook found its publication already concluded; leaving it.', [
                'publication' => $publication->id,
            ]);
        }
    }

    /**
     * Re-apply the dispatching workspace, so a tenant-aware model resolves — and, for an own-database
     * workspace, routes to the right connection.
     *
     * A vanished workspace leaves the context untouched and the `find()` above answers null, which is
     * the correct degradation: there is no database to publish from.
     */
    private function activateTenant(): void
    {
        $workspace = Workspace::find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        app(TenantContext::class)->set($workspace);

        if ($workspace->db_mode === WorkspaceDbMode::Own) {
            app(TenantManager::class)->configure($workspace);
        }
    }
}
