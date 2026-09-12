<?php

namespace App\Modules\Publishing\DTOs;

use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Http\Requests\StorePublicationRequest;
use Carbon\CarbonImmutable;

/**
 * A validated PUBLICATION on its way into {@see \App\Modules\Publishing\Services\PublicationService}.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IS NOT ON IT, AND WHY EACH ABSENCE IS DELIBERATE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *   status            The Manager's, exclusively. A DTO carrying a status would be a second door into
 *                     the state machine, opened by whoever assembles the DTO — and the whole point of
 *                     the Manager is that there is one door.
 *   remote_id         Written only when a platform has told us something. There is no payload from
 *   remote_draft_id   which either could legitimately arrive.
 *   attempts          The machine counts its own attempts.
 *
 * `scheduledAt` IS here, and it is the one field with a subtlety. Setting it does NOT arm anything:
 * this DTO describes CONTENT AND INTENT, and moving to `scheduled` is a transition the Manager makes.
 * A publication created with a moment is still a draft until it is armed — which is what lets a user
 * pick a time while still writing, and what stops a create call from being an irreversible act.
 *
 * `media` is an ORDERED list of Disk file ids and is stored verbatim. It is not dereferenced here, or
 * anywhere else in this module: the bytes matter exactly once, inside the adapter at publish time.
 *
 * `approvalPipelineId` IS here (B6), and it is content in the same sense the rest is: attaching a review
 * says what this publication IS, not where it is in its life. Nothing about it starts a review —
 * `ApprovalService::startProcess()` does that, from a door of its own — and nothing about it is a
 * transition. NULL MEANS DETACHED, because this DTO describes the whole row; a field that could only ever
 * be set would make "take the review off this draft" unexpressible.
 */
final readonly class PublicationDTO
{
    /**
     * @param  array<int, string>  $media  Disk file ids, IN THE ORDER the platform receives them
     * @param  array<string, mixed>  $options  per-destination extras, shaped by the adapter that reads them
     */
    public function __construct(
        public string $title,
        public ?string $body,
        public PublishingPlatform $platform,
        public ?string $platformConnectionId,
        /** UTC instant, or null. Carries INTENT, never a transition — see the class docblock. */
        public ?CarbonImmutable $scheduledAt,
        public array $media = [],
        public array $options = [],
        /** The review that gates arming, or null for none. Null on an update DETACHES. */
        public ?string $approvalPipelineId = null,
    ) {}

    /**
     * The validated payload.
     *
     * The instant is read through the request's own accessor rather than `$request->date()`, because a
     * zone-less "09:00" has to mean the workspace's nine o'clock — the same rule, through the same
     * resolver, that the calendar write path uses. Only the request still sees the raw string well
     * enough to know whether the caller named a zone at all.
     */
    public static function fromRequest(StorePublicationRequest $request): self
    {
        return new self(
            title: trim($request->string('title')->value()),
            body: $request->filled('body') ? $request->string('body')->value() : null,
            platform: $request->resolvedPlatform(),
            platformConnectionId: $request->filled('platform_connection_id')
                ? $request->string('platform_connection_id')->value()
                : null,
            scheduledAt: $request->resolvedScheduledAt(),
            media: $request->resolvedMedia(),
            options: $request->resolvedOptions(),
            approvalPipelineId: $request->resolvedApprovalPipelineId(),
        );
    }
}
