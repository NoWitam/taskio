<?php

namespace App\Modules\Bot\Enums;

/**
 * Operational buckets for the Bot Inbox (B7): a single derived state per task that the
 * bot is assigned to. The order here is the canonical display order for the FE.
 */
enum BotInboxState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Waiting = 'waiting';
    case InApproval = 'in_approval';
    case Revision = 'revision';
    case Failed = 'failed';
    case Done = 'done';

    /** @return array<int, string> the bucket keys in display order. */
    public static function keys(): array
    {
        return array_map(fn (self $state) => $state->value, self::cases());
    }
}
