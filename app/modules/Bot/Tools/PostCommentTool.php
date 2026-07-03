<?php

namespace App\Modules\Bot\Tools;

use App\Modules\Bot\Services\BotTaskInteractionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * post_comment(text): post a comment authored by the bot. Usable at any time, any
 * number of times — clarifications, status notes, summaries — even when a form is
 * attached.
 */
class PostCommentTool implements Tool
{
    public function __construct(
        private BotTaskInteractionService $interaction,
    ) {}

    public function description(): Stringable|string
    {
        return 'Publikuje komentarz do zadania w imieniu bota. Używaj dowolnie: do wyjaśnień, notatek o postępie lub podsumowań.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->interaction->postComment((string) ($request['text'] ?? ''));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()
                ->description('Treść komentarza w stylu persony bota.')
                ->required(),
        ];
    }
}
