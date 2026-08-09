<?php

namespace App\Modules\Knowledge\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Write the entry this red link points at" — when the red link is an INFLECTED FORM of an entry that
 * already exists.
 *
 * ------------------------------------------------------------------------------------------------
 * THE CASE, AND WHY EVERY LAYER WAS RIGHT
 *
 * A model wrote `[[nowego-tokio]]` where the base holds "Nowe Tokio" (slug `nowe-tokio`, alias
 * "Nowego Tokio"). The link resolved to nothing, so it rendered red; the owner clicked "create the
 * missing entry"; the composer started with `seed_slug=nowego-tokio`; entity resolution matched the
 * alias to the existing entry — correctly — so the model declined to write a duplicate — correctly —
 * and the seed check then found no entry under the requested slug and failed the run.
 *
 * Every step behaved as designed and the user got `seed_missed`, a red link they could not fill, and a
 * paid AI call that could never have succeeded.
 *
 * ------------------------------------------------------------------------------------------------
 * REFUSED BEFORE THE SPEND, AND IT NAMES THE ENTRY
 *
 * The answer the person needs is not "that failed" but "that already exists, as Nowe Tokio" — so the
 * payload carries the entry's id, slug and title, and a client can offer to open it instead of
 * offering to create it again.
 *
 * 409, not 422: the request is well-formed and it is the STATE of the base that conflicts with it.
 */
class KnowledgeSeedIsAliasException extends RuntimeException
{
    public const CODE = 'knowledge_seed_is_alias';

    public const MESSAGE_KEY = 'knowledge.entries.seed_is_alias';

    public function __construct(
        private readonly string $seed,
        private readonly string $entryId,
        private readonly string $entrySlug,
        private readonly string $entryTitle,
    ) {
        parent::__construct(__(self::MESSAGE_KEY, ['seed' => $seed, 'title' => $entryTitle]));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => self::CODE,
            'message' => __(self::MESSAGE_KEY, ['seed' => $this->seed, 'title' => $this->entryTitle]),
            'seed_slug' => $this->seed,
            // Everything a client needs to turn "create it" into "go to it", without a second request.
            'entry' => [
                'id' => $this->entryId,
                'slug' => $this->entrySlug,
                'title' => $this->entryTitle,
            ],
        ], Response::HTTP_CONFLICT);
    }
}
