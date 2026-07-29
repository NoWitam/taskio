<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Variables\Contracts\AiTextGenerator;
use App\Modules\Variables\Services\AiTextGenerationService;
use Illuminate\Support\Facades\Log;

/**
 * The Workflows-side implementation of the Variables {@see AiTextGenerator} contract (bound in the
 * Workflows provider) for an `@[ai-text]` directive (SB2). It is now a THIN DECORATOR: it keeps the
 * PER-RUN call-count budget that is unique to a workflow run and delegates the actual generation
 * (agent call + trim/truncate + fail-closed + cost metering) to the shared
 * {@see AiTextGenerationService} promoted down into the Variables layer.
 *
 * It:
 *   - refuses a blank prompt (no wasted call, no budget spent),
 *   - enforces a PER-RUN call budget so one run can't fan out into unbounded AI spend,
 *   - then delegates to the shared service with the workflow-field length cap + purpose hint.
 *
 * BUDGET SCOPE: the call counter is INSTANCE state, and the instance is SCOPED (one per job/request,
 * flushed at every queue-job boundary) so the step runner and the resolver share exactly one counter
 * for a pass. Instance freshness alone is NOT the scoping rule any more: {@see WorkflowStepRunner}
 * SETS the counter at the start of every pass — 0 for a first pass, and the count persisted at
 * suspend for a resumed one — and restores the outer value afterwards, so a run that suspends does
 * not get the cap twice over and a nested child run cannot spend its parent's budget.
 * Nothing about the prompt (which may carry form values) is ever logged; only the fact of a budget hit.
 */
class WorkflowAiTextService implements AiTextGenerator
{
    /**
     * The lead instruction wording for a workflow field — passed verbatim as the shared agent's
     * purpose hint so the instruction this run produces is byte-identical to the pre-down-move agent.
     */
    private const PURPOSE_HINT = "a field of an automated workflow\n(for example a task title, a task description, a report name, or report guidelines)";

    /** AI calls made so far in this run (see BUDGET SCOPE above). */
    private int $calls = 0;

    public function __construct(
        private AiTextGenerationService $generator,
    ) {}

    /**
     * Generate the text for one resolved ai-text prompt, or '' (fail-closed) on a blank prompt or an
     * exhausted per-run budget. Anything past the budget guard is delegated to the shared service,
     * which itself trims, meters, length-caps, and fails closed on any provider error.
     */
    public function generate(string $prompt, ?string $personaId): string
    {
        if (trim($prompt) === '') {
            return '';
        }

        if ($this->calls >= $this->maxCalls()) {
            Log::warning('Workflow ai-text: per-run call budget reached; directive resolved to empty.');

            return '';
        }

        $this->calls++;

        return $this->generator->generate(
            $prompt,
            $personaId,
            (int) config('workflows.ai_text_max_chars', 2000),
            self::PURPOSE_HINT,
        );
    }

    /**
     * Calls made so far under the current counter — read by the step runner when a step suspends, so
     * the count can ride on the run and the per-RUN cap survives into the resumed pass.
     */
    public function callsMade(): int
    {
        return $this->calls;
    }

    /**
     * SET the counter (the suspend/resume + nesting seam; see BUDGET SCOPE). Called only by the step
     * runner: with the count persisted at suspend when re-entering a parked run, with 0 when a pass
     * starts fresh, and with the saved outer value when an in-process nested run finishes.
     */
    public function restoreCalls(int $calls): void
    {
        $this->calls = max(0, $calls);
    }

    /** The per-run ai-text call ceiling (cost guard); config-driven. */
    private function maxCalls(): int
    {
        return (int) config('workflows.ai_text_max_calls_per_run', 10);
    }
}
