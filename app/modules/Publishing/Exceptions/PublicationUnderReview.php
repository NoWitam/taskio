<?php

namespace App\Modules\Publishing\Exceptions;

use App\Modules\Publishing\Models\Publication;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A REVIEW IS HOLDING THIS PUBLICATION, so it may not be edited and it may not be armed.
 *
 * R4 B6. The refusal a person meets when their screen was drawn before somebody sent the publication for
 * approval — or when they are looking at it in another tab while an approver reads the same row.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS IS A 422 IN THE MODULE'S REFUSAL SHAPE AND NOT THE BARE 403 THE POLICY WOULD GIVE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The decision itself lives in {@see \App\Modules\Publishing\Policies\PublicationPolicy}, composed from
 * `Publication::isInApproval()` exactly as `TaskPolicy::update()` composes it — one computation, which is
 * what keeps the resource's `can_be_edited` / `can_be_scheduled` flags from disagreeing with the write
 * path. What this class changes is only what the refusal SAYS.
 *
 * A 403 answers "not you". That sentence is false here: the creator, the workspace owner and every
 * reviewer get the identical refusal, and none of them has a permission problem — the ROW is busy being
 * decided about. A person told "forbidden" goes looking for a permission to be granted; a person told
 * "this is with an approver" waits, or goes and approves it. The module already has a vocabulary for
 * exactly this shape of answer (`{code, message, context}` at 422, which every `publication_*` refusal
 * uses and which the frontend already branches on by `code`), so the refusal joins it rather than
 * inventing a third convention.
 *
 * It is raised from the FormRequests' `failedAuthorization()`, i.e. AFTER the policy has said no and only
 * when the review is the reason — see {@see \App\Modules\Publishing\Http\Requests\SchedulePublicationRequest}.
 * Any other refusal (a published row, somebody else's draft) keeps the ordinary 403.
 *
 * `context.status` carries the publication's own status, which is a draft in practice and always will be
 * while a review is live — it is there so a client can render the row without a second request, exactly
 * as the transition refusal carries `from`/`to`.
 */
class PublicationUnderReview extends RuntimeException
{
    /** The wire code. A client branches on this, never on the sentence. */
    public const CODE = 'publication_under_review';

    public function __construct(
        public readonly string $publicationId,
        public readonly string $status,
    ) {
        // `publishing.approval.*`, NOT `publishing.transitions.*`: that catalog is pinned key-for-key to
        // {@see PublicationTransitionRefused}'s own vocabulary, and a review hold is not an edge the
        // machine refused.
        parent::__construct(__('publishing.approval.under_review'));
    }

    public static function for(Publication $publication): self
    {
        return new self((string) $publication->getKey(), $publication->status->value);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => self::CODE,
            'message' => $this->getMessage(),
            'context' => [
                'status' => $this->status,
            ],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
