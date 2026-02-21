<?php

namespace App\Modules\Tasks\Enums;

enum TaskPriority: string
{
    CASE URGENT = 'urgent';
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';

    public function label(): string
    {
        return match($this)
        {
            self::URGENT => 'Pilne',
            self::HIGH => 'Wysoki',
            self::MEDIUM => 'Średni',
            self::LOW => 'Niski',
        };
    }

    public function tone(): string
    {
        return match($this)
        {
            self::URGENT => 'danger',
            self::HIGH => 'warning',
            self::MEDIUM => 'primary',
            self::LOW => 'neutral',
        };
    }

    public function icon(): string
    {
        return match($this)
        {
            self::URGENT => 'alert-triangle',
            self::HIGH => 'chevron-up',
            self::MEDIUM => 'minus-circle',
            self::LOW => 'chevron-down',
        };
    }
}
