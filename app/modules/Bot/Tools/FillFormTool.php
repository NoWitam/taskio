<?php

namespace App\Modules\Bot\Tools;

use App\Modules\Bot\Services\BotTaskInteractionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * fill_form(answers): persist the bot's answers to the task's attached form. Only
 * exposed when the task has a form. `answers` is an object keyed by the form fields.
 */
class FillFormTool implements Tool
{
    public function __construct(
        private BotTaskInteractionService $interaction,
    ) {}

    public function description(): Stringable|string
    {
        return 'Wypełnia formularz przypięty do zadania. Przekaż odpowiedzi jako obiekt (klucz pola formularza => odpowiedź).';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->interaction->fillForm((array) ($request['answers'] ?? []));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'answers' => $schema->object()
                ->description('Odpowiedzi na pola formularza (klucz pola => wartość).')
                ->required(),
        ];
    }
}
