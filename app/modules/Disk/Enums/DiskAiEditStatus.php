<?php

namespace App\Modules\Disk\Enums;

/**
 * Lifecycle of an async Disk AI image edit: queued at dispatch, processing while the worker runs
 * the provider call, then a terminal done (result stored) or failed (given up).
 *
 * `SafetyRejected` is a SECOND terminal failure, split out from `Failed` because it is a different
 * thing to say to the user (the provider's safety system refused the content — change the
 * description/wardrobe) and a different thing to do about it (never retry: it is deterministic).
 *
 * It is deliberately a STORED-ONLY state: {@see wireStatus()} reports it as `failed` to clients, so
 * the poll/broadcast contract keeps the exact `queued|processing|done|failed` vocabulary consumers
 * already branch on, and the distinction reaches them ADDITIVELY as `error_code`
 * ({@see \App\Modules\Disk\Http\Resources\DiskAiEditResource}).
 */
enum DiskAiEditStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';
    case SafetyRejected = 'safety_rejected';

    /** A finished edit — no worker will touch it again (result stored or abandoned). */
    public function isTerminal(): bool
    {
        return $this === self::Done || $this->isFailure();
    }

    /** A terminal state that produced NO image, whatever the reason. */
    public function isFailure(): bool
    {
        return $this === self::Failed || $this === self::SafetyRejected;
    }

    /**
     * The status as CLIENTS see it. Every failure reads `failed` on the wire — the reason rides
     * {@see errorCode()} instead — so adding a stored state can never strand a consumer that waits
     * for a known terminal value.
     */
    public function wireStatus(): string
    {
        return $this->isFailure() ? self::Failed->value : $this->value;
    }

    /** The machine-readable reason a failed edit produced no image, or null when there is none. */
    public function errorCode(): ?string
    {
        return $this === self::SafetyRejected ? self::SafetyRejected->value : null;
    }
}
