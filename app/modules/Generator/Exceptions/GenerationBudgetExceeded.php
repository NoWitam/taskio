<?php

namespace App\Modules\Generator\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The PRE-RUN AI-budget gate refusal (R2 sub-stage 4): thrown up front by
 * {@see \App\Modules\Generator\Services\GenerationSessionRunManager::claimAndDispatch} when the active
 * workspace is ALREADY at/over its effective calendar-month AI $ cap — BEFORE the run is claimed or
 * dispatched, so an over-cap workspace's run is never partially billed (distinct from the mid-run fail-soft
 * {@see \App\Modules\Variables\Exceptions\AiBudgetExceededException} the executor swallows for a run that
 * CROSSES the cap in flight).
 *
 * Renderable — carries its own HTTP shape so the single guard point covers all four run entry points
 * (whole generate / per-part regenerate / per-part refine / delegate auto-run) without controller
 * duplication: HTTP 429 + a body `{code: 'ai_budget_exceeded', message: <localized, non-secret>}` matching
 * the Disk image path's 429 budget convention and the exact shape the FE's `isBudgetError` recognizes, so
 * the (otherwise dead) budget banner lights up. NEVER carries a secret — the message is a localized prose
 * key only.
 *
 * It also carries that SAME localized message as its own exception message. It was built HTTP-first, so the
 * message used to live ONLY inside render() — which left `getMessage()` EMPTY for a NON-HTTP caller (an
 * automated run recording why a step failed would have shown nothing for the single loudest failure mode of
 * the feature). The constructor now seeds it; render() keeps composing its body from the SAME key, so the
 * HTTP contract (429 + `{code, message}`) is byte-identical.
 */
class GenerationBudgetExceeded extends RuntimeException
{
    /** The typed budget-refusal code the FE keys the budget banner off (mirrors the Disk 429 convention). */
    public const CODE = 'ai_budget_exceeded';

    /** The localized, NON-SECRET refusal prose — see {@see MESSAGE_KEY}; never a provider/body detail. */
    public const MESSAGE_KEY = 'generator.sessions.ai_budget_exceeded';

    public function __construct()
    {
        parent::__construct(__(self::MESSAGE_KEY));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => self::CODE,
            // Re-translated at RENDER time (the request's locale), not the construction-time message — the
            // emitted body is unchanged.
            'message' => __(self::MESSAGE_KEY),
        ], Response::HTTP_TOO_MANY_REQUESTS);
    }
}
