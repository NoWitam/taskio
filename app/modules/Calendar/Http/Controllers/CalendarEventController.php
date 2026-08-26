<?php

namespace App\Modules\Calendar\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Calendar\DTOs\CalendarEventDTO;
use App\Modules\Calendar\Enums\CalendarEventScope;
use App\Modules\Calendar\Http\Requests\DestroyCalendarEventRequest;
use App\Modules\Calendar\Http\Requests\StoreCalendarEventRequest;
use App\Modules\Calendar\Http\Requests\UpdateCalendarEventRequest;
use App\Modules\Calendar\Http\Resources\CalendarEventResource;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Services\CalendarEventService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRUD for CALENDAR EVENTS — the module's only write surface.
 *
 * Thin by construction: request → DTO → service → resource. Authorization lives in the FormRequests
 * (via CalendarEventPolicy), including on destroy — which now has a scope to validate and therefore a
 * request of its own.
 *
 * THE ONE BRANCH IN THIS FILE is the series scope, and it is a `match` over an enum with no default
 * arm on purpose: a scope added to {@see CalendarEventScope} makes this expression throw at the point
 * of use instead of silently falling through to "the whole series", which is the most destructive of
 * the three. The request has already proved the scope is coherent with the row — that a scoped write
 * names a real occurrence of a series that exists — so nothing here decides anything, it only
 * dispatches.
 *
 * `update` returns THE RESOURCE YOU ARE LOOKING AT AFTERWARDS, which under a scoped write is often a
 * DIFFERENT row than the URL named — and says so in the STATUS CODE (200 for the addressed event, 201
 * when a new one was produced). See {@see UpdateCalendarEventRequest} for the full statement and for
 * why a separate verb was considered and rejected.
 *
 * There is no `index`. Listing events by themselves is not a thing this product does — events are read
 * ON THE GRID, merged with every other source, through GET /calendar/occurrences. A second list
 * endpoint would be a second answer to "what is on the calendar", differing from the first in filters,
 * window semantics and timezone, and the two would only have to disagree once.
 *
 * There is no `restore` either, though rows are soft-deleted. The trash exists so a mis-click on a
 * shared calendar is recoverable at all; a full trash UI is a screen nobody has designed yet, and an
 * endpoint with no screen is a contract to keep for free.
 */
class CalendarEventController extends Controller
{
    public function __construct(private CalendarEventService $service) {}

    public function store(StoreCalendarEventRequest $request): CalendarEventResource
    {
        return CalendarEventResource::make(
            $this->service->create(CalendarEventDTO::fromRequest($request))->loadMissing('creator')
        );
    }

    public function show(CalendarEvent $event): CalendarEventResource
    {
        $this->authorize('view', $event);

        return CalendarEventResource::make($event->loadMissing('creator'));
    }

    /**
     * THE STATUS CODE SAYS WHICH RESOURCE CAME BACK, and it is set explicitly rather than inferred.
     *
     *   200 OK       the event named in the URL, rewritten. Every request that names no scope lands
     *                here, which is the whole of the compatibility guarantee.
     *   201 Created  a NEW event — a detached occurrence, or the second half of a split. The URL named
     *                the series being edited; this names the thing the user is now looking at.
     *
     * Laravel would infer the same two codes from `wasRecentlyCreated` on its own. It is written out
     * because it is a CONTRACT here, not a side effect: a client can tell from the status alone that the
     * id it holds has stopped being the id it should edit next, without parsing the body to notice.
     */
    public function update(UpdateCalendarEventRequest $request, CalendarEvent $event): JsonResponse
    {
        $dto = CalendarEventDTO::fromRequest($request);
        $occurrenceDate = (string) $request->resolvedOccurrenceDate();

        $written = match ($request->resolvedScope()) {
            CalendarEventScope::SERIES => $this->service->update($event, $dto),
            CalendarEventScope::OCCURRENCE => $this->service->detachOccurrence($event, $dto, $occurrenceDate),
            CalendarEventScope::FOLLOWING => $this->service->updateFollowing($event, $dto, $occurrenceDate),
        };

        return CalendarEventResource::make($written->loadMissing('creator'))
            ->response()
            ->setStatusCode($written->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function destroy(DestroyCalendarEventRequest $request, CalendarEvent $event): JsonResponse
    {
        $occurrenceDate = (string) $request->resolvedOccurrenceDate();

        match ($request->resolvedScope()) {
            CalendarEventScope::SERIES => $this->service->delete($event),
            CalendarEventScope::OCCURRENCE => $this->service->deleteOccurrence($event, $occurrenceDate),
            CalendarEventScope::FOLLOWING => $this->service->deleteFollowing($event, $occurrenceDate),
        };

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
