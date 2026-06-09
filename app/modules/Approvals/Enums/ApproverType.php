<?php

namespace App\Modules\Approvals\Enums;

enum ApproverType: string
{
    case User = 'user';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Użytkownik',
            self::Ai => 'AI',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::User => 'user',
            self::Ai => 'sparkles',
        };
    }
}
