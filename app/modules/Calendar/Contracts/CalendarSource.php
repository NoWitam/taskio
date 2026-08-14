<?php

namespace App\Modules\Calendar\Contracts;

use App\Modules\Calendar\DTOs\CalendarSourceResult;
use App\Modules\Calendar\DTOs\CalendarWindow;

/**
 * Everything the Calendar module knows about anything it displays.
 *
 * THE CALENDAR KNOWS NOBODY. It owns this contract and a registry; the modules that have something to
 * put on a grid implement it IN THEIR OWN NAMESPACE and register themselves from their own service
 * provider. The code that turns a task into an occurrence lives in the Tasks module, not here — because
 * the moment Calendar names Tasks, adding the next source means editing Calendar, and the one property
 * worth protecting is gone.
 *
 * The bar is concrete and pinned by CalendarModuleBoundaryTest: adding a fourth source (R4 Publishing)
 * must not require changing a single line under app/modules/Calendar.
 *
 * Implementations must be CHEAP TO CONSTRUCT — they are registered lazily and resolved only when a
 * window actually asks for them — and must respect the budgets carried on the window
 * ({@see CalendarWindow::$maxOccurrencesPerItem}, {@see CalendarWindow::$maxOccurrences}) rather than
 * trusting the caller to trim afterwards.
 *
 * Failing is survivable: the registry logs and skips a source that throws OR that returns anything other
 * than CalendarOccurrence objects, so one broken module cannot blank a screen that is mostly other
 * modules' data. It is not, however, a substitute for a source that knows its own limits.
 *
 * Order your query ASCENDING when you bound it. The merged set is trimmed from the LATE end, so a source
 * that took its own slice from the newest end would hand over exactly the rows the trim discards first.
 */
interface CalendarSource
{
    /**
     * Stable public id — 'task', 'workflow_schedule', 'workflow_run'. It appears in request filters,
     * in every occurrence id and in saved user views, so renaming one breaks stored filters.
     */
    public function id(): string;

    /**
     * Human name for the source's filter chip, ALREADY TRANSLATED (`__()`).
     *
     * Server-side on purpose: a frontend that had to own a label per source would need editing for
     * every new source, which is exactly the coupling this module is built to avoid.
     */
    public function label(): string;

    /**
     * Everything this source puts inside the window, PLUS an account of anything it had to leave out.
     *
     * Order is irrelevant — the query service sorts the merged set — but the budgets on the window are
     * binding, and a source that hits one must SAY SO in the result's truncations rather than returning
     * a short list that looks complete. Nothing downstream can reconstruct the difference.
     *
     * Use {@see CalendarSourceResult::complete()} when nothing was left out.
     */
    public function occurrences(CalendarWindow $window): CalendarSourceResult;
}
