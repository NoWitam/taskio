<?php

namespace App\Modules\Calendar\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Calendar\DTOs\CalendarEventDTO;
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
 * (create/update, via CalendarEventPolicy) and in the one explicit authorize() below (destroy, which
 * has no request body to validate).
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

    public function update(UpdateCalendarEventRequest $request, CalendarEvent $event): CalendarEventResource
    {
        return CalendarEventResource::make(
            $this->service->update($event, CalendarEventDTO::fromRequest($request))->loadMissing('creator')
        );
    }

    public function destroy(CalendarEvent $event): JsonResponse
    {
        $this->authorize('delete', $event);

        $this->service->delete($event);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
