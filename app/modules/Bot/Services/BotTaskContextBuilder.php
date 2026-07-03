<?php

namespace App\Modules\Bot\Services;

use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Bot\Models\Bot;
use App\Modules\Tasks\Models\Task;

/**
 * Builds the READ context injected into the bot's execution agent (context injection,
 * NOT tools). Everything the bot needs to reason about the task is gathered here so it
 * is testable in isolation:
 *
 *   - the bot's KNOWLEDGE module entries (B6) — so it reasons with its own knowledge,
 *   - the task (title / description),
 *   - the attached form schema AND the current submission state (if any),
 *   - the conversation: the most recent N task comments (config ai.context_comment_limit,
 *     default 30), oldest-first so the agent reads the thread in order,
 *   - the approval run history INCLUDING rejection notes/reasons, so a revision run
 *     knows what to fix.
 */
class BotTaskContextBuilder
{
    public function build(Task $task, ?Bot $bot = null): string
    {
        $sections = [
            $this->knowledgeSection($bot),
            $this->taskSection($task),
            $this->formSection($task),
            $this->commentsSection($task),
            $this->approvalHistorySection($task),
        ];

        return implode("\n\n", array_filter($sections));
    }

    /**
     * The bot's knowledge module rendered as a context block. Empty knowledge (or no bot)
     * omits the section. Total injected knowledge is capped (config ai.knowledge_max_chars,
     * default 8000) so a large knowledge base can't blow the context window.
     */
    private function knowledgeSection(?Bot $bot): string
    {
        // Only inject when the knowledge module is explicitly ENABLED (an off module is
        // inert even if it still holds entries).
        if ($bot === null || !$bot->knowledgeEnabled()) {
            return '';
        }

        $entries = $bot->knowledgeEntries();

        if ($entries === []) {
            return '';
        }

        $maxChars = (int) config('ai.knowledge_max_chars', 8000);

        $body = '';
        foreach ($entries as $entry) {
            $line = "- {$entry['title']}: {$entry['content']}\n";

            // Stop before exceeding the cap; note the truncation so the model knows more exists.
            if (mb_strlen($body) + mb_strlen($line) > $maxChars) {
                $body .= "- […] (pominięto część wiedzy z powodu limitu)\n";
                break;
            }

            $body .= $line;
        }

        return "WIEDZA BOTA:\n" . rtrim($body);
    }

    private function taskSection(Task $task): string
    {
        $description = filled($task->description) ? $task->description : 'brak';

        return "ZADANIE:\nTytuł: {$task->title}\nOpis: {$description}";
    }

    private function formSection(Task $task): string
    {
        $task->loadMissing('form', 'formSubmission');

        if (!$task->form) {
            return 'FORMULARZ: brak.';
        }

        $schema = json_encode($task->form->content, JSON_UNESCAPED_UNICODE);
        $submission = $task->formSubmission
            ? json_encode($task->formSubmission->data, JSON_UNESCAPED_UNICODE)
            : 'jeszcze niewypełniony';

        return "FORMULARZ (schemat): {$schema}\nAKTUALNE ODPOWIEDZI: {$submission}";
    }

    private function commentsSection(Task $task): string
    {
        $limit = (int) config('ai.context_comment_limit', 30);

        // Most recent N, then re-ordered oldest-first so the thread reads naturally.
        $comments = $task->comments()
            ->with('author')
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->reverse();

        if ($comments->isEmpty()) {
            return 'KOMENTARZE (rozmowa): brak.';
        }

        $lines = $comments->map(function ($comment) {
            $author = $comment->author?->name ?? 'Nieznany';
            $who = $comment->author_type === 'bot' ? "{$author} (bot)" : $author;
            $text = is_string($comment->content) ? $comment->content : json_encode($comment->content, JSON_UNESCAPED_UNICODE);

            return "- {$who}: {$text}";
        })->implode("\n");

        return "KOMENTARZE (rozmowa, najnowsze {$limit}):\n{$lines}";
    }

    private function approvalHistorySection(Task $task): string
    {
        $processes = $task->approvalProcesses()
            ->with('stage')
            ->orderBy('created_at')
            ->get();

        if ($processes->isEmpty()) {
            return 'HISTORIA ZATWIERDZANIA: brak.';
        }

        $lines = $processes->map(function ($process) {
            $stage = $process->stage?->name ?? 'etap';
            $status = $process->status->value;
            $note = filled($process->note) ? " — uwagi: {$process->note}" : '';

            // Surface rejection reasons prominently — a revision run must address them.
            $prefix = $process->status === ApprovalProcessStatus::Rejected ? 'ODRZUCONO' : $status;

            return "- [{$prefix}] etap \"{$stage}\"{$note}";
        })->implode("\n");

        return "HISTORIA ZATWIERDZANIA (uwzględnij powody odrzuceń):\n{$lines}";
    }
}
