<?php

namespace App\Modules\Variables\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \App\Modules\Variables\Support\LedgerMeteredAiCall} when a workspace has reached its
 * effective calendar-month AI DOLLAR cap (R2 sub-stage 4) — BEFORE the provider call is invoked, so an
 * over-cap workspace's spend never fires (the load-bearing gate-before-spend safety property). `used`
 * and `cap` are estimated dollars (the gate sums the month's `estimated_cost`).
 *
 * Callers decide how to surface it: the ai-text generator swallows it fail-closed (resolves to ''),
 * the Disk image dispatch translates it to a 429, and the image worker lets the job fail cleanly.
 */
class AiBudgetExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $channel,
        public readonly float $used,
        public readonly float $cap,
    ) {
        parent::__construct("AI budget exceeded for channel [{$channel}]: \${$used} of \${$cap} estimated spend this calendar month.");
    }
}
