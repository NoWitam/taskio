<?php

namespace App\Modules\Publishing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Publishing\DTOs\PublicationDTO;
use App\Modules\Publishing\Http\Requests\DestroyPublicationRequest;
use App\Modules\Publishing\Http\Requests\SchedulePublicationRequest;
use App\Modules\Publishing\Http\Requests\StorePublicationRequest;
use App\Modules\Publishing\Http\Requests\UpdatePublicationRequest;
use App\Modules\Publishing\Http\Resources\PublicationCountsResource;
use App\Modules\Publishing\Http\Resources\PublicationResource;
use App\Modules\Publishing\Managers\PublicationManager;
use App\Modules\Publishing\Models\Publication;
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
 * WHY `schedule` IS THE ONLY TRANSITION WITH A ROUTE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * It is the only one a PERSON makes in B1. The rest belong to machinery that does not exist yet: the
 * due-sweep claims, the worker publishes, the classifier fails or parks, and the reconciliation is a
 * button that needs a screen. Shipping endpoints for those now would mean a contract to keep for a
 * caller that does not exist and a UI nobody has designed — the same argument `CalendarEventController`
 * makes for having no `restore`.
 *
 * The one it would be tempting to add anyway is a retry. It is deliberately absent, because a retry
 * endpoint written before the reconciliation screen exists is an endpoint whose obvious implementation
 * ("set it back to publishing") is precisely the thing the state machine refuses. It arrives in B3, with
 * the screen that makes reconciling possible.
 */
class PublicationController extends Controller
{
    public function __construct(
        private PublicationService $service,
        private PublicationManager $manager,
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

        return PublicationResource::make($publication->loadMissing('creator'));
    }

    public function store(StorePublicationRequest $request): JsonResponse
    {
        $publication = $this->service->create(PublicationDTO::fromRequest($request));

        return PublicationResource::make($publication->loadMissing('creator'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdatePublicationRequest $request, Publication $publication): PublicationResource
    {
        $this->service->update($publication, PublicationDTO::fromRequest($request));

        return PublicationResource::make($publication->loadMissing('creator'));
    }

    /**
     * ARM IT.
     *
     * The controller does not decide whether the move is legal — it hands the row and the instant to
     * the Manager, which either takes the edge or throws
     * {@see \App\Modules\Publishing\Exceptions\PublicationTransitionRefused} (a 422 that names the two
     * states). That is the whole reason there is no `if` in this method: a controller that pre-checked
     * would be a second reading of the transition table, and the second reading is the one that drifts.
     */
    public function schedule(SchedulePublicationRequest $request, Publication $publication): PublicationResource
    {
        $this->manager->arm($publication, $request->resolvedScheduledAt());

        return PublicationResource::make($publication->loadMissing('creator'));
    }

    public function destroy(DestroyPublicationRequest $request, Publication $publication): JsonResponse
    {
        $this->service->delete($publication);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
