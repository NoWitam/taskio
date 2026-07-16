<?php

namespace App\Modules\Workflows\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workflows\Http\Requests\ScheduleAssistRequest;
use App\Modules\Workflows\Services\WorkflowScheduleAssistService;
use Illuminate\Http\JsonResponse;

/**
 * AI schedule-assist endpoint: maps a natural-language schedule description onto the structured
 * config the FE schedule builder consumes (or reports what cannot be expressed, with an optional
 * alternative). Thin — authorization + input shape live in ScheduleAssistRequest, the rate limit,
 * agent run and (untrusted-model) re-validation live in WorkflowScheduleAssistService. A rate-limit
 * breach surfaces as a 429 from the service's ThrottleRequestsException.
 */
class WorkflowScheduleAssistController extends Controller
{
    public function __construct(
        private WorkflowScheduleAssistService $service,
    ) {}

    public function __invoke(ScheduleAssistRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->assist(
                prompt: $request->string('prompt')->value(),
                userId: $request->user()->id,
                tz: $request->string('tz')->value() ?: null,
                language: app()->getLocale() === 'en' ? 'en' : 'pl',
            ),
        ]);
    }
}
