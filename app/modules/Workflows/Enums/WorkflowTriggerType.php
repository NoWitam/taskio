<?php

namespace App\Modules\Workflows\Enums;

/**
 * What event starts a workflow. Each type owns a distinct `trigger_config` shape
 * (validated per type by the Store/Update request). The execution engine that reacts
 * to these events ships in a later batch — this enum only defines the vocabulary.
 */
enum WorkflowTriggerType: string
{
    case FORM_SUBMITTED = 'form_submitted';
    case SCHEDULE = 'schedule';

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::FORM_SUBMITTED => 'Wysłanie formularza',
            self::SCHEDULE => 'Harmonogram',
        };
    }
}
