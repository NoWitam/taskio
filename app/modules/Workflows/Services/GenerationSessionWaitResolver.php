<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Generator\Services\SessionAutomationService;
use App\Modules\Workflows\Contracts\WaitResolver;
use App\Modules\Workflows\Enums\WaitStatus;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Steps\GenerateContentStep;

/**
 * Answers the waiting-run sweep's ONE question for the `generation_session` wait kind: is the generation a
 * {@see GenerateContentStep} parked on still pending, settled, or gone?
 *
 * WHY IT LIVES IN WORKFLOWS (and not in the Generator). The registry contract is designed for the FEATURE
 * module to own the concrete — but the feature here is the STEP, and the step is a Workflows class. The
 * Generator must never name Workflows (its boundary test forbids it), so the resolver lives on the side that
 * is allowed to know both: Workflows. It still asks through the Generator's narrow automation seam
 * ({@see SessionAutomationService::terminalStatusFor}, a plain status string) and reaches into no Generator
 * model — so the coupling is exactly the one edge the step already has.
 *
 * The mapping is the single source of "what counts as settled", shared with the step's own resume():
 *   ready | failed        → SETTLED  (a real outcome; resume() collects it or fails the run with the cause)
 *   generating | draft    → PENDING  (keep waiting; `workflows.wait_timeout` still bounds it)
 *   null                  → GONE     (deleted or purged — it can never settle)
 *
 * WHAT `null` MEANS IS A SCOPE QUESTION, and the sweep answers it deliberately. The shared pass CLEARS the
 * tenant context before reading, so {@see SessionAutomationService::terminalStatusFor} runs UNCONSTRAINED
 * there and null means "no such row anywhere in that database". That is intentional and is the safe
 * direction: a workspace-scoped read with no active workspace would return null for EVERY parked run and
 * mass-fail them as GONE. It is not a cross-workspace leak — the only id ever asked about is the one the
 * run's own step wrote into its wait record, and all that comes back is a status string.
 *
 * CHEAP + READ-ONLY, per the contract: one indexed primary-key lookup per parked run, no writes, and no
 * throw for an ordinary "not found" (that is GONE). A wait record with no session id is likewise GONE — the
 * step could not collect anything from it either.
 */
class GenerationSessionWaitResolver implements WaitResolver
{
    public function __construct(
        private SessionAutomationService $sessions,
    ) {}

    public function kind(): string
    {
        return GenerateContentStep::WAIT_KIND;
    }

    /**
     * @param  array<string, mixed>  $waitingOn
     */
    public function status(WorkflowRun $run, array $waitingOn): WaitStatus
    {
        $payload = is_array($waitingOn['payload'] ?? null) ? $waitingOn['payload'] : [];
        $sessionId = $payload['session_id'] ?? null;

        if (!is_string($sessionId) || $sessionId === '') {
            return WaitStatus::GONE;
        }

        return match ($this->sessions->terminalStatusFor($sessionId)) {
            'ready', 'failed' => WaitStatus::SETTLED,
            'generating', 'draft' => WaitStatus::PENDING,
            default => WaitStatus::GONE,
        };
    }
}
