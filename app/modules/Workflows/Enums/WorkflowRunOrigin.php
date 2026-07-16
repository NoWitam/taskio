<?php

namespace App\Modules\Workflows\Enums;

/**
 * Why a workflow run began. `event` = a domain event matched the workflow's trigger
 * (Batch 3). `schedule` = the schedule sweep fired it (Batch 3b). `manual` = a user
 * started it by hand (Batch 3+). Recorded on the run so the timeline can explain its
 * origin; the MVP engine accepts any value but only produces runs the caller labels.
 */
enum WorkflowRunOrigin: string
{
    case EVENT = 'event';
    case SCHEDULE = 'schedule';
    case MANUAL = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::EVENT => 'Zdarzenie',
            self::SCHEDULE => 'Harmonogram',
            self::MANUAL => 'Ręcznie',
        };
    }
}
