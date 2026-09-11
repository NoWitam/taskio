<?php

namespace App\Modules\Publishing\Services;

use App\Modules\Publishing\Contracts\PlatformAdapter;
use App\Modules\Publishing\DTOs\RemoteRef;
use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Exceptions\PlatformRefused;
use App\Modules\Publishing\Exceptions\PublicationTransitionRefused;
use App\Modules\Publishing\Managers\PublicationManager;
use App\Modules\Publishing\Models\Publication;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * TAKING ONE PUBLICATION THROUGH THE TWO PHASES — the sequence, and nothing else.
 *
 * It decides no states (that is the Manager) and talks to no platform (that is the adapter). What it
 * owns is the ORDER, and the order is the safety property: the sequence below is what makes a crash at
 * any point in it recoverable without a duplicate post.
 *
 *   1. claim                      scheduled|failed → publishing
 *   2. phase 1, IF NEEDED         no handle yet → createDraft()
 *   3. PERSIST THE HANDLE         immediately, own write, before anything else
 *   4. phase 2                    publishDraft(handle)
 *   5. markPublished(ref)         publishing → published
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * STEP 2 IS CONDITIONAL, AND THAT CONDITION IS THE WHOLE MODULE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A publication that already carries `remote_draft_id` HAS A CONTAINER ON THE PLATFORM. Re-running
 * phase 1 for it would make a second one, and a second container publishes exactly as publicly as the
 * first. So a resume goes straight to phase 2 with the stored handle. This is the reason the column
 * exists and the reason it is written in its own committed statement at step 3.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THERE IS NO TRANSACTION AROUND ANY OF THIS, DELIBERATELY
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The house rule is "use transactions for multi-step writes", and this is the case that rule is wrong
 * for. A transaction spanning steps 3–5 would roll the handle back when phase 2 failed, leaving a clean
 * row in front of a container that exists — which is the precise duplicate this design is built to
 * prevent. Atomicity is the wrong goal when one of the steps has already changed the outside world.
 *
 * Each step is a single-row write and is individually durable, which is what the recovery story
 * actually needs. The same reasoning is why an attempt row is never rolled back with the outcome it
 * records.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * HOW FAILURE IS CLASSIFIED, AND WHY THE DEFAULT LEANS THE HEAVY WAY
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *   PlatformRefused        → failed            "nothing was created" is part of that exception's
 *                                              contract, so a retry is safe.
 *   anything else          → needs_reconcile   a timeout, a reset, a 5xx, an unrecognised shape. We do
 *                                              not know, and the state with no automatic exit is the
 *                                              correct place for not knowing.
 *
 * The asymmetry is intentional. Being wrong towards `needs_reconcile` costs a person looking at a row
 * that turned out fine. Being wrong the other way costs a second public post.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT B3 CHANGED HERE, WHICH IS ALMOST NOTHING — AND THAT WAS THE POINT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * B1 predicted that B3 would wrap this in a job and add a sweep, and that neither would change the
 * sequence or the classification because both already live here. That held. The only structural change
 * is that step 1 was split off into {@see publish()} so {@see publishClaimed()} can be entered by a
 * worker for a row the SWEEP already claimed — the claim has to happen in the sweep, or two sweeps
 * dispatch two jobs for one publication.
 *
 * There is still NO BACKOFF and there never will be one, which is worth stating because a queue is
 * exactly where somebody would add it. Every outcome this method produces is already terminal for the
 * attempt: `failed` is retried by a person, and `needs_reconcile` has no automatic exit at all. A job
 * that retried on its own would be the coin flip the whole module is built to refuse — which is why
 * `PublishPublicationJob` carries `tries = 1` and a test reads that property.
 */
class PublicationPublisher
{
    public function __construct(
        private PublicationManager $manager,
        private PlatformAdapterRegistry $adapters,
    ) {}

    /**
     * Publish one publication, now.
     *
     * Returns the row in whatever state it ended in — `published`, `failed` or `needs_reconcile`. It
     * does NOT throw for a platform failure: a failure is a legitimate outcome of publishing and the
     * state carries it. It DOES let a refused TRANSITION propagate, because that is a caller mistake
     * (asking to publish something that is not claimable) rather than an outcome.
     *
     * SINCE B3 THIS HAS NO PRODUCTION CALLER — the sweep claims via `claimDue()` and the job enters at
     * {@see publishClaimed()}, and that pair is the production path. It stays because the claim-then-
     * publish sequence it states is the contract the queue implements, and the dry-run tests drive it
     * directly. A future caller (B6's workflow step, say) that reaches for it instead of arming a row
     * for the sweep would bypass the sweep's batch accounting and the job's overlap lock — decide that
     * consciously or route through the queue.
     *
     * @throws \App\Modules\Publishing\Exceptions\PublicationTransitionRefused when the row cannot be claimed
     */
    public function publish(Publication $publication): Publication
    {
        // Step 1. Refuses here, before any platform is touched, when the row is in `needs_reconcile`
        // or `blocked` — the two fences.
        $this->manager->claim($publication);

        return $this->publishClaimed($publication);
    }

    /**
     * STEPS 2–5, for a row THAT HAS ALREADY BEEN CLAIMED.
     *
     * The seam B3 needed, and the reason it is a seam rather than a flag on {@see publish()}: the queue
     * claims in the SWEEP and publishes in a JOB, minutes and a process apart. The claim has to happen in
     * the sweep — that atomic `scheduled → publishing` is the only thing that stops two sweeps
     * dispatching two jobs for one publication — so by the time the worker picks the row up, the edge
     * this class used to take has already been taken. Calling `claim()` again would be `publishing →
     * publishing`, which the machine correctly refuses.
     *
     * The GUARD below is the other half of that arrangement. This method is entered by a worker holding
     * only an id, and a row that is no longer `publishing` means something else has already concluded
     * this attempt — a reaper parked it, a person reconciled it, the previous delivery finished it. It
     * refuses rather than proceeding, because "publish a row somebody else already resolved" is the one
     * shape of duplicate this module exists to prevent, and an id is not proof of a claim.
     *
     * It is a `LogicException` and NOT a {@see PublicationTransitionRefused}, deliberately. The latter
     * describes an edge the machine does not contain and renders as a 422 a person can act on; this is a
     * caller that skipped the claim, which is a programming error with no user-facing reading. The
     * callers that could hit it both check first — `publish()` has just claimed, and
     * `PublishPublicationJob` re-reads the status before entering — so reaching this is a statement that
     * one of those checks has been removed.
     *
     * @throws \LogicException when the row was not claimed first
     */
    public function publishClaimed(Publication $publication): Publication
    {
        if ($publication->status !== PublicationStatus::PUBLISHING) {
            throw new LogicException(
                'A publication must be claimed before it is published; this one is ' . $publication->status->value . '.',
            );
        }

        try {
            $adapter = $this->adapters->resolve($publication->platform);

            // Steps 2 and 3.
            if (!$publication->hasRemoteDraft()) {
                $this->manager->rememberDraft($publication, $adapter->createDraft($publication));
            }

            // Step 4. The handle is passed explicitly from the model, so a resume publishes the
            // container that was stored rather than one the adapter might re-derive.
            $ref = $adapter->publishDraft($publication, (string) $publication->remote_draft_id);

            // Step 5.
            return $this->manager->markPublished($publication, $ref);
        } catch (PlatformRefused $e) {
            return $this->manager->markFailed($publication, $e->failureCode, $e->context);
        } catch (PublicationTransitionRefused $e) {
            // THE ROW MOVED WHILE THIS WORKER HELD IT — a redelivery's park, a reaper — and the CAS
            // refused this frame's conclusion. That is the machinery WORKING, so it must not fall
            // into the catch below: the ERROR line there would claim an "unknown state" about a
            // publish whose outcome this frame knows precisely, and the follow-up park would throw a
            // second, misleading refusal (from == to). Rethrown instead — the job logs it under its
            // real reason. The remote id is logged when this frame holds one: it is an identifier,
            // not a credential, and it is the exact string a later reconciliation will establish.
            Log::info('A publish conclusion lost the race for its row; the winner\'s status stands.', [
                'publication' => $publication->id,
                'platform' => $publication->platform->value,
                'row_status' => $publication->status->value,
                'remote_id' => isset($ref) ? $ref->id : null,
            ]);

            throw $e;
        } catch (Throwable $e) {
            // CLASS AND LOCATION, NEVER THE MESSAGE — the same rule the job's failure hook and the
            // tenant sweeps already keep, and this is the line where breaking it would cost the most.
            //
            // This catch sits directly above an adapter that is holding a live access token while it
            // talks to a platform, and it catches EVERYTHING. The exception whose message reaches this
            // array is composed by code nobody here controls: an HTTP client that puts the failing URL
            // in it (Meta authenticates by `?access_token=…` in the query string — the token would be
            // in the log verbatim, on every timeout, forever), a client exception that embeds the first
            // 120 characters of a response body, a driver quoting its own bindings.
            //
            // The class says what went wrong and the file and line say where. That is the diagnosis a
            // person actually acts on, and it cannot carry a credential no matter who wrote the
            // exception. `PublishingConnectionSecrecyTest` drives this exact path with a token in the
            // message and searches the log for it.
            Log::error('Publication ended in an unknown state and needs reconciliation.', [
                'publication' => $publication->id,
                'platform' => $publication->platform->value,
                'had_remote_draft' => $publication->hasRemoteDraft(),
                'exception' => $e::class,
                'at' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return $this->manager->markNeedsReconcile($publication, 'publish_outcome_unknown', [
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * ASK THE PLATFORM WHAT ACTUALLY HAPPENED, and let the answer decide the state.
     *
     * The ONLY way out of `needs_reconcile`, and it is a separate entry point rather than a step inside
     * {@see publish()} on purpose: reconciliation must never be something a retry does on the way past.
     * A person (B3: a button; later, possibly a supervised sweep) decides that it happens, and the
     * platform decides what it concludes.
     *
     *   a ref  → published. It was out all along; the record now says so.
     *   null   → failed.    PROVEN absent — which is what re-opens the ordinary retry path.
     *   throw  → unchanged. The adapter could not establish either. Staying in `needs_reconcile` is the
     *            correct outcome, not a gap: nothing has been learned, so nothing may move.
     *
     * @throws \App\Modules\Publishing\Exceptions\PublicationTransitionRefused when the row is not in a state a conclusion applies to
     */
    public function reconcile(Publication $publication): Publication
    {
        $adapter = $this->adapters->resolve($publication->platform);

        try {
            $existing = $this->findExisting($adapter, $publication);
        } catch (Throwable $e) {
            // Class and location, never the message. See publishClaimed() — the reasoning is identical
            // and this path is worse in one respect: a probe runs every hour, unattended, for as long
            // as a row sits unresolved. A token in this message would not be logged once; it would be
            // logged on a schedule.
            Log::error('Reconciliation could not establish whether a publication exists.', [
                'publication' => $publication->id,
                'platform' => $publication->platform->value,
                'exception' => $e::class,
                'at' => $e->getFile() . ':' . $e->getLine(),
            ]);

            // Nothing was learned, so nothing moves. The row stays where a person can see it.
            return $publication;
        }

        if ($existing instanceof RemoteRef) {
            return $this->manager->markPublished($publication, $existing);
        }

        return $this->manager->markFailed($publication, 'reconciled_absent');
    }

    /** Extracted only so the two outcomes above read as two outcomes rather than as a nested try. */
    private function findExisting(PlatformAdapter $adapter, Publication $publication): ?RemoteRef
    {
        return $adapter->findExisting($publication);
    }
}
