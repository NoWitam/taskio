<?php

namespace App\Modules\Workflows\Listeners;

use App\Modules\Publishing\Events\PublicationConcluded;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Jobs\WorkflowRunResumeJob;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Steps\PublishStep;
use Throwable;

/**
 * The FAST path out of a `publication` wait: the moment a publication reaches an outcome, wake the
 * workflow run parked on it instead of making it sit until the next `workflows:reap-stale-runs` sweep.
 *
 * It listens to Publishing's own conclusion signal ({@see PublicationConcluded}) — raised inside
 * `PublicationManager::transition()`, which is where ADR-0055 Decision 3 said any such hook would have to
 * live, because the CAS is a builder UPDATE and fires no Eloquent model events at all. So Publishing
 * needs no knowledge whatsoever of workflows: it announces an outcome, and THIS module (which is allowed
 * to name Publishing) reacts. The run is found by the INDEXED `waiting_key`, exactly the correlation key
 * {@see PublishStep} parked it under.
 *
 * IT IS AN OPTIMIZATION, NOT THE CORRECTNESS MECHANISM — and here that sentence has teeth. The
 * conclusions this event carries are raised by the due-sweep's worker, by the reaper and by a person's
 * reconcile click; a run parked on a publication nobody ever resolves gets no event at all, forever, and
 * `needs_reconcile` is deliberately not announced (see the event). The waiting-run sweep remains the
 * backstop that bounds every one of those cases.
 *
 * NEVER THROWS. This runs INSIDE whatever concluded the publication — a queued publish worker, a sweep
 * pass, an HTTP request — so a failure here would fail that instead, and the thing it would fail is the
 * one that has just talked to a platform. Everything is wrapped and merely reported; the sweep will still
 * recover the run.
 *
 * The resume is CLAIMED by the job, not here: `claimResume` is guarded on the same correlation key, so
 * this listener racing the sweep (or an at-least-once redelivery) is a clean no-op rather than a double
 * resume.
 */
class ResumeWaitingRunOnPublicationConcluded
{
    public function handle(PublicationConcluded $event): void
    {
        try {
            $waitingKey = PublishStep::correlationKey($event->publicationId);

            $run = WorkflowRun::query()
                ->where('state', WorkflowRunState::WAITING->value)
                ->where('waiting_key', $waitingKey)
                ->first();

            if ($run === null) {
                return;
            }

            // The key the run was OBSERVED parked on rides along: by the time the job runs the step may
            // have re-suspended onto another leg (a publication that went back to `needs_reconcile`, say),
            // and the job must then lose its claim and no-op.
            WorkflowRunResumeJob::dispatch($run->id, $event->workspaceId, $waitingKey);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
