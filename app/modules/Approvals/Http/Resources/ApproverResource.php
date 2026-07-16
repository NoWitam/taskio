<?php

namespace App\Modules\Approvals\Http\Resources;

use App\Models\User;
use App\Modules\Approvals\Enums\ApproverType;
use App\Modules\Bot\Models\Bot;

/**
 * Presents the resolved approver identity for a stage / process. Returns a tagged
 * shape so the frontend can render a User, a Bot, or nothing (a generic AI stage has
 * no named approver). Tolerates a null relation (former member / missing bot).
 *
 * Shape:
 *   - user approver: { type: 'user', id, name, email, avatar: null, is_bot: false }
 *   - bot  approver: { type: 'bot',  id, name, email: null, avatar: null, is_bot: true }
 *   - ai stage / unresolved: null
 */
class ApproverResource
{
    /**
     * @param  ApproverType  $type  the stage/process approver_type
     * @param  object|null  $user  the resolved User relation (user branch)
     * @param  object|null  $bot  the resolved Bot relation (bot branch)
     * @return array<string, mixed>|null
     */
    public static function present(ApproverType $type, ?object $user, ?object $bot): ?array
    {
        if ($type === ApproverType::User && $user instanceof User) {
            return [
                'type' => 'user',
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => null,
                'is_bot' => false,
            ];
        }

        if ($type === ApproverType::Bot && $bot instanceof Bot) {
            return [
                'type' => 'bot',
                'id' => $bot->id,
                'name' => $bot->name,
                'email' => null,
                'avatar' => null,
                'is_bot' => true,
            ];
        }

        return null;
    }
}
