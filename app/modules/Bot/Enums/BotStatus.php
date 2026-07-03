<?php

namespace App\Modules\Bot\Enums;

enum BotStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case DISABLED = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Szkic',
            self::ACTIVE => 'Aktywny',
            self::DISABLED => 'Wyłączony',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::DRAFT => 'neutral',
            self::ACTIVE => 'success',
            self::DISABLED => 'danger',
        };
    }
}
