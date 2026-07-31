<?php

namespace App\Modules\Bot\Services;

use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Bot\Models\Bot;
use App\Modules\Comments\DTOs\CommentDTO;
use App\Modules\Comments\Services\CommentService;
use App\Modules\Forms\DTOs\FormSubmissionDTO;
use App\Modules\Forms\Services\FormSubmissionService;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\TaskService;
use RuntimeException;

/**
 * Performs the side-effects of the bot's interactive tools (post_comment, fill_form,
 * ask_and_wait, finish) for a SINGLE run. The agent tools are thin adapters over this
 * service, so the run's behavior is testable without a live provider (tests invoke the
 * tools, which call these methods).
 *
 * The service tracks a terminal OUTCOME for the run so the job knows how it ended:
 *   - null      : run continues (agent may call more tools),
 *   - Waiting   : ask_and_wait fired — end the run, task stays in_progress + waiting,
 *   - Finished  : finish fired — task submitted to in_test (approval attached) or moved
 *                 straight to done (no approval to wait for).
 */
class BotTaskInteractionService
{
    public const OUTCOME_WAITING = 'waiting';

    public const OUTCOME_FINISHED = 'finished';

    private ?string $outcome = null;

    private bool $formFilled = false;

    public function __construct(
        private Bot $bot,
        private Task $task,
        private CommentService $comments,
        private FormSubmissionService $submissions,
        private TaskService $tasks,
        private BotActionService $actions,
    ) {}

    public function outcome(): ?string
    {
        return $this->outcome;
    }

    /** Whether the run should stop invoking tools (a terminal tool fired). */
    public function isDone(): bool
    {
        return $this->outcome !== null;
    }

    /** Post a comment authored by the bot. Usable any time, any number of times. */
    public function postComment(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            throw new RuntimeException('Komentarz nie może być pusty.');
        }

        $this->comments->create($this->task, CommentDTO::fromBot($this->bot, $text));
        $this->actions->record($this->bot, $this->task, BotActionType::Commented);

        return 'Komentarz opublikowany.';
    }

    /** Persist form answers (only meaningful when the task has an attached form). */
    public function fillForm(array $answers): string
    {
        if (!$this->task->form_id) {
            throw new RuntimeException('To zadanie nie ma formularza do wypełnienia.');
        }

        if ($answers === []) {
            throw new RuntimeException('Odpowiedzi formularza nie mogą być puste.');
        }

        $this->task->loadMissing('formSubmission');

        if ($this->task->formSubmission) {
            $submission = $this->submissions->update($this->task->formSubmission, $answers);
        } else {
            $submission = $this->submissions->create(new FormSubmissionDTO(
                form_id: $this->task->form_id,
                submittable_type: $this->task->getMorphClass(),
                submittable_id: $this->task->getKey(),
                data: $answers,
            ));
        }

        // Keep the long-lived run Task in sync: its `formSubmission` was eager-loaded as
        // null by the context builder before this call created the row, and everything
        // downstream in the run (finish's unfilled-form guard) reads that relation.
        $this->task->setRelation('formSubmission', $submission);

        $this->formFilled = true;
        $this->actions->record($this->bot, $this->task, BotActionType::FormFilled);

        return 'Formularz wypełniony.';
    }

    /**
     * Ask a question and wait for a human reply: post the question as a bot comment,
     * record it, and end the run. The task stays in_progress; the run-state is flipped
     * to waiting by the job so the next HUMAN comment resumes the run.
     */
    public function askAndWait(string $question): string
    {
        $question = trim($question);

        if ($question === '') {
            throw new RuntimeException('Pytanie nie może być puste.');
        }

        $this->comments->create($this->task, CommentDTO::fromBot($this->bot, $question));
        $this->actions->record($this->bot, $this->task, BotActionType::QuestionAsked, [
            'question' => $question,
        ]);

        $this->outcome = self::OUTCOME_WAITING;

        return 'Pytanie zadane; oczekiwanie na odpowiedź człowieka.';
    }

    /**
     * End the run with the work delivered. Where the task LANDS depends on whether anyone
     * is going to review it:
     *
     *  - approval pipeline attached -> in_test, which is what STARTS the review (the task
     *    is reassigned to the first stage approver),
     *  - no pipeline               -> done, because in_test would mean waiting for a
     *    review that nobody is going to perform — the task would sit there until a human
     *    clicked "done" for no reason.
     *
     * If the task has a form that was never filled (this run or previously), finish FAILS
     * with an instructive tool-error so the agent fills the form first — we never deliver
     * an incomplete deliverable either way.
     */
    public function finish(?string $summary = null): string
    {
        if ($this->task->form_id && !$this->formFilled) {
            $this->task->loadMissing('formSubmission');

            if (!$this->task->formSubmission) {
                throw new RuntimeException(
                    'Nie można zakończyć: zadanie ma formularz, który nie został wypełniony. Najpierw użyj narzędzia fill_form.'
                );
            }
        }

        $payload = $summary !== null ? ['summary' => $summary] : [];
        $this->outcome = self::OUTCOME_FINISHED;

        if (!$this->task->hasApprovalPipeline()) {
            $this->tasks->botComplete($this->task);
            $this->actions->record($this->bot, $this->task, BotActionType::MarkedDone, $payload);

            return 'Zadanie zakończone i oznaczone jako zrobione (brak przypiętej akceptacji).';
        }

        $this->tasks->botSubmitToTest($this->task);
        $this->actions->record($this->bot, $this->task, BotActionType::SubmittedToTest, $payload);

        return 'Zadanie przesłane do testów — uruchomiono akceptację.';
    }
}
