<?php

namespace App\Modules\Knowledge\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * RESTORE refusal: the trashed entry's slug has been taken by a live entry while it was in the trash.
 *
 * Slug uniqueness is scoped to LIVE entries on purpose — a trash that blocked a slug would make
 * deleting an entry a permanent reservation of its name. The cost of that choice is exactly this
 * case, and the two ways out are to re-slug the restored entry or to refuse.
 *
 * Refusing wins because the slug is the entry's identity: every `[[wikilink]]` in the base points at
 * it, so silently re-slugging on restore would hand the user back an entry that nothing links to any
 * more — the failure would look like success and surface later as mysteriously empty backlinks.
 * A 409 instead tells the user the one thing they can act on: rename the occupant, then restore.
 *
 * 409 (not 422) because the request is well-formed and the STATE is what conflicts.
 */
class KnowledgeSlugConflictException extends RuntimeException
{
    public const CODE = 'knowledge_slug_conflict';

    public const MESSAGE_KEY = 'knowledge.entries.slug_conflict';

    public function __construct(private readonly string $slug)
    {
        parent::__construct(__(self::MESSAGE_KEY, ['slug' => $slug]));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => self::CODE,
            'message' => __(self::MESSAGE_KEY, ['slug' => $this->slug]),
            'slug' => $this->slug,
        ], Response::HTTP_CONFLICT);
    }
}
