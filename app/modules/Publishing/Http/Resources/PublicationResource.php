<?php

namespace App\Modules\Publishing\Http\Resources;

use App\Http\Resources\CreatorResource;
use App\Modules\Publishing\Models\Publication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ONE publication, in full.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE STATUS TRAVELS AS A CODE **AND** AS PROSE, WHICH LOOKS REDUNDANT AND IS NOT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `status` is the stable machine value and is what a client BRANCHES on — never the wording, which is
 * the house rule (recognise structurally, never by the server's sentence). `status_label` is the same
 * status in the reader's language, published because a screen that has to translate seven statuses
 * itself is a screen that silently omits the eighth.
 *
 * `status_tone` rides along for the same reason a calendar occurrence carries a colour: the meaning is
 * the server's to decide, so the badge on this row and the square on the grid cannot drift apart. Null
 * for a draft, which is the one status with no colour to state.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `media` IS IDS, NOT FILES
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Handed over as the ordered id list that is stored. NOT expanded into file objects, and that is a
 * decision rather than an omission: expanding them would make this module resolve Disk files on every
 * read of every publication, take a dependency it does not have, and put a preview into a payload whose
 * job is to describe an intent. A client that wants thumbnails asks the Disk, which is the module that
 * owns them and already has an endpoint for exactly that.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT THE PLATFORM SAID, AND WHAT WE DO NOT REPEAT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `remote_id` and `remote_url` are published — they are the proof a post exists and the way a person
 * goes and looks at it.
 *
 * `remote_draft_id` is NOT. It is internal recovery state, meaningless to a reader, and publishing it
 * would invite a client to send it back — which is the one value that must never arrive from outside,
 * because a resume pointed at a container somebody else named is a post nobody authored. The request
 * refuses it on the way in; this refuses to advertise it on the way out.
 *
 * `failure_code` is a STABLE CODE the client translates, beside `failure_context` for the details that
 * make it actionable. Never the platform's own prose, which is composed on their servers in whatever
 * language they choose.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE CAPABILITY FLAGS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `is_owner` is HUMAN authorship only; the `can_*` flags route through the policy and therefore include
 * the workspace-owner fallback AND the state test. For a publication created by a workflow run the two
 * legitimately disagree (ADR-0015) — gate UI actions on `can_*`, never on `is_owner`.
 *
 * `can_be_scheduled` is separate from `can_be_edited` on purpose, even though they compute the same
 * answer today: arming is the consequential act, and the day a rule says "only a reviewer may arm" this
 * is the flag that changes.
 *
 * `can_be_reconciled` (B3) is true for exactly one status and is the flag a screen should hang the
 * "check the platform" action on. It is deliberately the ONLY affordance offered for a publication in
 * `needs_reconcile`: `can_be_edited` and `can_be_deleted` are both false there, and there is no retry
 * flag at all, because from that status the only honest next act is to go and look.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE REVIEW FIELDS (B6), AND WHY `approval_state` IS NOT REDUNDANT WITH `is_in_approval`
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `is_in_approval` answers "is somebody deciding about this right now", which is what the two capability
 * flags above turn on — both `can_be_edited` and `can_be_scheduled` route through the policy, which
 * composes exactly that predicate, so a screen that respects the flags never offers a button whose
 * request would be refused.
 *
 * `approval_state` answers the question a concluded review leaves behind, and without it that answer
 * would be invisible. A publication a reviewer TURNED DOWN goes back to being a `draft` — the same status
 * as one nobody has looked at and the same status as one still under review — so `status` alone cannot
 * distinguish "not sent yet" from "sent and refused". The field is the latest process's own status
 * (`pending` / `approved` / `rejected`), or null when this publication has never been reviewed.
 *
 * Both are answered from EAGER-LOADED relations (`Publication::readRelations()`). They are rendered for
 * every row of a list, so a lazy read would be two queries per publication.
 */
class PublicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Publication $publication */
        $publication = $this->resource;

        return [
            'id' => $publication->id,
            'title' => $publication->title,
            'body' => $publication->body,

            'platform' => $publication->platform->value,
            'platform_label' => $publication->platform->label(),
            'publishes_publicly' => $publication->platform->publishesPublicly(),
            'platform_connection_id' => $publication->platform_connection_id,

            // Branch on the code; render the label. See the class docblock.
            'status' => $publication->status->value,
            'status_label' => $publication->status->label(),
            'status_tone' => $publication->status->tone(),
            'needs_attention' => $publication->status->needsAttention(),

            'scheduled_at' => $publication->scheduled_at?->toISOString(),
            'published_at' => $publication->published_at?->toISOString(),

            // Ordered Disk file ids. Not expanded — see the class docblock.
            'media' => $publication->media ?? [],
            'options' => $publication->options ?? (object) [],

            // The proof, and the way to go and look. `remote_draft_id` is deliberately absent.
            'remote_id' => $publication->remote_id,
            'remote_url' => $publication->remote_url,

            'attempts' => $publication->attempts,
            'last_attempt_at' => $publication->last_attempt_at?->toISOString(),
            'failure_code' => $publication->failure_code,
            'failure_context' => $publication->failure_context,

            // ── REVIEW (B6) ───────────────────────────────────────────────────────────────────────
            'approval_pipeline_id' => $publication->approval_pipeline_id,
            'is_in_approval' => $publication->isInApproval(),
            // The latest decision, which is the only way a REJECTION is visible: a refused publication
            // is a draft again and says nothing about it on `status`. Null = never reviewed.
            'approval_state' => $publication->latestApprovalProcess?->status->value,
            // When this means to go out, whichever half of its life it is in — `scheduled_at` once
            // armed, the moment a review is holding while it is not. One field, so a screen never has
            // to know which column the answer came from.
            'intended_publish_at' => $publication->intendedPublishAt()?->toISOString(),

            'creator' => CreatorResource::make($this->whenLoaded('creator')),

            'is_owner' => $publication->isOwnedBy($request->user()),
            'can_be_edited' => $request->user()?->can('update', $publication) ?? false,
            'can_be_deleted' => $request->user()?->can('delete', $publication) ?? false,
            'can_be_scheduled' => $request->user()?->can('schedule', $publication) ?? false,
            'can_be_reconciled' => $request->user()?->can('reconcile', $publication) ?? false,

            'created_at' => $publication->created_at?->toISOString(),
            'updated_at' => $publication->updated_at?->toISOString(),
        ];
    }
}
