<?php

namespace App\Modules\Bot\Services;

use App\Modules\Bot\Enums\BotTool;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Tools\BotToolContext;
use App\Modules\Bot\Tools\Registry\FetchUrlTool;
use App\Modules\Bot\Tools\Registry\GenerateFileTool;
use App\Modules\Bot\Tools\Registry\ReadAttachmentsTool;
use App\Modules\Bot\Tools\Registry\WebSearchTool;
use App\Modules\Bot\Tools\Support\SafeUrlGuard;
use App\Modules\Bot\Tools\Support\SearchProvider;
use Laravel\Ai\Contracts\Tool;

/**
 * The bot tool registry (B5). Single source of truth for the OPTIONAL task-execution
 * tools. Each entry knows:
 *   - its id (BotTool),
 *   - whether it is AVAILABLE (some tools need config, e.g. web_search needs a key),
 *   - how to BUILD the tool bound to a run's context.
 *
 * A tool is exposed to the agent only when the bot GRANTED it (task_execution.tools[])
 * AND it is available — so there are never dead options (the user's hard requirement).
 */
class BotToolRegistry
{
    public function __construct(
        private SearchProvider $search,
        private SafeUrlGuard $urlGuard,
    ) {}

    /** Whether a registry tool is available in the current environment. */
    public function isAvailable(BotTool $tool): bool
    {
        return match ($tool) {
            BotTool::WebSearch => $this->search->isAvailable(),
            default => true,
        };
    }

    /**
     * Discovery list for the FE editor: every registry id + its availability.
     *
     * @return array<int, array{id: string, available: bool}>
     */
    public function catalog(): array
    {
        return array_map(fn (BotTool $tool) => [
            'id' => $tool->value,
            'available' => $this->isAvailable($tool),
        ], BotTool::cases());
    }

    /**
     * The granted, available registry tool ids for a bot (string values). Unknown,
     * unavailable and non-granted ids are excluded.
     *
     * @return array<int, string>
     */
    public function grantedAvailableIds(Bot $bot): array
    {
        $granted = (array) ($bot->task_execution['tools'] ?? []);

        $ids = [];
        foreach ($granted as $id) {
            $tool = BotTool::tryFrom((string) $id);

            if ($tool !== null && $this->isAvailable($tool)) {
                $ids[] = $tool->value;
            }
        }

        return $ids;
    }

    /** Alias kept for the agent instructions. */
    public function grantedIds(Bot $bot): array
    {
        return $this->grantedAvailableIds($bot);
    }

    /** Build a registry tool by its string id, bound to the run context. */
    public function makeById(string $id, BotToolContext $ctx): Tool
    {
        return $this->make(BotTool::from($id), $ctx);
    }

    private function make(BotTool $tool, BotToolContext $ctx): Tool
    {
        return match ($tool) {
            BotTool::FetchUrl => new FetchUrlTool($ctx, $this->urlGuard),
            BotTool::WebSearch => new WebSearchTool($ctx, $this->search),
            BotTool::GenerateFile => new GenerateFileTool($ctx),
            BotTool::ReadAttachments => new ReadAttachmentsTool($ctx),
        };
    }
}
