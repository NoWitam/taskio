<?php

namespace App\Modules\Workflows\Enums;

/**
 * The outcome of a single step within a workflow run, recorded as an audit row
 * (WorkflowRunStep). `pending` exists for a step row created before execution; the runner
 * flips it to `succeeded` (with an output payload) or `failed` (with an error) as it goes.
 */
enum WorkflowRunStepStatus: string
{
    case PENDING = 'pending';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Oczekuje',
            self::SUCCEEDED => 'Zakończony',
            self::FAILED => 'Błąd',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::PENDING => 'neutral',
            self::SUCCEEDED => 'success',
            self::FAILED => 'danger',
        };
    }
}
