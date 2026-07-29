<?php

namespace App\Modules\Workflows\Support;

/**
 * The in-process record of the queue connection that was active BEFORE the run job forced the `sync`
 * driver — a tiny twin of {@see \App\Modules\Workflows\Services\WorkflowRunContext}, and the EXPLICIT,
 * NAMED escape hatch out of that override.
 *
 * WHY THE OVERRIDE EXISTS. Both run jobs wrap the step loop in `Queue::setDefaultDriver('sync')` so
 * anything a step dispatches runs in-process while {@see \App\Modules\Workflows\Services\WorkflowRunContext}
 * is still alive — that is what lets HasCreator stamp step-authored rows with the RUN (see ADR-0015).
 *
 * WHY THE HATCH EXISTS. A SUSPENDING step wants the opposite: its external work must go to the REAL
 * queue and outlive this process, otherwise it would run synchronously and the suspension would be
 * pointless. Such a step reads `current()` and dispatches `onConnection()` it.
 *
 *     $connection = app(RealQueueConnection::class)->current();
 *     $job = SomeJob::dispatch(...);
 *     if ($connection !== null) { $job->onConnection($connection); }
 *
 * LIFECYCLE. SAVE/RESTORE, in the same `finally` that restores the driver: a run job reads
 * `current()` first, publishes ONLY if it is null, and puts the saved value back on the way out (the
 * outermost job thereby restores null). Restoring is load-bearing: this is a container SINGLETON that
 * survives across jobs in a long-running worker, so a leaked value would mis-route dispatches made by
 * a later, unrelated job — while an unconditional clear would break the opposite case, a re-triggered
 * child run executing IN-PROCESS inside a step, which would wipe the PARENT's published value and
 * leave the parent's suspending step dispatching onto the forced `sync` driver.
 *
 * Nothing may depend on the ambient override still being in place — `current()` returning null simply
 * means "dispatch normally".
 */
class RealQueueConnection
{
    private ?string $connection = null;

    public function set(?string $connection): void
    {
        $this->connection = $connection;
    }

    /**
     * Hard reset, for a teardown that owns the whole process. A run job must NOT use this — it would
     * wipe an outer run's published value; it saves and restores instead (see LIFECYCLE above).
     */
    public function clear(): void
    {
        $this->connection = null;
    }

    /** The pre-override connection name, or null when no run job is currently overriding the driver. */
    public function current(): ?string
    {
        return $this->connection;
    }
}
