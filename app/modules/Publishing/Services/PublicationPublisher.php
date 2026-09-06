<?php

namespace App\Modules\Publishing\Services;

use App\Modules\Publishing\Contracts\PlatformAdapter;
use App\Modules\Publishing\DTOs\RemoteRef;
use App\Modules\Publishing\Exceptions\PlatformRefused;
use App\Modules\Publishing\Managers\PublicationManager;
use App\Modules\Publishing\Models\Publication;
use Illuminate\Support\Facades\Log;
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
 * WHAT B1 DOES NOT DO
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * This runs SYNCHRONOUSLY. There is no queue, no backoff, no due-sweep and no stale-`publishing`
 * reaper — all B3. The seam is deliberate: B3 wraps this method in a job and adds a sweep that finds
 * rows stuck in `publishing`, and neither of those changes the sequence or the classification, because
 * both already live here.
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
     * @throws \App\Modules\Publishing\Exceptions\PublicationTransitionRefused when the row cannot be claimed
     */
    public function publish(Publication $publication): Publication
    {
        // Step 1. Refuses here, before any platform is touched, when the row is in `needs_reconcile`
        // or `blocked` — the two fences.
        $this->manager->claim($publication);

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
        } catch (Throwable $e) {
            // The message is logged and NOT stored: a platform's prose is composed on their servers in
            // whatever language they choose, and a `failure_context` is read by a UI.
            Log::error('Publication ended in an unknown state and needs reconciliation.', [
                'publication' => $publication->id,
                'platform' => $publication->platform->value,
                'had_remote_draft' => $publication->hasRemoteDraft(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
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
            Log::error('Reconciliation could not establish whether a publication exists.', [
                'publication' => $publication->id,
                'platform' => $publication->platform->value,
                'exception' => $e::class,
                'message' => $e->getMessage(),
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
