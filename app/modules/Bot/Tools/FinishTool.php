<?php

namespace App\Modules\Bot\Tools;

use App\Modules\Bot\Services\BotTaskInteractionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * finish(): submit the task to in_test (auto-starts an attached approval pipeline).
 * If the task has a form that was not filled, this fails with an instructive error so
 * the agent fills the form first — the run must not submit an incomplete deliverable.
 */
class FinishTool implements Tool
{
    public function __construct(
        private BotTaskInteractionService $interaction,
    ) {}

    public function description(): Stringable|string
    {
        return 'Kończy pracę i przesyła zadanie do testów (uruchamia pipeline zatwierdzania, jeśli jest przypięty). '
            . 'Jeśli zadanie ma formularz, wypełnij go PRZED zakończeniem.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->interaction->finish();
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
