<?php

namespace App\Modules\Workflows\Contracts;

use App\Modules\Workflows\Enums\WaitStatus;
use App\Modules\Workflows\Models\WorkflowRun;

/**
 * Answers ONE question for the waiting-run sweep: "is the external work this run is parked on still
 * pending, settled, or gone?".
 *
 * THE BOUNDARY. Workflows must never name the modules whose work its steps wait on — otherwise the
 * engine would grow a dependency on every feature that suspends. So the sweep asks through this
 * contract, keyed by `waiting_on.kind`, and a feature module REGISTERS its own implementation on
 * {@see \App\Modules\Workflows\Services\WaitResolverRegistry} from its OWN service provider (the same
 * one-way-dependency trick the Variables module uses for ElementScopeResolver / AiTextGenerator,
 * inverted: here the FEATURE side owns the concrete and Workflows owns the contract). Workflows ships
 * the engine with ZERO registered kinds; nothing about it knows what a wait is for.
 *
 * An implementation MUST be cheap (it runs for every waiting run on every sweep), MUST be read-only
 * with respect to the run, and MUST NOT throw for an ordinary "not found" — that is {@see
 * WaitStatus::GONE}. A thrown resolver is caught by the registry and downgraded to PENDING so one
 * broken kind cannot abort the sweep for the others.
 */
interface WaitResolver
{
    /** The `waiting_on.kind` value this resolver answers for. Registry key — must be unique. */
    public function kind(): string;

    /**
     * @param  array<string, mixed>  $waitingOn  the run's persisted `waiting_on` record
     */
    public function status(WorkflowRun $run, array $waitingOn): WaitStatus;
}
