<?php

namespace App\Modules\Bot\Agents;

use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Services\BotTaskInteractionService;
use App\Modules\Bot\Services\BotTaskToolFactory;
use App\Modules\Tasks\Models\Task;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Interactive task-execution agent (B4). The bot READS its context (task, form +
 * submission, conversation, approval history — built by BotTaskContextBuilder and
 * injected into the instructions) and ACTS through tools:
 *
 *   - post_comment  — always available,
 *   - fill_form     — only when the task has an attached form,
 *   - ask_and_wait  — ask a human and end the run,
 *   - finish        — submit to in_test (fails if an attached form is unfilled).
 *
 * Multi-step (#[MaxSteps]) so the agent can chain tools within one run.
 */
#[MaxSteps(12)]
class BotTaskExecutionAgent implements Agent, HasTools
{
    use Promptable;

    public function __construct(
        private Bot $bot,
        private Task $task,
        private string $context,
        private BotTaskInteractionService $interaction,
    ) {}

    public function instructions(): Stringable|string
    {
        $persona = $this->bot->persona ?: 'Brak zdefiniowanej persony.';
        $style = $this->bot->style ?: 'Brak zdefiniowanego stylu.';
        $dictionary = $this->renderDictionary($this->bot->dictionaryEntries());
        $phrases = $this->renderPhrases($this->bot->phraseEntries());
        $prohibitions = $this->joinList($this->bot->prohibitions);

        $formRule = $this->task->form_id
            ? 'To zadanie MA formularz — wypełnij go narzędziem fill_form ZANIM wywołasz finish.'
            : 'To zadanie nie ma formularza.';

        $registrySection = $this->registrySection();

        return <<<INSTRUCTIONS
        Jesteś botem uczestniczącym w realizacji zadania. Działasz w turach przy użyciu narzędzi.

        PERSONA:
        {$persona}

        STYL:
        {$style}

        SŁOWNIK (preferowane terminy): {$dictionary}
        FRAZY (używaj gdy pasują): {$phrases}
        ZAKAZY (nigdy nie używaj / nie rób): {$prohibitions}

        KONTEKST:
        {$this->context}

        NARZĘDZIA I ZASADY:
        - post_comment: publikuj komentarze (wyjaśnienia, postęp, podsumowania) dowolnie.
        - fill_form: wypełnij formularz (jeśli jest). {$formRule}
        - ask_and_wait: gdy potrzebujesz odpowiedzi CZŁOWIEKA — zadaj pytanie i zakończ turę.
        - finish: gdy praca jest gotowa — prześlij zadanie do testów.{$registrySection}
        - Przestrzegaj zakazów i preferowanego słownictwa.
        INSTRUCTIONS;
    }

    /** @return iterable<\Laravel\Ai\Contracts\Tool> */
    public function tools(): iterable
    {
        return app(BotTaskToolFactory::class)->forRun(
            $this->bot,
            $this->task,
            $this->interaction,
            app(\App\Modules\Bot\Services\BotActionService::class),
        );
    }

    /** One-line purpose for each granted registry tool, so the model knows its options. */
    private function registrySection(): string
    {
        $descriptions = [
            'fetch_url' => 'fetch_url: pobierz treść publicznej strony WWW.',
            'web_search' => 'web_search: wyszukaj informacje w internecie.',
            'generate_file' => 'generate_file: utwórz plik tekstowy i dołącz go do zadania.',
            'read_attachments' => 'read_attachments: przeczytaj załączniki tego zadania.',
        ];

        $granted = app(BotTaskToolFactory::class)->grantedRegistryIds($this->bot);

        if ($granted === []) {
            return '';
        }

        $lines = array_map(fn ($id) => "\n        - " . ($descriptions[$id] ?? $id), $granted);

        return implode('', $lines);
    }

    private function joinList(?array $values): string
    {
        return empty($values) ? 'brak' : implode(', ', $values);
    }

    /**
     * Render dictionary entries as `term — meaning` lines.
     *
     * @param  array<int, array{term: string, meaning: string}>  $entries
     */
    private function renderDictionary(array $entries): string
    {
        if ($entries === []) {
            return 'brak';
        }

        return implode('; ', array_map(
            fn ($e) => filled($e['meaning']) ? "{$e['term']} — {$e['meaning']}" : $e['term'],
            $entries
        ));
    }

    /**
     * Render phrase entries as `phrase (kontekst: …)` lines (context omitted when empty).
     *
     * @param  array<int, array{phrase: string, context: string|null}>  $entries
     */
    private function renderPhrases(array $entries): string
    {
        if ($entries === []) {
            return 'brak';
        }

        return implode('; ', array_map(
            fn ($e) => filled($e['context']) ? "{$e['phrase']} (kontekst: {$e['context']})" : $e['phrase'],
            $entries
        ));
    }
}
