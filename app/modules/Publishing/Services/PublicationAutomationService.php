<?php

namespace App\Modules\Publishing\Services;

use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Publishing\DTOs\PublicationDTO;
use App\Modules\Publishing\DTOs\PublicationOutcome;
use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Managers\PublicationManager;
use App\Modules\Publishing\Models\PlatformConnection;
use App\Modules\Publishing\Models\Publication;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE MODULE'S DOOR FOR SOMETHING THAT IS NOT A PERSON — and the only one.
 *
 * R4 B6. Everything else in this module is entered from an HTTP request (a controller, a FormRequest, a
 * policy) or from the module's own machinery (the sweeps, the worker). This class is the third entrance:
 * an UNATTENDED caller — today the Workflows `publish` step, tomorrow a Campaign — that has content, a
 * destination and a moment, and wants a publication made out of them.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * IT ARMS. IT NEVER PUBLISHES. THIS IS THE WHOLE FENCE.
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * {@see PublicationPublisher::publish()} exists and is callable, and its own docblock warns this batch by
 * name not to reach for it. Nothing here does, and the reason is not tidiness:
 *
 *   - `publish()` bypasses the due-sweep's atomic claim, which is the ONLY thing that stops two callers
 *     dispatching two publishes for one row (ADR-0055 Decision 4).
 *   - It bypasses `PublishPublicationJob`'s per-publication overlap lock.
 *   - And it would run the platform call INSIDE a workflow step — i.e. inside a run job that forces the
 *     `sync` queue driver — so the publish would happen in a process holding a transaction-free two-phase
 *     sequence the module explicitly forbids wrapping, on a worker whose timeout is set for step
 *     orchestration rather than for talking to YouTube.
 *
 * So this class does exactly what a person's Schedule button does: it ARMS a row, and the sweep does the
 * rest, a minute later, through the one path that is allowed to start a publish. An automated publication
 * and a hand-made one go out through the same machinery, which is also why every guarantee B1–B3 bought
 * applies to both without being re-derived here.
 *
 * `WorkflowsPublishingBoundaryTest` reads this file's bytes — and the step's — and refuses an IMPORT of
 * the publisher, a mention of its claimed-publish entry point, or any method call named `publish`. The
 * `{@see}` above is prose and matches none of them, which is the point of pinning the call shape rather
 * than the noun: the warning has to be readable HERE, where somebody would otherwise reach for it.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * TWO SHAPES, AND THE DIFFERENCE IS WHO SAYS WHEN
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WITHOUT A REVIEW PIPELINE the caller's decision is final: the row is created and armed in the same
 * breath, for the moment the caller named (or for now, which in this module means "the next sweep pass
 * takes it").
 *
 * WITH A REVIEW PIPELINE nothing is armed. The row is created as a draft carrying its intended moment in
 * `arm_on_approval_at`, a review is started on it, and the arming waits for the last approver —
 * {@see \App\Modules\Publishing\Models\Publication::onApprovalCompleted()} is what performs it. The
 * caller is told nothing about approvals; it gets a publication back either way and finds out how it
 * ended by asking {@see outcomeFor()}.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE TRANSACTION IS ON THE ROW'S OWN CONNECTION, AND IT STOPS SHORT OF THE REVIEW
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * Creating and arming is TWO writes and they must not half-apply: a committed draft nobody armed is a
 * publication that silently never goes out, and the caller has already been told it succeeded. So they
 * are wrapped — on `(new Publication)->getConnectionName()`, never bare `DB::transaction()`, which
 * resolves `database.default` and would have guarded a connection an own-database workspace's writes
 * never touch. The precedent, and the defect that produced it, is
 * {@see \App\Modules\Publishing\Managers\PlatformConnectionManager::transaction()}.
 *
 * `ApprovalService::startProcess()` is called AFTER that transaction commits, deliberately. It opens a
 * transaction of its own on the DEFAULT connection and dispatches a job for an automated first stage;
 * nesting it would pair a tenant-connection rollback with a committed approval process pointing at a row
 * that no longer exists. If it throws (a pipeline with no stages), the draft is left behind and the
 * caller's failure is honest — nothing was armed, so nothing can go out. That is the same posture the
 * `generate_content` step takes towards the draft session it leaves behind on a refusal.
 */
class PublicationAutomationService
{
    public function __construct(
        private PublicationService $publications,
        private PublicationManager $manager,
        private ApprovalService $approvals,
    ) {}

    /**
     * Make a publication on behalf of an automation, and either arm it or hand it to a review.
     *
     * @param  CarbonInterface|null  $publishAt  when it should go out; null means "as soon as it may"
     * @param  string|null  $approvalPipelineId  a workspace pipeline that gates the arming, or null
     */
    public function create(PublicationDTO $dto, ?CarbonInterface $publishAt, ?string $approvalPipelineId): Publication
    {
        $publication = $this->transaction(function () use ($dto, $publishAt, $approvalPipelineId): Publication {
            $publication = $this->publications->create($dto);

            if ($approvalPipelineId !== null) {
                // TWO STATEMENTS RATHER THAN ONE INSERT, and on purpose. `arm_on_approval_at` is the
                // reason: it is the ONE column only an automation may set, and putting it on
                // `PublicationDTO` — which is also the HTTP write surface — would open a second door
                // onto the request path for the sake of saving a statement here. (The pipeline id IS on
                // the DTO since the manual-review path was filled in; it rides along in this write
                // rather than through the DTO only because the seam takes it as its own argument.) Both
                // columns are ordinary fillable ones and neither is `status`, so this is a content
                // write like any other — the Manager still owns every transition.
                $publication->update([
                    'approval_pipeline_id' => $approvalPipelineId,
                    // The intent the review is holding. `now()` for "as soon as it may": in a module
                    // where nothing publishes synchronously, a moment already past IS "immediately" —
                    // the next sweep pass claims it. See the B6 migration.
                    'arm_on_approval_at' => $publishAt ?? now(),
                ]);

                return $publication;
            }

            // No review: the caller's decision is final, so this is the arming a person would have
            // pressed. Through the Manager, which is the module's one door onto `draft -> scheduled`.
            $this->manager->arm($publication, $publishAt ?? now());

            return $publication;
        });

        if ($approvalPipelineId !== null) {
            // OUTSIDE the transaction — see the class docblock. A null actor is correct: the review was
            // started by machinery, and `startProcess` falls back to `auth()->id()`, which is null on a
            // queue. The publication's own creator already records who is responsible for the row.
            $this->approvals->startProcess($publication, null);
        }

        return $publication;
    }

    /**
     * WHERE ONE PUBLICATION STANDS — the read an unattended caller waiting on it makes, repeatedly.
     *
     * Null means GONE: no such publication, anywhere this connection can see. A caller must treat that as
     * terminal rather than as "not yet", because a row that does not exist can never reach an outcome.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * IT IS DELIBERATELY UNSCOPED, AND THAT IS THE SAFE DIRECTION
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * The callers that poll this run in sweeps with the tenant context CLEARED (that is what lets one
     * pass cover every shared-database workspace at once), so `WorkspaceScope` adds no predicate and a
     * null here means "no such row in this database" rather than "not in the active workspace". A
     * workspace-scoped read from a pass with no active workspace would answer null for EVERY parked
     * caller and strand or fail all of them at once.
     *
     * It is not a cross-workspace leak: the only id ever asked about is one the caller itself was handed
     * when it created the row, and all that comes back is a status and a boolean.
     *
     * CHEAP: one primary-key lookup. The approval reads happen ONLY for a draft — every other status has
     * already answered the question, and a publication spends most of its life not being a draft.
     */
    public function outcomeFor(string $publicationId): ?PublicationOutcome
    {
        if (!Str::isUuid($publicationId)) {
            return null;
        }

        $publication = Publication::find($publicationId);

        if ($publication === null) {
            return null;
        }

        return new PublicationOutcome(
            $publication->status,
            $publication->status === PublicationStatus::DRAFT && $publication->approvalWasRejected(),
        );
    }

    /**
     * Whether this destination can actually be published to — asked when a definition is WRITTEN.
     *
     * The same three questions `StorePublicationRequest` asks of a create payload (the connection exists
     * in this workspace, it serves this platform, and it is usable), asked through the module that owns
     * the answer so a caller outside it never reimplements `usable()`. Asking the same authority is also
     * what keeps a saved definition from being one the run would refuse.
     *
     * A MISSING CONNECTION IS ONLY ACCEPTABLE FOR A DESTINATION THAT PUBLISHES NOTHING, which is stricter
     * than the HTTP create path — and the difference is the point. A person drafting may name the account
     * later, from a picker, before they arm it; a workflow definition has no later. A step pointed at
     * YouTube with no account would fail at publish time on every single run, unattended, which is exactly
     * the class of mistake worth refusing while somebody is still looking at it.
     */
    public function destinationIsUsable(PublishingPlatform $platform, ?string $connectionId): bool
    {
        if ($connectionId === null || $connectionId === '') {
            return !$platform->publishesPublicly();
        }

        if (!Str::isUuid($connectionId)) {
            return false;
        }

        return PlatformConnection::query()
            ->usable()
            ->where('platform', $platform)
            ->whereKey($connectionId)
            ->exists();
    }

    /**
     * A transaction ON THE PUBLICATION'S OWN CONNECTION. See the class docblock for the defect that
     * makes the distinction load-bearing rather than pedantic.
     */
    private function transaction(Closure $callback): mixed
    {
        return DB::connection((new Publication)->getConnectionName())->transaction($callback);
    }
}
