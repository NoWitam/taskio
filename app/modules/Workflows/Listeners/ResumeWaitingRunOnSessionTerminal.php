<?php

namespace App\Modules\Workflows\Listeners;

use App\Modules\Generator\Events\GenerationSessionUpdated;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Jobs\WorkflowRunResumeJob;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Steps\GenerateContentStep;
use Throwable;

/**
 * The FAST path out of a `generation_session` wait: the moment a generation run settles, wake the workflow
 * run parked on it instead of making it sit until the next `workflows:reap-stale-runs` sweep.
 *
 * It listens to the Generator's own terminal signal ({@see GenerationSessionUpdated}) — the event the chat
 * already broadcasts — so the Generator needs no knowledge whatsoever of workflows: it fires its event, and
 * THIS module (which is allowed to name Generator) reacts. The run is found by the INDEXED `waiting_key`,
 * exactly the correlation key {@see GenerateContentStep} parked it under.
 *
 * IT IS AN OPTIMIZATION, NOT THE CORRECTNESS MECHANISM. The waiting-run sweep remains the backstop and MUST
 * stay one: the generator's own lifecycle reaper fails a stranded session with the tenant context CLEARED,
 * and {@see \App\Modules\Generator\Services\GenerationSessionRunManager} deliberately SKIPS the broadcast in
 * that case — so an event-only design would strand precisely the runs that most need recovering.
 *
 * NEVER THROWS. This runs INSIDE the generation worker (the event is dispatched on the way out of the run),
 * so a failure here would fail that job — retrying an already-finished generation, or marking it failed
 * after it succeeded. Everything is wrapped and merely reported; the sweep will still recover the run.
 *
 * The resume is CLAIMED by the job, not here: `claimResume` is guarded on the same correlation key, so this
 * listener racing the sweep (or an at-least-once redelivery) is a clean no-op rather than a double resume.
 */
class ResumeWaitingRunOnSessionTerminal
{
    /** The session statuses that end a wait — the same terminal set the wait resolver calls SETTLED. */
    private const TERMINAL = ['ready', 'failed'];

    public function handle(GenerationSessionUpdated $event): void
    {
        try {
            if (!in_array($event->status, self::TERMINAL, true)) {
                return;
            }

            $waitingKey = GenerateContentStep::correlationKey($event->sessionId);

            $run = WorkflowRun::query()
                ->where('state', WorkflowRunState::WAITING->value)
                ->where('waiting_key', $waitingKey)
                ->first();

            if ($run === null) {
                return;
            }

            // The key the run was OBSERVED parked on rides along: by the time the job runs the step may
            // have re-suspended onto another leg, and the job must then lose its claim and no-op.
            WorkflowRunResumeJob::dispatch($run->id, $event->workspaceId, $waitingKey);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
