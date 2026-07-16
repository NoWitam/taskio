<?php

namespace App\Modules\Bot\Tools;

use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Services\BotActionService;
use App\Modules\Bot\Services\BotTaskInteractionService;
use App\Modules\Tasks\Models\Task;

/**
 * Carries everything a run's tools are bound to: the bot, the task, the interaction
 * service (core tools) and the action recorder (registry-tool audit). Passed to the
 * agent and its test double so both build IDENTICAL tool sets via BotToolRegistry.
 */
class BotToolContext
{
    public function __construct(
        public readonly Bot $bot,
        public readonly Task $task,
        public readonly BotTaskInteractionService $interaction,
        public readonly BotActionService $actions,
    ) {}
}
