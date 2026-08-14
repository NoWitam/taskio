<?php

namespace App\Modules\Workflows\Enums;

/**
 * The lifecycle state of a single workflow RUN (one execution of a workflow's steps).
 *
 *   pending ──claim──▶ running ──release(completed|failed)──▶ terminal
 *                        │  ▲
 *              suspend() │  │ claimResume()
 *                        ▼  │
 *                       waiting
 *
 * `waiting` is now PRODUCED by the engine: a step that hands work to something outside this process
 * throws StepSuspended, and WorkflowRunManager::suspend() parks the run there until a fresh resume
 * job (or the stale-wait sweep) moves it on. It is NOT terminal and NOT matched by the stale-RUNNING
 * reaper — `workflows.wait_timeout` is what bounds it.
 *
 * `cancelled` is still designed-in but UNUSED: it anticipates a manual-cancel seam. It stays declared
 * so the column vocabulary and the frontend badge map remain stable until that lands.
 */
enum WorkflowRunState: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case WAITING = 'waiting'; // parked by suspend(); NOT terminal — bounded by workflows.wait_timeout.
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled'; // reserved — not produced by the MVP engine.

    /**
     * The state's name in the READER's language.
     *
     * This used to hardcode Polish, and it was the last remnant of an app-wide defect this chapter
     * fixed everywhere else: server prose was following `APP_LOCALE` rather than the language of the
     * person reading it. The value is serialized as `state_label` on the runs API, so the change is
     * visible there — but it is the FIX arriving, not a contract change: nothing matches on the prose.
     * The runs UI branches on `run.state` (a stable code) and uses `state_label` only as the fallback
     * argument to its own translation lookup, which is the house rule — recognize structurally, never
     * by the server's wording.
     *
     * There was briefly a second method here, `translatedLabel()`, whose body was `return $this->label()`
     * — kept so the calendar's badge call site would read as what it was doing. Two names for one string
     * is not documentation; it is a second thing to keep in step, and the next person to change the
     * wording would have had to notice both. The call site says what it means with a comment instead.
     */
    public function label(): string
    {
        return __('workflows.run_states.' . $this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::PENDING => 'neutral',
            self::RUNNING => 'info',
            self::WAITING => 'warning',
            self::COMPLETED => 'success',
            self::FAILED => 'danger',
            self::CANCELLED => 'neutral',
        };
    }

    /** Whether the run has reached a terminal (non-resumable) state. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::CANCELLED => true,
            default => false,
        };
    }
}
