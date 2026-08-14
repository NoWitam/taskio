<?php

use App\Http\Middleware\RequireWorkspace;
use App\Modules\Calendar\Http\Controllers\CalendarEventController;
use App\Modules\Calendar\Http\Controllers\CalendarOccurrenceController;
use Illuminate\Support\Facades\Route;

/**
 * Calendar read API.
 *
 * Every route is gated by RequireWorkspace, not merely by auth: the calendar answers "what is happening
 * in THIS workspace", so a request without an active tenant has no answer at all — the sources' own
 * workspace scopes would go inert and the grid would aggregate across tenants. RequireWorkspace refuses
 * that with a 400 before anything is looked up, and ResolveWorkspace has already refused a workspace the
 * caller is not a member of with a 403.
 */
Route::middleware(['auth:sanctum', RequireWorkspace::class])
    ->prefix('calendar')
    ->group(function () {
        // The READ path: everything on the grid, from every source, merged. There is deliberately no
        // `GET events` beside it — see CalendarEventController for why a second list would be a second
        // answer to the same question.
        Route::get('occurrences', [CalendarOccurrenceController::class, 'index'])
            ->name('calendar.occurrences.index');

        // The WRITE path, and the module's only one: the events the Calendar itself owns. The literal
        // `occurrences` segment above is declared first and these are uuid-constrained, so no id can
        // ever be read as a route word or vice-versa.
        Route::post('events', [CalendarEventController::class, 'store'])
            ->name('calendar.events.store');
        Route::get('events/{event}', [CalendarEventController::class, 'show'])
            ->whereUuid('event')
            ->name('calendar.events.show');
        Route::put('events/{event}', [CalendarEventController::class, 'update'])
            ->whereUuid('event')
            ->name('calendar.events.update');
        Route::delete('events/{event}', [CalendarEventController::class, 'destroy'])
            ->whereUuid('event')
            ->name('calendar.events.destroy');
    });
