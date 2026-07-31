<?php

namespace App\Modules\Bot\Tools;

use App\Modules\Bot\Services\BotTaskInteractionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

/**
 * fill_form(answers): persist the bot's answers to the task's attached form. Only
 * exposed when the task has a form. `answers` travels as a JSON object string keyed by
 * the form fields — see schema() for why it cannot be a declared object.
 */
class FillFormTool implements Tool
{
    public function __construct(
        private BotTaskInteractionService $interaction,
    ) {}

    public function description(): Stringable|string
    {
        return 'Wypełnia formularz przypięty do zadania. Przekaż odpowiedzi jako obiekt JSON: '
            . 'klucz = identyfikator pola formularza, wartość = odpowiedź.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->interaction->fillForm($this->decodeAnswers($request['answers'] ?? null));
    }

    /**
     * A JSON object string from the model, or an already-decoded map (the scripted test
     * agent and any non-OpenAI gateway hand the tool the array directly). Malformed JSON
     * raises an instructive tool error so the agent can correct itself within the run
     * rather than the whole run dying.
     *
     * @return array<string, mixed>
     */
    private function decodeAnswers(mixed $answers): array
    {
        if (is_array($answers)) {
            return $answers;
        }

        if (!is_string($answers) || trim($answers) === '') {
            return [];
        }

        $decoded = json_decode($answers, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Nie udało się odczytać odpowiedzi: `answers` musi być poprawnym obiektem JSON, '
                . 'np. {"pole-1": "wartość"}.'
            );
        }

        return $decoded;
    }

    /**
     * The answers are a JSON STRING, not a declared object, because the form's fields are
     * dynamic per task while OpenAI's strict function calling requires every object in the
     * schema to enumerate its `properties` AND set `additionalProperties: false`. A
     * free-form `object()` (no properties) fails that validation and 400s the ENTIRE run —
     * the same class of failure an empty schema causes (see FinishTool::schema()).
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'answers' => $schema->string()
                ->description(
                    'Odpowiedzi jako obiekt JSON: klucz = identyfikator pola formularza, wartość = odpowiedź '
                    . '(tekst, liczba lub lista dla pól wielokrotnego wyboru). '
                    . 'Przykład: {"pole-1":"Tak","pole-2":["a","b"]}.'
                )
                ->required(),
        ];
    }
}
