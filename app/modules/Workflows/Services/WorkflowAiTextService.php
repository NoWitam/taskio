<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\Agents\WorkflowAiTextAgent;
use App\Modules\Workflows\Enums\WorkflowAiPersona;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the AI-TEXT generation for an `@[ai-text]` directive (SB2) — the fail-closed, budgeted seam
 * between WorkflowVariableResolver and WorkflowAiTextAgent. It:
 *
 *   - refuses a blank prompt (no wasted call),
 *   - enforces a PER-RUN call budget so one run can't fan out into unbounded AI spend,
 *   - runs the tool-less agent with provider/model from config('ai'),
 *   - trims and length-caps the result,
 *   - and NEVER throws: any provider/transport failure (missing key, timeout, exception) resolves
 *     to '' — the exact fail-closed contract the resolver's other directives already follow, so a
 *     blank REQUIRED title still hard-fails the run honestly while a blank description is just empty.
 *
 * BUDGET SCOPE: the call counter is INSTANCE state. The service is resolved fresh together with the
 * resolver for each WorkflowRunJob (neither is a container singleton), so the counter naturally
 * scopes to one run — do NOT bind this as a singleton. Nothing about the prompt (which may carry
 * form values) is ever logged; only the fact of a failure / budget hit.
 */
class WorkflowAiTextService
{
    /** AI calls made so far in this run (see BUDGET SCOPE above). */
    private int $calls = 0;

    /**
     * Generate the text for one resolved ai-text prompt, or '' (fail-closed) on a blank prompt, an
     * exhausted per-run budget, or any agent failure. $persona colors the tone only.
     */
    public function generate(string $prompt, WorkflowAiPersona $persona): string
    {
        $prompt = trim($prompt);

        if ($prompt === '') {
            return '';
        }

        if ($this->calls >= $this->maxCalls()) {
            Log::warning('Workflow ai-text: per-run call budget reached; directive resolved to empty.');

            return '';
        }

        $this->calls++;

        try {
            $response = (new WorkflowAiTextAgent($persona))->prompt(
                prompt: $prompt,
                provider: config('ai.provider'),
                model: config('ai.model'),
            );

            $text = trim((string) ($response->text ?? ''));
        } catch (Throwable $e) {
            Log::warning('Workflow ai-text generation failed: ' . $e->getMessage());

            return '';
        }

        return $this->truncate($text);
    }

    /** The per-run ai-text call ceiling (cost guard); config-driven. */
    private function maxCalls(): int
    {
        return (int) config('workflows.ai_text_max_calls_per_run', 10);
    }

    /**
     * Length-cap the generated text to the configured maximum (multibyte-safe). Field-specific DB
     * limits still apply on top of this (e.g. a task title column) — keep title prompts concise.
     */
    private function truncate(string $text): string
    {
        $max = (int) config('workflows.ai_text_max_chars', 2000);

        return $max > 0 && mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }
}
