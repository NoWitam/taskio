<?php

namespace App\Modules\Bot\Services;

use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Bot\Models\Bot;
use App\Modules\Knowledge\DTOs\CompiledKnowledge;
use App\Modules\Tasks\Models\Task;

/**
 * Builds the READ context injected into the bot's execution agent (context injection,
 * NOT tools). Everything the bot needs to reason about the task is gathered here so it
 * is testable in isolation:
 *
 *   - the bot's KNOWLEDGE — either a bound knowledge base or its own built-in module,
 *   - the task (title / description),
 *   - the attached form schema AND the current submission state (if any),
 *   - the conversation: the most recent N task comments (config ai.context_comment_limit,
 *     default 30), oldest-first so the agent reads the thread in order,
 *   - the approval run history INCLUDING rejection notes/reasons, so a revision run
 *     knows what to fix.
 *
 * The class stays PURE: it reads models and returns a string, and it never spends. The
 * knowledge-base read is the one part of the context that can cost an AI call, so it is
 * performed by {@see BotKnowledgeReader} and handed IN, already compiled. That keeps the
 * spend at the caller (which also records the audit for it) and keeps this builder callable
 * from anywhere without wondering whether building a context bills a workspace.
 */
class BotTaskContextBuilder
{
    /**
     * $knowledge is the compiled knowledge base, when the bot is bound to one — see
     * {@see knowledgeSection()} for what happens when it is absent.
     */
    public function build(Task $task, ?Bot $bot = null, ?CompiledKnowledge $knowledge = null): string
    {
        $sections = [
            $this->knowledgeSection($bot, $knowledge),
            $this->taskSection($task),
            $this->formSection($task),
            $this->commentsSection($task),
            $this->approvalHistorySection($task),
        ];

        return implode("\n\n", array_filter($sections));
    }

    /**
     * The bot's knowledge, from whichever of the two sources it has.
     *
     * A compiled KNOWLEDGE BASE wins when one was read: it is already a labelled, fenced DATA
     * block (the Knowledge module frames and scrubs it), so it is injected verbatim — wrapping
     * it in a second frame here would let this module's copy of the framing drift from the one
     * every other consumer gets.
     *
     * With no bound base the LEGACY path runs, and it is deliberately untouched: same cap, same
     * prose, same truncation marker, byte for byte. That is what makes B6 safe to ship — a bot
     * nobody has migrated behaves exactly as it did yesterday, and the difference is pinned by a
     * frozen-fixture test rather than by inspection.
     */
    private function knowledgeSection(?Bot $bot, ?CompiledKnowledge $knowledge): string
    {
        if ($knowledge !== null) {
            return $knowledge->text;
        }

        return $this->legacyKnowledgeSection($bot);
    }

    /**
     * The bot's built-in knowledge module rendered as a context block. Empty knowledge (or no
     * bot) omits the section. Total injected knowledge is capped (config ai.knowledge_max_chars,
     * default 8000) so a large knowledge base can't blow the context window.
     */
    private function legacyKnowledgeSection(?Bot $bot): string
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
