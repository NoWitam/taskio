<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\Enums\WaitStatus;
use App\Modules\Workflows\Enums\WorkflowRunOrigin;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Exceptions\StepSuspended;
use App\Modules\Workflows\Jobs\WorkflowRunJob;
use App\Modules\Workflows\Jobs\WorkflowRunResumeJob;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The run-state machine for workflow runs. Follows the SHAPE of BotTaskRunManager (per the
 * approved Etap 5 plan) rather than the formal Manager layer: a lifecycle owner built
 * around an ATOMIC conditional-UPDATE claim.
 *
 *   pending ──claim──▶ running ──release(completed|failed)──▶ terminal
 *                        │  ▲
 *              suspend() │  │ claimResume()
 *                        ▼  │
 *                       waiting
 *
 * A run is CLAIMED with a single conditional UPDATE:
 *
 *   UPDATE workflow_runs
 *   SET state='running', started_at=now()
 *   WHERE id=? AND state='pending'
 *
 * Postgres locks the row for the UPDATE, so exactly one concurrent worker can flip
 * pending -> running and see affected=1. That is the concurrency guard: a run executes at
 * most once even under a non-sync queue (no check-then-act race), and started_at is stamped
 * in the SAME statement so the reaper can tell a genuinely stuck run from a slow one.
 *
 * The `waiting` detour (suspend/claimResume/reapWaitingRuns) uses the SAME guarded-UPDATE
 * discipline, so a doubly-delivered resume is a no-op rather than a double execution. It is
 * strictly ADDITIVE: a workflow without a suspending step never touches those methods and its
 * runs behave exactly as before.
 */
class WorkflowRunManager
{
    /**
     * How many parked runs the waiting sweep loads per page. Deliberately small: each row costs a
     * WaitResolver call (which may itself query a feature module's table) and possibly a dispatch,
     * so the page is sized for bounded memory and a short-lived transaction window, not throughput.
     */
    private const SWEEP_CHUNK = 20;

    public function __construct(
        private WaitResolverRegistry $waitResolvers,
        private TenantContext $tenant,
    ) {}

    /**
     * Create a `pending` run and dispatch its job after the surrounding transaction
     * commits (so a sync worker sees the committed row, and an async worker never races a
     * not-yet-committed insert). Returns the created run.
     *
     * $depth / $originRunId thread the re-trigger chain. $creatorId is the acting user for a
     * MANUAL run and null for an engine-started one (event/schedule) — a null creator INHERITS
     * the workflow author, so an engine run is attributed to whoever authored the definition
     * (never NULL). Both the id and 'user' type are set EXPLICITLY so HasCreator's saving hook
     * cannot stamp a parent run onto a child re-trigger run. Engine-vs-manual is read from
     * `origin`, never from creator_id.
     *
     * @param  array<string, mixed>  $triggerPayload
     */
    public function start(
        Workflow $workflow,
        WorkflowRunOrigin $origin,
        array $triggerPayload,
        int $depth = 0,
        ?string $originRunId = null,
        ?string $creatorId = null,
    ): WorkflowRun {
        $run = WorkflowRun::create([
            'workflow_id' => $workflow->id,
            'state' => WorkflowRunState::PENDING,
            'origin' => $origin,
            // WorkflowRun.trigger_type stays a plain string column — unwrap the model's enum cast.
            'trigger_type' => $workflow->trigger_type->value,
            'trigger_payload' => $triggerPayload,
            'context' => null,
            'depth' => $depth,
            'origin_run_id' => $originRunId,
            'creator_id' => $creatorId ?? $workflow->creator_id,
            'creator_type' => 'user',
        ]);

        DB::afterCommit(fn () => WorkflowRunJob::dispatch($run->id));

        return $run;
    }

    /**
     * Atomic claim: flip a `pending` run to `running` and stamp started_at in the same
     * statement. Returns true iff THIS call won the claim (a lost claim is a no-op — the
     * run is already running or terminal).
     */
    public function claim(WorkflowRun $run): bool
    {
        $affected = WorkflowRun::withoutGlobalScopes()
            ->whereKey($run->id)
            ->where('state', WorkflowRunState::PENDING->value)
            ->update([
                'state' => WorkflowRunState::RUNNING->value,
                'started_at' => now(),
            ]);

        return $affected === 1;
    }

    /**
     * Release a claimed run to a terminal state, stamping finished_at (and an error on
     * failure). Only the terminal state + finish metadata change — the accumulated context
     * and step rows are left intact for the timeline.
     */
    public function release(WorkflowRun $run, WorkflowRunState $terminal, ?string $error = null): void
    {
        WorkflowRun::withoutGlobalScopes()
            ->whereKey($run->id)
            ->update([
                'state' => $terminal->value,
                'finished_at' => now(),
                'error' => $error,
            ]);
    }

    /**
     * Stale-claim reaper: release runs stuck in `running` past config('workflows.run_timeout')
     * to `failed` with a timeout error. A worker killed mid-run (SIGKILL/OOM) never fires the
     * job's failed() hook, and claim() only matches `pending`, so such a run would strand
     * forever. Also reaps NULL-started orphans (rows claimed before this column existed).
     *
     * Runs on the CURRENTLY ACTIVE connection; the `workflows:reap-stale-runs` command
     * sweeps the shared DB and every own-database tenant. Returns the number reaped.
     */
    public function reapStaleRuns(): int
    {
        $timeout = max(60, (int) config('workflows.run_timeout', 900));
        $cutoff = now()->subSeconds($timeout);

        $stale = WorkflowRun::withoutGlobalScopes()
            ->where('state', WorkflowRunState::RUNNING->value)
            ->where(function ($query) use ($cutoff) {
                // NULL only for rows claimed before started_at existed (pre-deploy
                // orphans): post-deploy every claim stamps started_at atomically.
                $query->whereNull('started_at')
                    ->orWhere('started_at', '<', $cutoff);
            })
            ->get();

        foreach ($stale as $run) {
            $this->release($run, WorkflowRunState::FAILED, 'Run reaped: stuck in running past the timeout.');
        }

        return $stale->count();
    }

    /**
     * PARK a running run: a step handed work to something outside this process and cannot publish
     * its output yet. A GUARDED update (`state='running'`) so a run that was already reaped or
     * released in the meantime is never resurrected into `waiting`. Returns the number of rows the
     * park actually wrote (1 normally, 0 when the guard lost) — see the DROPPED PARK note below.
     *
     * The accumulated context is persisted in the SAME statement (it holds every EARLIER step's
     * output, which the resumed pass must read), together with the descriptive wait columns.
     *
     * `started_at` is deliberately LEFT AS-IS: the stale-RUNNING reaper matches `state='running'`
     * only, so a waiting run is already immune to it, and re-stamping here would only muddy the
     * audit trail. `finished_at`/`error` stay untouched — the run is not terminal.
     *
     * `waiting_since`, by contrast, IS re-stamped on every park — including a RE-park of the same
     * step onto the same leg. So `workflows.wait_timeout` bounds the time since the LAST park, not
     * the total time the run has been waiting. That is deliberate and cannot loop: a resume is only
     * dispatched on a SETTLED or GONE observation, and a step that re-suspends did so because its
     * work is still pending, so two machines can never ping-pong the clock. The one case that does
     * extend a wait indefinitely is a HUMAN repeatedly acting on the external work (e.g. refining a
     * generation), which is precisely the case the timeout should not cut short. Any future resolver
     * that can report SETTLED for work a step then re-parks on would break that reasoning — keep the
     * two sides in lock-step (see GenerationSessionWaitResolver's mapping note).
     *
     * WHAT `waiting_on` CARRIES (the resume contract):
     *   kind / step_key / step_type / position / payload  — the step's own descriptors,
     *   config           the ALREADY-RESOLVED config the step suspended with. Replayed verbatim on
     *                    resume so a spend-incurring directive in a suspendable step's config is
     *                    paid EXACTLY ONCE (and so the outcome is collected under the very config
     *                    the external work was started with).
     *   definition_hash  a fingerprint of the WHOLE step definition at suspend time; ANY mid-wait
     *                    edit fails the resumed run loudly instead of silently altering it.
     *   ai_text_calls    engine meta: how many `@[ai-text]` calls this run had already made, so the
     *                    per-RUN budget survives the resume (a fresh job = a fresh service instance).
     *
     * PRIVACY. `waiting_on` now carries the RESOLVED config, which may hold form-submitted personal
     * data (a `{{trigger.fields.*}}` reference resolves to whatever the submitter typed) and
     * AI-generated content. It MUST NEVER be serialized into a run-detail API Resource, logged, or
     * exposed to the frontend — {@see \App\Modules\Workflows\Http\Resources\WorkflowRunResource}
     * deliberately omits it. Keep it that way.
     *
     * DROPPED PARK. The guard can lose: a concurrent failure or the stale-running reaper may have
     * already released the run. The external work is then in flight and ORPHANED, so this reports it
     * (a warning carrying the run id + wait KIND only — never the payload or the config) and returns
     * 0 rather than swallowing it.
     *
     * Values are json_encoded EXPLICITLY: a guarded builder update bypasses the model's casts.
     *
     * @param  array<string, mixed>  $context  the run context accumulated BEFORE this step
     * @param  array<string, mixed>  $config  the step's RESOLVED config (replayed on resume)
     * @param  string  $definitionHash  fingerprint of the whole step definition at suspend time
     * @param  int  $aiTextCalls  ai-text calls made by this run so far
     * @return int rows written: 1 on a successful park, 0 on a dropped one
     */
    public function suspend(
        WorkflowRun $run,
        int $position,
        string $key,
        string $type,
        StepSuspended $suspension,
        array $context,
        array $config,
        string $definitionHash,
        int $aiTextCalls,
    ): int {
        $affected = WorkflowRun::withoutGlobalScopes()
            ->whereKey($run->id)
            ->where('state', WorkflowRunState::RUNNING->value)
            ->update([
                'state' => WorkflowRunState::WAITING->value,
                'context' => json_encode($context),
                'waiting_on' => json_encode([
                    'kind' => $suspension->kind,
                    'step_key' => $key,
                    'step_type' => $type,
                    'position' => $position,
                    'payload' => $suspension->payload,
                    'config' => $config,
                    'definition_hash' => $definitionHash,
                    'ai_text_calls' => $aiTextCalls,
                ]),
                'waiting_key' => $suspension->correlationKey,
                'waiting_since' => now(),
            ]);

        if ($affected === 0) {
            Log::warning('Workflow run park dropped: the run was no longer running, so its external work is orphaned.', [
                'run_id' => $run->id,
                'kind' => $suspension->kind,
            ]);
        }

        return $affected;
    }

    /**
     * Atomic RESUME claim — the exact mirror of claim(), one state earlier in the alphabet of this
     * machine: flip a `waiting` run back to `running`. Returns true iff THIS call won it, so a
     * doubled resume (a settle listener racing the sweep, or a redelivered job) is a clean no-op
     * instead of a second execution of the suspended step.
     *
     * CORRELATED CLAIM. The claim is guarded on the `waiting_key` the caller was dispatched FOR, not
     * merely on `state='waiting'`. A suspended step may re-suspend on a SECOND leg (explicitly
     * allowed by SuspendableWorkflowStep), and without this guard a duplicate/stale job for leg A
     * would win the claim on leg B and resume it while its external work was still in flight. The
     * column is indexed, so the extra predicate is free.
     *
     * A null/blank $waitingKey means UNCORRELATED and falls back to the state-only guard: that is
     * the legacy path for a job enqueued before the key rode along (and the direct-call seam used by
     * the engine tests). New dispatch sites must always pass the key they observed.
     *
     * started_at is RE-STAMPED on purpose: the resumed pass gets a FRESH `workflows.run_timeout`
     * window from the existing stale-RUNNING reaper, so a resume that hangs is recovered by the
     * machinery that already exists — no second timeout concept.
     */
    public function claimResume(WorkflowRun $run, ?string $waitingKey = null): bool
    {
        $affected = WorkflowRun::withoutGlobalScopes()
            ->whereKey($run->id)
            ->where('state', WorkflowRunState::WAITING->value)
            ->when(
                $waitingKey !== null && $waitingKey !== '',
                fn ($query) => $query->where('waiting_key', $waitingKey),
            )
            ->update([
                'state' => WorkflowRunState::RUNNING->value,
                'started_at' => now(),
            ]);

        return $affected === 1;
    }

    /**
     * Stale-WAIT sweep: decide what to do with every run parked in `waiting`. Runs on the CURRENTLY
     * ACTIVE connection, like reapStaleRuns() — the `workflows:reap-stale-runs` command drives it
     * over the shared DB and every own-database tenant. Returns how many runs were ACTED ON
     * (resumed + failed); a still-pending wait is not counted.
     *
     * Per run, in this order:
     *   1. the external work SETTLED   → dispatch the resume job (a real outcome beats a timeout,
     *                                    which is why this is asked before the age cutoff — even a
     *                                    long-overdue wait is RESUMED, never failed, once it settled);
     *   2. the external work is GONE   → fail the run now rather than waiting the timeout out;
     *   3. the wait is older than `workflows.wait_timeout` → fail it as timed out (also covers a
     *      NULL waiting_since orphan, mirroring reapStaleRuns' NULL started_at case);
     *   4. otherwise leave it waiting.
     *
     * The age cutoff is applied IN SQL, as two partitions over the (state, waiting_since) index —
     * FRESH (still inside the timeout) and STALE (past it, or a NULL orphan) — instead of loading
     * every waiting row and comparing timestamps in PHP. Both partitions ask the resolver first, so
     * the order above is unchanged; only the STALE partition can time a run out. Each partition is
     * CHUNKED by id so the sweep's memory stays bounded no matter how many runs are parked (and so
     * releasing rows mid-sweep cannot shift a page and skip a run).
     *
     * The "settled / gone" question is answered ENTIRELY through the kind-keyed
     * {@see WaitResolverRegistry}, so this engine names no feature module (see that class).
     */
    public function reapWaitingRuns(): int
    {
        $timeout = max(60, (int) config('workflows.wait_timeout', 2700));
        $cutoff = now()->subSeconds($timeout);

        $waiting = fn () => WorkflowRun::withoutGlobalScopes()
            ->where('state', WorkflowRunState::WAITING->value);

        $handled = 0;

        // FRESH: still inside the wait timeout — resumable or gone, never timed out.
        $waiting()
            ->where('waiting_since', '>=', $cutoff)
            ->chunkById(self::SWEEP_CHUNK, function ($runs) use (&$handled) {
                foreach ($runs as $run) {
                    $handled += $this->sweepWaitingRun($run, timedOut: false);
                }
            });

        // STALE: past the timeout, or a NULL orphan (a row parked before waiting_since existed).
        $waiting()
            ->where(fn ($query) => $query->whereNull('waiting_since')->orWhere('waiting_since', '<', $cutoff))
            ->chunkById(self::SWEEP_CHUNK, function ($runs) use (&$handled) {
                foreach ($runs as $run) {
                    $handled += $this->sweepWaitingRun($run, timedOut: true);
                }
            });

        return $handled;
    }

    /**
     * Decide ONE parked run's fate (see reapWaitingRuns' ordering). $timedOut says which partition
     * the row came from: only a STALE row may be failed as timed out, and only after the resolver
     * had its say. Returns 1 when the run was acted on, 0 when it was left waiting.
     */
    private function sweepWaitingRun(WorkflowRun $run, bool $timedOut): int
    {
        $waitingOn = is_array($run->waiting_on) ? $run->waiting_on : [];
        $status = $this->waitResolvers->statusFor((string) ($waitingOn['kind'] ?? ''), $run, $waitingOn);

        if ($status === WaitStatus::SETTLED) {
            // The job is CORRELATED to the key this sweep observed: by the time it runs, the step
            // may already have been resumed onto a different leg, and that job must then no-op.
            WorkflowRunResumeJob::dispatch($run->id, $this->workspaceIdFor($run), (string) $run->waiting_key);

            return 1;
        }

        if ($status === WaitStatus::GONE) {
            $this->release($run, WorkflowRunState::FAILED, __('workflows.runs.wait_gone'));

            return 1;
        }

        if ($timedOut) {
            $this->release($run, WorkflowRunState::FAILED, __('workflows.runs.wait_timed_out'));

            return 1;
        }

        return 0;
    }

    /**
     * The workspace the resume job must re-establish. In shared-database mode the run row carries
     * workspace_id (the sweep's shared pass runs with NO active context); in own-database mode that
     * column does not exist and the active tenant context — set by the command for that pass — is
     * the answer.
     */
    private function workspaceIdFor(WorkflowRun $run): string
    {
        return (string) ($run->getAttribute('workspace_id') ?? $this->tenant->id() ?? '');
    }

    /**
     * How many runs this workflow has started since the start of the current month. The
     * per-workflow monthly cost proxy the dispatcher checks before starting a run
     * (see config/workflows.php).
     */
    public function runsThisMonth(Workflow $workflow): int
    {
        return WorkflowRun::query()
            ->where('workflow_id', $workflow->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /**
     * How many runs have started across the WHOLE active workspace since the start of the
     * month — the workspace-wide hard-cap proxy the dispatcher checks last. The query
     * is workspace-scoped automatically (WorkflowRun is TenantAware), so in shared-db mode it
     * counts only this workspace's rows and in own-db mode it runs on the tenant connection.
     * One cheap COUNT with a covering (workspace_id, created_at) filter — cheaper than summing
     * per workflow, and correct as a runaway-spend ceiling.
     */
    public function runsThisMonthAcrossWorkspace(): int
    {
        return WorkflowRun::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }
}
