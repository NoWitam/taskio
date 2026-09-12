<?php

namespace App\Modules\Publishing\Events;

use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Models\Publication;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A PUBLICATION REACHED AN OUTCOME SOMEBODY MAY HAVE BEEN WAITING FOR.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT "CONCLUDED" MEANS HERE, AND WHAT IT DELIBERATELY DOES NOT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Three lifecycle outcomes plus one that is not a lifecycle move at all:
 *
 *   published        it is out; there is a remote id and a url to hand back.
 *   failed           the platform was asked and definitely created nothing.
 *   blocked          the connection is held, so this will not go out until a person fixes it.
 *   review rejected  the row is still a DRAFT — no status moved — but the answer is in.
 *
 * `needs_reconcile` IS NOT ANNOUNCED, and that absence is the single most considered thing in this file.
 * That status means WE DO NOT KNOW whether a post exists, and it has no automatic exit by design
 * (ADR-0055 Decisions 1–2). Announcing it as a conclusion would wake every waiting caller with a
 * non-answer, and a caller that treated it as one would conclude "failed" about a publication that may
 * be live — which is the exact reasoning error the whole module is arranged to prevent. A probe or a
 * person resolves it, and THAT resolution (`published`/`failed`) is what fires this event.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * IT CARRIES PRIMITIVES, AND IT NAMES NOBODY
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * An id, a workspace id and a status string. No model (a listener re-reads the row under the right
 * tenant connection — see `WorkflowRunResumeJob` for why a serialized model is the wrong payload for
 * anything that crosses a queue), no content, and nothing derived from a credential.
 *
 * NOT BROADCAST, unlike the Generator's sibling signal. There is no publishing screen subscribed to a
 * channel yet (B8), and a broadcast needs a private channel plus its authorization — surface that would
 * exist for nobody. Adding `ShouldBroadcast` later changes no listener.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE DIRECTION OF THE DEPENDENCY IS THE POINT OF HAVING AN EVENT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Publishing fires it and knows nothing about who listens. The Workflows module is what reacts — it is
 * allowed to name Publishing, and Publishing may never name it (pinned module-wide by
 * `WorkflowsPublishingBoundaryTest`). The same inversion the Generator's terminal event already uses.
 */
class PublicationConcluded
{
    use Dispatchable;

    public function __construct(
        public string $workspaceId,
        public string $publicationId,
        /** A `PublicationStatus` value, or {@see self::REVIEW_REJECTED}. */
        public string $status,
    ) {}

    /**
     * The one outcome that is NOT a `PublicationStatus`: a review said no and the row stayed a draft.
     *
     * Deliberately not a status value. Adding an eighth status for it would have meant adding its edges
     * too, including an edge back out into `scheduled` — the one move a rejection exists to withhold.
     */
    public const REVIEW_REJECTED = 'review_rejected';

    /**
     * The statuses this event is raised for. Read by `PublicationManager::transition()`, which is the
     * only thing that can raise the lifecycle three — see the class docblock for why `needs_reconcile`
     * is not among them.
     *
     * @return array<int, PublicationStatus>
     */
    public static function concludingStatuses(): array
    {
        return [
            PublicationStatus::PUBLISHED,
            PublicationStatus::FAILED,
            PublicationStatus::BLOCKED,
        ];
    }

    /** Announce a lifecycle outcome. Silent when the row cannot say which workspace it belongs to. */
    public static function fromTransition(Publication $publication, PublicationStatus $to): void
    {
        self::announce($publication, $to->value);
    }

    /** Announce a review that concluded in a refusal. The publication is still a draft. */
    public static function fromRejectedReview(Publication $publication): void
    {
        self::announce($publication, self::REVIEW_REJECTED);
    }

    /**
     * ONE workspace id, from the two places it can live.
     *
     * During an own-database pass a workspace is ACTIVE and the row carries no `workspace_id` column at
     * all — one tenant database is one workspace. During the shared pass no workspace is active on
     * purpose, and the answer is on the row. The same two-source reading `PublicationQueueService` makes,
     * for the same reason.
     *
     * An UNATTRIBUTABLE row raises nothing. A listener's whole job is to re-establish a tenant connection
     * from this id, and handing it an empty string would have it act under whatever context happens to be
     * ambient in a worker — which is how one workspace's machinery touches another's data. A dropped
     * notification costs a waiting caller its fast path; the sweep still recovers it.
     */
    private static function announce(Publication $publication, string $status): void
    {
        $workspaceId = app(TenantContext::class)->id()
            ?? $publication->getAttribute('workspace_id');

        if (!is_string($workspaceId) || $workspaceId === '') {
            return;
        }

        self::dispatch($workspaceId, (string) $publication->getKey(), $status);
    }
}
