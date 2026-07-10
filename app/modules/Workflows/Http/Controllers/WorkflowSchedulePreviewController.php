<?php

namespace App\Modules\Workflows\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workflows\Http\Requests\SchedulePreviewRequest;
use App\Modules\Workflows\Services\WorkflowScheduleService;
use Illuminate\Http\JsonResponse;

/**
 * Live SCHEDULE-PREVIEW endpoint (POST /workflows/meta/schedule-preview): projects the next N fire
 * instants of a proposed cadence so the FE schedule builder shows a running preview as the user
 * edits. Thin — authorization + the (empty-guard-off) block validation live in SchedulePreviewRequest,
 * the projection lives in WorkflowScheduleService::nextOccurrences (a pure function, no tenancy).
 *
 * The occurrences are ISO8601 UTC, formatted like WorkflowResource serves next_due_at (toISOString),
 * so the FE parses one shape everywhere. `empty` is true when the cadence yields no occurrence (an
 * over-constrained exclusion set) — surfaced as data for a pre-save warning, never a 422. `approximate`
 * is true ONLY for the interval family (every_n_minutes), whose live phase is set at activation, so a
 * now-anchored preview is indicative.
 */
class WorkflowSchedulePreviewController extends Controller
{
    public function __construct(
        private WorkflowScheduleService $schedule,
    ) {}

    public function __invoke(SchedulePreviewRequest $request): JsonResponse
    {
        $block = $request->scheduleBlock();
        $count = $request->occurrenceCount();

        $occurrences = $this->schedule->nextOccurrences($block, $count);

        return response()->json([
            'occurrences' => array_map(fn ($occurrence) => $occurrence->toISOString(), $occurrences),
            'count' => $count,
            'empty' => $occurrences === [],
            'approximate' => $this->schedule->isApproximate($block),
        ]);
    }
}
