<?php

namespace App\Modules\Disk\Enums;

/**
 * Lifecycle of an async Disk AI image edit: queued at dispatch, processing while the worker runs
 * the provider call, then a terminal done (result stored) or failed (given up).
 */
enum DiskAiEditStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';

    /** A finished edit — no worker will touch it again (result stored or abandoned). */
    public function isTerminal(): bool
    {
        return $this === self::Done || $this === self::Failed;
    }
}
