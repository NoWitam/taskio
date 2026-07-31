<?php

namespace App\Modules\Bot\Tools;

use App\Modules\Bot\Services\BotTaskInteractionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * finish(): deliver the work. Lands in in_test when an approval pipeline is attached (that
 * is what starts the review) and in done when there is none. If the task has a form that
 * was not filled, this fails with an instructive error so the agent fills the form first —
 * the run must not deliver an incomplete deliverable.
 */
class FinishTool implements Tool
{
    public function __construct(
        private BotTaskInteractionService $interaction,
    ) {}

    public function description(): Stringable|string
    {
        return 'Kończy pracę nad zadaniem. Jeśli zadanie ma przypiętą akceptację, trafia do testów '
            . 'i uruchamia proces akceptacji; jeśli nie ma — zostaje oznaczone jako zrobione. '
            . 'Jeśli zadanie ma formularz, wypełnij go PRZED zakończeniem.';
    }

    public function handle(Request $request): Stringable|string
    {
        $summary = $request['summary'] ?? null;

        return $this->interaction->finish(
            is_string($summary) && trim($summary) !== '' ? trim($summary) : null
        );
    }

    /**
     * `finish` needs no arguments, but it may NOT declare an empty schema: laravel/ai
     * omits `parameters` entirely for one while still sending `strict: true`, and OpenAI
     * rejects that whole request — "Invalid schema for function 'FinishTool': ...
     * 'additionalProperties' is required to be supplied and to be false" — which fails
     * the ENTIRE run, not just the tool call.
     *
     * So the tool carries one nullable+required property (the same shape the Approvals
     * tools use for their argument-free calls). It stays optional in practice, and the
     * summary is not dead weight: it is recorded on the `submitted_to_test` action.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()
                ->description('Krótkie podsumowanie wykonanej pracy. Opcjonalne — może być null.')
                ->nullable()
                ->required(),
        ];
    }
}
