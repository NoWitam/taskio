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

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Oczekuje',
            self::RUNNING => 'W trakcie',
            self::WAITING => 'Wstrzymany',
            self::COMPLETED => 'Zakończony',
            self::FAILED => 'Błąd',
            self::CANCELLED => 'Anulowany',
        };
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
