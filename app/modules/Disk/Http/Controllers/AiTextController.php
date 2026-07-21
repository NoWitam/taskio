<?php

namespace App\Modules\Disk\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Disk\Http\Requests\AiTextRequest;
use App\Modules\Disk\Services\TextAiService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * SYNC AI text edit for the Disk preview editor. gpt-4o text edits are fast, so — unlike the queued
 * image edit — this runs inline: validate, call the provider, return the edited text. A provider /
 * transport failure collapses to a localized 502 (the raw provider body is never surfaced).
 */
class AiTextController extends Controller
{
    public function __construct(
        private TextAiService $service,
    ) {}

    public function store(AiTextRequest $request): JsonResponse
    {
        try {
            $text = $this->service->edit(
                $request->string('content')->value(),
                $request->string('prompt')->value(),
            );
        } catch (Throwable $e) {
            // Never leak the provider body: report it for ops, answer a localized 502 (the same
            // non-secret failure message the image path records).
            report($e);

            abort(Response::HTTP_BAD_GATEWAY, __('disk.ai.failed'));
        }

        return response()->json(['data' => ['text' => $text]]);
    }
}
