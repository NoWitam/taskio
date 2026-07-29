<?php

namespace App\Modules\Generator\Enums;

/**
 * Lifecycle of a generation SESSION (R2 sub-stage 2b). A session starts a `draft` (recipe snapshotted,
 * slots being filled), is atomically CLAIMED into `generating` when a run is kicked off, and lands a
 * terminal `ready` (results persisted — inspect per-part status) or `failed` (the whole run threw).
 * Mirrors {@see \App\Modules\Disk\Enums\DiskAiEditStatus} — a string-backed enum whose value is the wire
 * id + the persisted column, with an isTerminal() guard the worker uses for idempotency.
 */
enum GenerationSessionStatus: string
{
    case Draft = 'draft';
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';

    /** A finished run — no worker will touch it again (results stored or the run abandoned). */
    public function isTerminal(): bool
    {
        return $this === self::Ready || $this === self::Failed;
    }

    /**
     * Whether the user may edit inputs (name / slot_values) in this state — only OUTSIDE a run: a
     * `draft` being filled or a completed `ready` being tweaked before a re-generate. `generating` is
     * in-flight and `failed` is retried as-is (edit is re-enabled once it returns to draft/ready).
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Ready;
    }
}
