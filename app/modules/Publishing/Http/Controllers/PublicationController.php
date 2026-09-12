<?php

namespace App\Modules\Publishing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Publishing\DTOs\PublicationDTO;
use App\Modules\Publishing\Http\Requests\DestroyPublicationRequest;
use App\Modules\Publishing\Http\Requests\ReconcilePublicationRequest;
use App\Modules\Publishing\Http\Requests\SchedulePublicationRequest;
use App\Modules\Publishing\Http\Requests\StorePublicationRequest;
use App\Modules\Publishing\Http\Requests\UpdatePublicationRequest;
use App\Modules\Publishing\Http\Resources\PublicationCountsResource;
use App\Modules\Publishing\Http\Resources\PublicationResource;
use App\Modules\Publishing\Managers\PublicationManager;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Services\PublicationPublisher;
use App\Modules\Publishing\Services\PublicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRUD for PUBLICATIONS, plus the one transition B1 exposes over HTTP.
 *
 * Thin by construction: request → DTO → service → resource. Authorization lives in the FormRequests
 * (via `PublicationPolicy`), including on destroy and on schedule, both of which have requests of their
 * own for exactly that reason.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE TWO TRANSITIONS WITH A ROUTE, AND THE ONE THAT STILL HAS NONE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `schedule` arms a publication and `reconcile` asks the platform what happened to one. Both are acts a
 * PERSON performs. Everything else belongs to machinery that now exists and is not addressable over
 * HTTP: the sweep claims, the worker publishes, the classifier fails or parks, the reaper recovers.
 *
 * THERE IS STILL NO RETRY ENDPOINT, and B3 is the batch that could have added one. It did not, because
 * once reconciliation exists a retry endpoint has nothing left to do: a reconciliation that proves
 * absence leaves the row in `failed`, and from `failed` the retry a person wants IS `POST /schedule`.
 * An endpoint that skipped that step would be the one whose obvious implementation — "set it back to
 * publishing" — is precisely what the state machine refuses, and it would be reachable from
 * `needs_reconcile`, where a second call could produce a second public artifact.
 */
class PublicationController extends Controller
{
    public function __construct(
        private PublicationService $service,
        private PublicationManager $manager,
        private PublicationPublisher $publisher,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Publication::class);

        return PublicationResource::collection($this->service->index($request));
    }

    public function counts(Request $request): PublicationCountsResource
    {
        $this->authorize('viewAny', Publication::class);

        return PublicationCountsResource::make($this->service->counts($request));
    }

    public function show(Publication $publication): PublicationResource
    {
        $this->authorize('view', $publication);

        return PublicationResource::make($publication->loadMissing(Publication::readRelations()));
    }

    public function store(StorePublicationRequest $request): JsonResponse
    {
        $publication = $this->service->create(PublicationDTO::fromRequest($request));

        return PublicationResource::make($publication->loadMissing(Publication::readRelations()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdatePublicationRequest $request, Publication $publication): PublicationResource
    {
        $this->service->update($publication, PublicationDTO::fromRequest($request));

        return PublicationResource::make($publication->loadMissing(Publication::readRelations()));
    }

    /**
     * ARM IT — or, when a review gates this draft, SUBMIT it with the chosen moment (B6).
     *
     * The controller still decides nothing about the STATE MACHINE: the service either hands the row
     * and the instant to the Manager (which takes the edge or throws
     * {@see \App\Modules\Publishing\Exceptions\PublicationTransitionRefused}, a 422 that names the two
     * states) or opens the review and parks the moment — a branch on REVIEW standing, which lives in
     * exactly one place ({@see PublicationService::schedule()}), never a second reading of the
     * transition table here.
     */
    public function schedule(SchedulePublicationRequest $request, Publication $publication): PublicationResource
    {
        $this->service->schedule($publication, $request->resolvedScheduledAt(), $request->user());

        return PublicationResource::make($publication->loadMissing(Publication::readRelations()));
    }

    /**
     * ASK THE PLATFORM. The manual way out of `needs_reconcile`, and B3's answer to B1's open question.
     *
     * B1 said a retry endpoint would arrive in B3 "with the screen that makes reconciling possible". It
     * did not arrive, and this is why: once reconciliation exists there is nothing left for a retry
     * endpoint to do that is not already an ordinary arming. A reconciliation that proves absence leaves
     * the row in `failed`, from which `POST /schedule` is a legal move — so the retry a person wants is
     * the button they already have, reached through the one door that establishes it is safe.
     *
     * Like `schedule`, this method contains no `if`. The publisher asks the adapter and the answer picks
     * the transition; a controller that pre-checked would be a second reading of the same table.
     */
    public function reconcile(ReconcilePublicationRequest $request, Publication $publication): PublicationResource
    {
        $this->publisher->reconcile($publication);

        return PublicationResource::make($publication->loadMissing(Publication::readRelations()));
    }

    public function destroy(DestroyPublicationRequest $request, Publication $publication): JsonResponse
    {
        $this->service->delete($publication);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
