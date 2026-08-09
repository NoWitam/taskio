<?php

namespace App\Modules\Knowledge\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The PRE-RUN AI-budget refusal for the knowledge composer: thrown before a drafting run is claimed or
 * dispatched when the workspace is already at or over its effective month cap — so an over-cap
 * workspace never gets a half-billed session, and never gets a session stuck in `generating` with a
 * worker that refused to spend.
 *
 * Renderable, carrying its own HTTP shape, so both entry points (start and refine) are covered without
 * controller duplication: **429** with `{code: 'ai_budget_exceeded', message: <localized>}` — the same
 * body the Disk image path and the Generator's session runs already emit, and the exact shape the front
 * end's budget banner recognises. Matching that convention is the whole point: a third bespoke shape
 * would mean a third branch in the client for an event all three treat identically.
 *
 * Distinct from {@see \App\Modules\Variables\Exceptions\AiBudgetExceededException}, which is the
 * mid-flight refusal a background path swallows fail-soft. This one answers a person who is waiting.
 *
 * It carries the localized prose as its own message too, so a non-HTTP caller (a queued run recording
 * why it stopped) has something to say. NEVER a provider detail — the message is a translation key's
 * output and nothing else.
 */
class KnowledgeDraftBudgetExceeded extends RuntimeException
{
    /** The typed refusal code the FE keys its budget banner off. */
    public const CODE = 'ai_budget_exceeded';

    /** The localized, NON-SECRET refusal prose. */
    public const MESSAGE_KEY = 'knowledge.drafting.ai_budget_exceeded';

    public function __construct()
    {
        parent::__construct(__(self::MESSAGE_KEY));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => self::CODE,
            // Re-translated at RENDER time (the request's locale); the emitted body is unchanged.
            'message' => __(self::MESSAGE_KEY),
        ], Response::HTTP_TOO_MANY_REQUESTS);
    }
}
