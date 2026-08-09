<?php

namespace App\Modules\Knowledge\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a SECOND context expansion on a session whose context has already been widened.
 *
 * WHY REFUSE RATHER THAN JUST REPEAT THE WORK. Expanding spends an embedding, and a repeat with
 * nothing changed buys the identical retrieval set: the same source text against the same corpus with
 * the same configuration is a deterministic query. The state that made it look available again was
 * per-browser, so the people most likely to press it twice — someone who reloaded, or a second
 * reviewer opening the same session — are exactly the people who would gain nothing for the money.
 *
 * IT IS NOT A DEAD END, and that is what makes refusing acceptable. A REFINEMENT clears the flag,
 * because a revision consumes the widened context (the composer re-derives its whole set against it),
 * after which expanding again is genuinely new work. So the loop a reviewer actually wants — widen,
 * revise, widen again — is open; only widening twice in a row against unchanged inputs is closed.
 *
 * The honest caveat: the base's own entries can change between two expansions, so a repeat is not
 * ALWAYS pointless. That case is reachable through the same door (refine, then expand), and it is rare
 * enough that paying for every accidental double-click to serve it would be the wrong trade.
 *
 * 422 with `{code, message}` — a state conflict about this session, not an authorization problem and
 * not a budget one; the client can tell the three apart by code and say something useful.
 */
class KnowledgeContextAlreadyExpanded extends RuntimeException
{
    public const CODE = 'knowledge_context_already_expanded';

    public const MESSAGE_KEY = 'knowledge.drafting.context_already_expanded';

    public function __construct()
    {
        parent::__construct(__(self::MESSAGE_KEY));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => self::CODE,
            'message' => __(self::MESSAGE_KEY),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
