<?php

namespace App\Modules\Tasks\Http\Resources;

use App\Models\User;
use App\Modules\Bot\Models\Bot;

/**
 * Polymorphic assignee presenter (User|Bot) for the additive `assignee` field on
 * task resources. Returns a tagged shape so the frontend can render a human or a bot
 * uniformly, or null when the task is unassigned. Tolerates a former-member User
 * (the member-scope bypass on Task::assignee() keeps it resolvable).
 *
 * Shape:
 *   { type: 'user'|'bot', id, name, email?: string|null, avatar: null, is_bot: bool }
 */
class TaskAssigneeResource
{
    /**
     * @return array<string, mixed>|null
     */
    public static function present(?object $assignee): ?array
    {
        if ($assignee instanceof User) {
            return [
                'type' => 'user',
                'id' => $assignee->id,
                'name' => $assignee->name,
                'email' => $assignee->email,
                'avatar' => null,
                'is_bot' => false,
            ];
        }

        if ($assignee instanceof Bot) {
            return [
                'type' => 'bot',
                'id' => $assignee->id,
                'name' => $assignee->name,
                'email' => null,
                'avatar' => null,
                'is_bot' => true,
            ];
        }

        return null;
    }
}
