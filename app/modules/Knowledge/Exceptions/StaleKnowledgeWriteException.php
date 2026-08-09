<?php

namespace App\Modules\Knowledge\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * OPTIMISTIC-LOCK refusal: the writer sent an `expected_revision_id` that is no longer the entry's
 * current revision, so somebody else saved in between.
 *
 * Refusing is the whole point. A knowledge base is written by several people and by bots, and the
 * default behaviour of a plain PATCH — last write wins — means the loser's edit is gone with no
 * trace and no notification. Every revision is kept, so nothing is truly lost, but a silent
 * overwrite still costs the reader: the entry now says one thing when two people believed they had
 * said two.
 *
 * 409 (not 422) because the payload is valid — the STATE moved. Renderable so the refusal has one
 * shape wherever it is thrown, and it carries `current_revision_id` so a client can fetch exactly
 * what it missed and offer a merge instead of just an error.
 */
class StaleKnowledgeWriteException extends RuntimeException
{
    public const CODE = 'knowledge_stale_write';

    public const MESSAGE_KEY = 'knowledge.entries.stale_write';

    public function __construct(private readonly ?string $currentRevisionId)
    {
        parent::__construct(__(self::MESSAGE_KEY));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => self::CODE,
            'message' => __(self::MESSAGE_KEY),
            'current_revision_id' => $this->currentRevisionId,
        ], Response::HTTP_CONFLICT);
    }
}
