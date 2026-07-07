<?php

namespace App\Modules\Bot\Enums;

/**
 * A bot's operational status. Collapsed to a two-state toggle (active | inactive): a bot
 * is either live (can execute tasks) or off. Status is never supplied on create/update —
 * a bot is created INACTIVE and toggled through the dedicated status endpoint.
 */
enum BotStatus: string
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
