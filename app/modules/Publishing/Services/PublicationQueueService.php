<?php

namespace App\Modules\Publishing\Services;

use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Exceptions\PublicationTransitionRefused;
use App\Modules\Publishing\Jobs\PublishPublicationJob;
use App\Modules\Publishing\Managers\PublicationManager;
use App\Modules\Publishing\Models\Publication;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * THE QUEUE'S THREE PASSES, each over the CURRENTLY ACTIVE database connection.
 *
 * This class knows nothing about tenancy beyond that sentence. The commands
 * ({@see \App\Modules\Publishing\Console\DispatchDuePublicationsCommand},
 * {@see \App\Modules\Publishing\Console\ReconcilePublicationsCommand}) walk the shared database and then
 * every own-database workspace; each pass below simply runs against whatever is configured when it is
 * called. The same split `TokenRefresher` and `BotTaskRunManager` already use, and it is what lets all
 * three passes be tested without provisioning a database.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * IT DECIDES NO STATES. NOT ONE.
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * Every move below goes through {@see PublicationManager} — `claimDue()`, `markNeedsReconcile()`,
 * `markFailed()` — and every reconciliation conclusion through {@see PublicationPublisher::reconcile()},
 * which asks the platform first. There is no status assignment in this file and
 * `PublishingStateMachineTest` scans its bytes to keep it that way.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * NO TRANSACTION IS OPENED HERE, AND THAT IS A DECISION RATHER THAN AN OVERSIGHT
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * B2's review found a transaction opened on `database.default` while the rows it was meant to protect
 * were being written to a tenant connection — atomicity that existed for nobody, most of all for the
 * customers whose data is most isolated. The remedy there was to open it on the ROW's own connection
 * ({@see \App\Modules\Publishing\Managers\PlatformConnectionManager::transaction()}).
 *
 * The remedy HERE is that there is nothing to wrap. Every write these three passes make is a single row:
 * one conditional claim, one park, one conclusion. Adding a transaction would not make anything more
 * atomic; it would only reintroduce the connection question and tell the next reader that several things
 * happen together when they do not.
 *
 * And in the one place a transaction would look tempting — claim, then dispatch — it would be actively
 * wrong twice over: it would hold a row lock across a queue write, and under a driver that runs the job
 * inline it would nest the whole publish inside a transaction the module explicitly forbids wrapping
 * (see {@see PublicationPublisher}: a rolled-back phase-1 handle leaves a container on a platform with
 * no record of it). The claim-then-dispatch gap is instead closed by ADMITTING it — see
 * {@see dispatchDue()}.
 */
class PublicationQueueService
{
    /** Cache key prefix for the automatic reconciliation cooldown. See {@see reconcilePending()}. */
    private const PROBE_COOLDOWN_PREFIX = 'publishing:reconcile-probe:';

    /** The queue could not be written to. Nothing was sent, so the row is honestly `failed`. */
    public const FAILURE_DISPATCH = 'dispatch_failed';

    /** Nobody came back for a claimed row. We do not know what the platform saw. */
    public const FAILURE_REAPED = 'reaper_stale';

    public function __construct(
        private PublicationManager $manager,
        private PublicationPublisher $publisher,
        private TenantContext $context,
    ) {}

    /**
     * PASS 1 — claim every publication whose moment has come, and hand each to a worker.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * THE CLAIM COMES FIRST, AND THAT ORDER IS THE CONCURRENCY GUARANTEE
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * `claimDue()` is one conditional UPDATE, so of two overlapping sweeps exactly one moves the row and
     * the other is handed null and moves on. Dispatching first and claiming in the worker would put the
     * decision after the fan-out, which is where two jobs for one publication comes from.
     *
     * A null claim is therefore ORDINARY and is counted, not logged: "somebody else has it" is the
     * mechanism working.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * THE GAP BETWEEN THE CLAIM AND THE DISPATCH, ADMITTED AND ANSWERED
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * The row is `publishing` the instant the claim lands, and the job is dispatched a statement later.
     * If that write to the queue fails — the queue's own database is down, Redis is unreachable — the
     * publication is claimed with nobody coming for it.
     *
     * It is marked `failed`, not `needs_reconcile`, and the distinction is exactly the one the module
     * cares about: NO PLATFORM WAS TOUCHED. `failed` means "we know nothing was created", which is a
     * true statement here and the one that keeps the ordinary retry safe. Parking it in
     * `needs_reconcile` would be needlessly pessimistic — it would demand a person reconcile a
     * publication that never left the building.
     *
     * @return array{claimed: int, lost: int, undispatchable: int}
     */
    public function dispatchDue(): array
    {
        $counts = ['claimed' => 0, 'lost' => 0, 'undispatchable' => 0];

        $due = Publication::query()
            ->where('status', PublicationStatus::SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            // Oldest promise first, and it uses the (workspace_id, status, scheduled_at) index the
            // publications table already carries.
            ->orderBy('scheduled_at')
            ->limit($this->batch('dispatch_batch', 200))
            ->get();

        foreach ($due as $publication) {
            // One row that cannot be processed must not hold up every publication behind it — each of
            // those has a moment somebody chose, and they are all later than this one. The same posture
            // `RunScheduledWorkflowsCommand` takes per workflow.
            try {
                $this->dispatchOne($publication, $counts);
            } catch (Throwable $e) {
                Log::error('The publishing due sweep failed for one publication; continuing with the rest.', [
                    'publication' => $publication->id,
                    'exception' => $e::class,
                ]);
                $counts['undispatchable']++;
            }
        }

        return $counts;
    }

    /**
     * Claim one due publication and hand it over. Extracted so {@see dispatchDue()} reads as the loop
     * it is and the per-row guard has something to guard.
     *
     * @param  array<string, int>  $counts
     */
    private function dispatchOne(Publication $publication, array &$counts): void
    {
        $workspaceId = $this->workspaceIdFor($publication);

        if ($workspaceId === null) {
            // Unattributable: a shared-database row with no workspace stamped on it. A publish needs to
            // know whose accounts it may use, so this is refused loudly rather than published against
            // whatever connection happens to be configured.
            Log::error('A due publication names no workspace and cannot be dispatched.', [
                'publication' => $publication->id,
            ]);
            $counts['undispatchable']++;

            return;
        }

        $claimed = $this->manager->claimDue($publication);

        if ($claimed === null) {
            $counts['lost']++;

            return;
        }

        try {
            PublishPublicationJob::dispatch($claimed->id, $workspaceId);
            $counts['claimed']++;
        } catch (Throwable $e) {
            Log::error('A claimed publication could not be handed to the queue.', [
                'publication' => $claimed->id,
                'exception' => $e::class,
            ]);

            try {
                // Nothing was sent anywhere. See dispatchDue()'s docblock for why this is `failed`.
                $this->manager->markFailed($claimed, self::FAILURE_DISPATCH, ['exception' => $e::class]);
                $counts['undispatchable']++;
            } catch (PublicationTransitionRefused) {
                // ═══════════════════════════════════════════════════════════════════════════════════
                // THE THROW CAME AFTER THE JOB HAD ALREADY RUN, AND THE ROW IS NOT OURS TO CONCLUDE.
                // ═══════════════════════════════════════════════════════════════════════════════════
                // "The dispatch failed" and "nothing was sent" are NOT the same statement, and under a
                // queue that runs the job inline they routinely disagree: `dispatch()` returns only
                // after the publish has happened, so a throw from it can be a throw about a post that
                // is already out. The Manager's conditional write is what notices — `$claimed` was read
                // before the job ran, and the row has moved since.
                //
                // Writing `failed` here would have been the module's worst outcome twice over: it
                // asserts nothing was created (while `remote_id` sits on the row naming a live post),
                // and the product then offers to schedule it again.
                //
                // COUNTED AS `claimed`, NOT `undispatchable`. A conclusion reached by something else
                // means a worker got the row, which is precisely what `claimed` records. Calling it
                // undispatchable would make the sweep report a failure for its most successful path.
                Log::info('A publication was concluded by its worker before the failed dispatch could be recorded.', [
                    'publication' => $claimed->id,
                ]);
                $counts['claimed']++;
            }
        }
    }

    /**
     * PASS 2 — THE REAPER. Rows stranded in `publishing` by a worker that never came back.
     *
     * A SIGKILL, an OOM, a `queue:restart` mid-call, a host that went away: none of them reach the job's
     * `failed()` hook, so the row keeps its claim forever. And a stranded claim is not merely untidy
     * here — `publishing` is neither editable nor deletable and has no automatic exit, so the
     * publication is beyond every affordance the product offers until something moves it.
     *
     * IT MOVES THEM TO `needs_reconcile`, NEVER RETRIES THEM, and does not pretend to know more than it
     * does. All this pass knows is that a claim is old. Whether the platform received anything is
     * exactly the question `needs_reconcile` exists to hold open, and the reaper is the last component
     * that should be guessing at it — it is running minutes or hours after the fact with no memory of
     * the call.
     *
     * The cutoff is `stale_after`, deliberately many times the job's own timeout: the subject is the
     * death a timeout could not catch, and reaping a publish that is merely slow would park a row whose
     * platform call is still in flight. A NULL `last_attempt_at` is treated as stale for the same reason
     * `BotTaskRunManager` does — every claim stamps it atomically, so a null can only be a row from
     * before this mechanism existed.
     *
     * IT IS BOUNDED, like the other two passes. `reap_batch` caps how many rows one pass parks, ordered
     * OLDEST CLAIM FIRST so the bound defers the freshest strandings rather than an arbitrary slice —
     * and a parked row leaves the selection, so a backlog drains over consecutive passes. Unbounded, the
     * one situation that produces many stranded rows at once (a worker host dying with a full queue) is
     * exactly the situation where this pass would try to park all of them in one transaction-less loop.
     *
     * @return int how many were parked
     */
    public function reapStalePublishing(): int
    {
        $cutoff = now()->subSeconds($this->staleAfter());

        $stranded = Publication::query()
            ->where('status', PublicationStatus::PUBLISHING)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_attempt_at')
                    ->orWhere('last_attempt_at', '<', $cutoff);
            })
            // Oldest claim first. Nulls are the oldest thing there is — a row from before the claim
            // stamped a time — and Postgres sorts them last by default, so they are asked for first.
            ->orderByRaw('last_attempt_at asc nulls first')
            ->limit($this->batch('reap_batch', 200))
            ->get();

        $reaped = 0;

        foreach ($stranded as $publication) {
            try {
                $this->manager->markNeedsReconcile($publication, self::FAILURE_REAPED, [
                    'stale_after_seconds' => $this->staleAfter(),
                ]);

                Log::warning('A publication was stranded in publishing and has been parked for reconciliation.', [
                    'publication' => $publication->id,
                    'platform' => $publication->platform->value,
                    'last_attempt_at' => $publication->last_attempt_at?->toISOString(),
                ]);

                $reaped++;
            } catch (PublicationTransitionRefused) {
                // THE WORKER CAME BACK between this pass's SELECT and its write, and concluded the row
                // itself — with everything this pass does not have: the platform's answer. Its
                // conclusion stands, and overwriting it with `needs_reconcile` would take a publication
                // that IS resolved (possibly `published`) and hand it to a person to reconcile.
                //
                // Not an error, and deliberately not counted: nothing was reaped, because there was
                // nothing left stranded.
                Log::info('A stranded publication was concluded by its own worker before the reaper reached it.', [
                    'publication' => $publication->id,
                ]);
            } catch (Throwable $e) {
                // One row that refuses to move must not stop the pass for every row behind it.
                Log::error('The publishing reaper could not park a publication; continuing.', [
                    'publication' => $publication->id,
                    'exception' => $e::class,
                ]);
            }
        }

        return $reaped;
    }

    /**
     * PASS 3 — ASK THE PLATFORM about everything sitting in `needs_reconcile`.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * WHY AN AUTOMATIC PROBE IS SAFE WHEN AN AUTOMATIC RETRY IS NOT
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * They are opposite acts. A retry WRITES to a platform and may produce a second artifact; a probe
     * READS, and the worst it can do is fail to answer. So the doctrine that forbids the first says
     * nothing against the second — and the two conclusions a probe can reach are conclusions ABOUT
     * EVIDENCE rather than guesses: a {@see \App\Modules\Publishing\DTOs\RemoteRef} means the artifact
     * was found, and a null means the platform was asked and proved absence.
     *
     * The third outcome is the one that matters most and is invisible from here: an adapter that cannot
     * establish either throws, {@see PublicationPublisher::reconcile()} logs it and returns the row
     * UNCHANGED, and it stays in `needs_reconcile` for a person. There is no attempt limit, no
     * escalation and no eventual give-up — a row may sit here forever, which is the honest state for
     * something nobody can answer.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * THE COOLDOWN, AND WHY IT LIVES IN THE CACHE
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * "Forever" plus "every five minutes" is 288 questions a day, per stuck row, against somebody's rate
     * limit — the kind of traffic a platform holds against the whole application, affecting the
     * publications that still work. So a publication is probed at most once per `reconcile_cooldown`.
     *
     * The marker is a cache key rather than a column, and the trade is deliberate: a lost cache costs
     * ONE extra read-only question, while a column would mean this pass writing to a row whose every
     * other write belongs to the Manager. It is also the reason the manual endpoint can ignore it —
     * a person asking is not a sweep, and their question should never be answered with silence because
     * a machine asked recently. "Not a sweep" is not "unlimited", though: the route carries its own
     * throttle (see the module's routes/api.php), because a held-down button spends the same
     * per-application platform limit the cooldown here exists to protect.
     *
     * @return array{probed: int, published: int, failed: int, unresolved: int}
     */
    public function reconcilePending(): array
    {
        $counts = ['probed' => 0, 'published' => 0, 'failed' => 0, 'unresolved' => 0];

        $pending = Publication::query()
            ->where('status', PublicationStatus::NEEDS_RECONCILE)
            // Longest-waiting first, so a backlog larger than the batch still drains rather than
            // re-asking about the same head every pass.
            ->orderBy('updated_at')
            ->limit($this->batch('reconcile_batch', 100))
            ->get();

        foreach ($pending as $publication) {
            if (!$this->mayProbe($publication)) {
                continue;
            }

            $counts['probed']++;

            try {
                $resolved = $this->publisher->reconcile($publication);
            } catch (PublicationTransitionRefused $e) {
                // Somebody concluded this row between the SELECT above and the probe's own write — a
                // person on the manual endpoint, or the probe's answer racing a parallel pass. The row
                // now holds a status decided with at least as much information as this pass had, so
                // this is the machinery working, not an error worth an ERROR line.
                Log::info('A reconciliation lost the race for its row; the winner\'s status stands.', [
                    'publication' => $publication->id,
                    'platform' => $publication->platform->value,
                ]);
                $counts['unresolved']++;

                continue;
            } catch (Throwable $e) {
                // reconcile() swallows an adapter that cannot answer, and the CAS refusal is caught
                // above; what reaches here is the registry refusing to resolve an adapter at all — a
                // configuration error for this destination, which must not stop the pass for the other
                // destinations.
                Log::error('A publication could not be reconciled; continuing with the rest.', [
                    'publication' => $publication->id,
                    'platform' => $publication->platform->value,
                    'exception' => $e::class,
                ]);
                $counts['unresolved']++;

                continue;
            }

            match ($resolved->status) {
                PublicationStatus::PUBLISHED => $counts['published']++,
                PublicationStatus::FAILED => $counts['failed']++,
                default => $counts['unresolved']++,
            };
        }

        return $counts;
    }

    /**
     * Whether this publication is due another AUTOMATIC probe, marking it as asked when it is.
     *
     * `add()` rather than a read-then-write, so two overlapping passes cannot both decide to ask.
     */
    private function mayProbe(Publication $publication): bool
    {
        $cooldown = max(0, (int) config('publishing.queue.reconcile_cooldown', 3600));

        if ($cooldown === 0) {
            return true;
        }

        return Cache::add(self::PROBE_COOLDOWN_PREFIX . $publication->id, true, $cooldown);
    }

    /**
     * Which workspace a due publication belongs to, so the job can re-establish its tenancy.
     *
     * TWO SOURCES, because the sweep has two shapes. During an own-database pass a workspace is ACTIVE
     * and the row itself carries no `workspace_id` column at all — one tenant database is one workspace.
     * During the shared pass no workspace is active on purpose (that is what makes a single query cover
     * every shared workspace), and the answer is on the row.
     */
    private function workspaceIdFor(Publication $publication): ?string
    {
        $active = $this->context->id();

        if ($active !== null) {
            return $active;
        }

        $stamped = $publication->getAttribute('workspace_id');

        return is_string($stamped) && $stamped !== '' ? $stamped : null;
    }

    private function batch(string $key, int $default): int
    {
        return max(1, (int) config('publishing.queue.' . $key, $default));
    }

    /** Floored well above any plausible job timeout — a reaper that fires early parks live work. */
    private function staleAfter(): int
    {
        return max(60, (int) config('publishing.queue.stale_after', 900));
    }
}
