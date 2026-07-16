<?php

namespace App\Modules\Bot\Tools;

use App\Modules\Bot\Services\BotTaskInteractionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * ask_and_wait(question): post a question as a bot comment and END the run to wait for
 * a human reply. The task stays in_progress; the next HUMAN comment resumes the run.
 * Use this when you genuinely cannot proceed without input from a person.
 */
class AskAndWaitTool implements Tool
{
    public function __construct(
        private BotTaskInteractionService $interaction,
    ) {}

    public function description(): Stringable|string
    {
        return 'Zadaje pytanie i CZEKA na odpowiedź człowieka. Publikuje pytanie jako komentarz i kończy bieżącą turę. '
            . 'Użyj tylko gdy naprawdę nie możesz kontynuować bez odpowiedzi osoby.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->interaction->askAndWait((string) ($request['question'] ?? ''));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()
                ->description('Pytanie do człowieka, w stylu persony bota.')
                ->required(),
        ];
    }
}
