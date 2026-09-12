<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Publishing\Services\PublicationAutomationService;
use App\Modules\Workflows\Contracts\WaitResolver;
use App\Modules\Workflows\Enums\WaitStatus;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Steps\PublishStep;

/**
 * Answers the waiting-run sweep's ONE question for the `publication` wait kind: is the publication a
 * {@see PublishStep} parked on still pending, settled, or gone?
 *
 * WHY IT LIVES IN WORKFLOWS (and not in Publishing) — the same reason
 * {@see GenerationSessionWaitResolver} does. The registry contract's default posture is that the FEATURE
 * module owns the concrete, but the feature here is the STEP, and the step is a Workflows class.
 * Publishing must never name Workflows (its boundary test forbids it, and the thing it would have to name
 * is the run waiting on it), so the resolver lives on the side that is allowed to know both. It still
 * asks through the narrow automation seam
 * ({@see PublicationAutomationService::outcomeFor()}, a status plus one boolean) and reaches into no
 * Publishing model.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE MAPPING, AND THE ONE ENTRY THAT IS THE WHOLE POINT OF THE FILE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *   concluded   → SETTLED  published, failed, blocked, or a review that refused it. resume() collects
 *                          the first and fails the run with the cause for the rest.
 *   anything    → PENDING  a draft under review, an armed row waiting for its minute, a publish in
 *   else                   flight — AND `needs_reconcile`.
 *   null        → GONE     no such publication; it can never reach an outcome.
 *
 * `needs_reconcile` IS PENDING, DELIBERATELY, and nothing in the sweep may be "improved" to treat it
 * otherwise. It means the module does not know whether a post exists: reporting SETTLED would wake the
 * run to conclude something from a non-answer, and reporting GONE would fail a run whose publication may
 * be live on somebody's timeline. So the run keeps waiting until a reconciliation probe or a person
 * resolves it — and if nobody ever does, `workflows.wait_timeout` ends the run rather than the sweep
 * waking it every five minutes forever. PENDING is what makes that true: the sweep dispatches a resume
 * ONLY for SETTLED, so a parked publication costs one cheap read per pass and no jobs at all.
 *
 * WHAT `null` MEANS IS A SCOPE QUESTION, and the sweep answers it deliberately. The shared pass CLEARS
 * the tenant context before reading, so the seam runs UNCONSTRAINED there and null means "no such row
 * anywhere in that database". That is intentional and is the safe direction: a workspace-scoped read with
 * no active workspace would return null for EVERY parked run and mass-fail them as GONE. It is not a
 * cross-workspace leak — the only id ever asked about is the one the run's own step wrote into its wait
 * record, and all that comes back is a status and a boolean.
 *
 * CHEAP + READ-ONLY, per the contract: one indexed primary-key lookup per parked run (plus two small
 * approval reads for a DRAFT only), no writes, and no throw for an ordinary "not found" — that is GONE.
 * A wait record with no publication id is likewise GONE: the step could not collect anything from it
 * either.
 */
class PublicationWaitResolver implements WaitResolver
{
    public function __construct(
        private PublicationAutomationService $publications,
    ) {}

    public function kind(): string
    {
        return PublishStep::WAIT_KIND;
    }

    /**
     * @param  array<string, mixed>  $waitingOn
     */
    public function status(WorkflowRun $run, array $waitingOn): WaitStatus
    {
        $payload = is_array($waitingOn['payload'] ?? null) ? $waitingOn['payload'] : [];
        $publicationId = $payload['publication_id'] ?? null;

        if (!is_string($publicationId) || $publicationId === '') {
            return WaitStatus::GONE;
        }

        $outcome = $this->publications->outcomeFor($publicationId);

        if ($outcome === null) {
            return WaitStatus::GONE;
        }

        return $outcome->isConcluded() ? WaitStatus::SETTLED : WaitStatus::PENDING;
    }
}
