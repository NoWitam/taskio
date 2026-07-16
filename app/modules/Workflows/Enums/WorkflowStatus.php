<?php

namespace App\Modules\Workflows\Enums;

/**
 * A workflow definition's operational status. A two-state toggle (active | inactive):
 * a workflow is either live (its trigger may fire) or off. Status is never supplied on
 * create/update — a workflow is created INACTIVE and toggled through the dedicated
 * status endpoint.
 */
enum WorkflowStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Aktywny',
            self::INACTIVE => 'Nieaktywny',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::ACTIVE => 'success',
            self::INACTIVE => 'neutral',
        };
    }
}
