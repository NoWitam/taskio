<?php

namespace App\Modules\Bot\Policies;

use App\Models\User;
use App\Modules\Bot\Models\Bot;
use App\Policies\Concerns\ChecksRecordOwnership;

/**
 * Workspace membership is enforced upstream by ResolveWorkspace + WorkspaceScope:
 * a request only ever reaches a bot that belongs to the active workspace, and a
 * non-member cannot resolve the workspace at all. These checks therefore gate
 * ownership (mutations) on top of that membership guarantee.
 */
class BotPolicy
{
    use ChecksRecordOwnership;

    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, Bot $bot): bool
    {
        return $user !== null;
    }

    public function create(?User $user): bool
    {
        return $user !== null;
    }

    public function update(?User $user, Bot $bot): bool
    {
        return $this->ownsOrManagesSystemRecord($bot, $user);
    }

    /** Toggling a bot's status (active|inactive) is a creator-only action (mirrors update). */
    public function changeStatus(?User $user, Bot $bot): bool
    {
        return $this->ownsOrManagesSystemRecord($bot, $user);
    }

    public function delete(?User $user, Bot $bot): bool
    {
        return $this->ownsOrManagesSystemRecord($bot, $user);
    }

    public function restore(?User $user, Bot $bot): bool
    {
        return $this->ownsOrManagesSystemRecord($bot, $user);
    }

    /**
     * Manually retry a failed task (B7). This is a WRITE that dispatches a cap-exempt AI
     * run (real spend), so it is gated to the bot's OWNER — not any workspace member —
     * consistent with the other mutating abilities.
     */
    public function retry(?User $user, Bot $bot): bool
    {
        return $this->ownsOrManagesSystemRecord($bot, $user);
    }
}
