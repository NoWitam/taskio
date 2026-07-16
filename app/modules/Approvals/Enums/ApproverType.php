<?php

namespace App\Modules\Approvals\Enums;

enum ApproverType: string
{
    case User = 'user';
    case Ai = 'ai';
    case Bot = 'bot';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Użytkownik',
            self::Ai => 'AI',
            self::Bot => 'Bot',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::User => 'user',
            self::Ai => 'sparkles',
            self::Bot => 'bot',
        };
    }

    /**
     * Whether this approver is evaluated automatically by the AI pipeline (a generic
     * AI stage OR a named bot approver). Drives the ProcessAiApprovalJob dispatch.
     */
    public function isAutomated(): bool
    {
        return $this === self::Ai || $this === self::Bot;
    }
}
