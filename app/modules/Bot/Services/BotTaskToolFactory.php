<?php

namespace App\Modules\Bot\Services;

use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Tools\AskAndWaitTool;
use App\Modules\Bot\Tools\BotToolContext;
use App\Modules\Bot\Tools\FillFormTool;
use App\Modules\Bot\Tools\FinishTool;
use App\Modules\Bot\Tools\PostCommentTool;
use App\Modules\Tasks\Models\Task;
use Laravel\Ai\Contracts\Tool;

/**
 * Builds the FULL tool set for a run: the four ALWAYS-present interaction tools plus the
 * OPTIONAL registry tools the bot was granted and that are available. Shared by the real
 * agent AND the scripted test double so both expose an IDENTICAL set — there is exactly
 * one place that decides which tools a run has.
 */
class BotTaskToolFactory
{
    public function __construct(
        private BotToolRegistry $registry,
    ) {}

    /**
     * @return array<int, Tool>
     */
    public function forRun(Bot $bot, Task $task, BotTaskInteractionService $interaction, BotActionService $actions): array
    {
        return array_values($this->forRunKeyed($bot, $task, $interaction, $actions));
    }

    /**
     * The run's tools keyed by their tool NAME (post_comment, finish, fetch_url, …).
     * Used by the agent (values) and the scripted test double (name lookup) so both
     * resolve the SAME tools.
     *
     * @return array<string, Tool>
     */
    public function forRunKeyed(Bot $bot, Task $task, BotTaskInteractionService $interaction, BotActionService $actions): array
    {
        $ctx = new BotToolContext($bot, $task, $interaction, $actions);

        // Core interaction tools (always present). fill_form only when a form exists.
        $tools = [
            'post_comment' => new PostCommentTool($interaction),
            'ask_and_wait' => new AskAndWaitTool($interaction),
            'finish' => new FinishTool($interaction),
        ];

        if ($task->form_id) {
            $tools['fill_form'] = new FillFormTool($interaction);
        }

        // Optional registry tools the bot granted and that are available (keyed by id).
        foreach ($this->registry->grantedAvailableIds($bot) as $id) {
            $tools[$id] = $this->registry->makeById($id, $ctx);
        }

        return $tools;
    }

    /**
     * The granted, available REGISTRY tool ids for this bot — used to name them in the
     * agent instructions so the model knows what it can use.
     *
     * @return array<int, string>
     */
    public function grantedRegistryIds(Bot $bot): array
    {
        return $this->registry->grantedIds($bot);
    }
}
