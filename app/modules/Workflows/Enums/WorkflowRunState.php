<?php

namespace App\Modules\Workflows\Enums;

/**
 * The lifecycle state of a single workflow RUN (one execution of a workflow's steps).
 *
 *   pending ──claim──▶ running ──release(completed|failed)──▶ terminal
 *
 * `waiting` and `cancelled` are designed-in but UNUSED in the MVP engine: `waiting`
 * anticipates a future step that suspends a run to await an external event (e.g. a human
 * decision) — the MVP runs every step to completion in one pass. `cancelled` anticipates
 * a manual-cancel seam (Batch 3+). They are declared now so the column vocabulary and the
 * frontend badge map are stable before those features land.
 */
enum WorkflowRunState: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case WAITING = 'waiting'; // reserved — not produced by the MVP engine.
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
