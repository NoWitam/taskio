<?php

namespace App\Modules\Bot\Enums;

/**
 * Why a bot execution run started. Drives which lifecycle bot_action is recorded and
 * appears in the run's task_started payload for the frontend timeline.
 */
enum BotRunTrigger: string
{
    case Initial = 'initial';
    case Resume = 'resume';
    case Revision = 'revision';

    // B7: a human-initiated retry of a failed/handed-over task. Exempt from the per-task
    // run cap (the operator explicitly asked for another attempt).
    case Retry = 'retry';

    /** Whether this trigger bypasses the per-task run cap. */
    public function isCapExempt(): bool
    {
        return $this === self::Retry;
    }
}
