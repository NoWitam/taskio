<?php

namespace App\Modules\Bot\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bot\DTOs\BotVisualGenerationDTO;
use App\Modules\Bot\Http\Requests\ApproveBotVisualRequest;
use App\Modules\Bot\Http\Requests\DestroyBotVisualCandidateRequest;
use App\Modules\Bot\Http\Requests\GenerateBotVisualRequest;
use App\Modules\Bot\Http\Resources\BotResource;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Services\BotVisualIdentityService;
use App\Modules\Disk\Http\Resources\DiskAiEditResource;
use App\Modules\Disk\Models\File;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bot's VISUAL identity: create a likeness, approve one, drop one.
 *
 * Generation is ASYNC (the provider call takes tens of seconds), and rides the Disk's existing
 * status machinery rather than a second one: this returns 202 with a {@see DiskAiEditResource} and
 * the client follows it on `GET /api/disk/ai/image/{id}` — the same poll + broadcast the Disk
 * preview editor uses. When it reports `done` the candidate is ALREADY filed on the bot, so the
 * client just refetches the bot to see it.
 *
 * Curation is synchronous and returns the updated bot.
 */
class BotVisualController extends Controller
{
    public function __construct(
        private BotVisualIdentityService $service,
    ) {}

    /** Queue one generation (reference or description). 202 + the status row to follow. */
    public function generate(GenerateBotVisualRequest $request, Bot $bot): JsonResponse
    {
        $edit = $this->service->generate($bot, BotVisualGenerationDTO::fromRequest($request));

        return DiskAiEditResource::make($edit)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    /** Promote a candidate to the approved likeness. */
    public function approve(ApproveBotVisualRequest $request, Bot $bot): BotResource
    {
        $file = $this->service->resolveFile($bot, $request->string('file_id')->value());

        abort_if($file === null, Response::HTTP_NOT_FOUND);

        return BotResource::make(
            $this->service->approve($bot, $file)->loadMissing('creator')
        );
    }

    /**
     * Delete a candidate and its bytes. The {file} binding is workspace-scoped; the service refuses
     * anything that is not a candidate of THIS bot, and refuses the approved likeness outright.
     */
    public function destroyCandidate(DestroyBotVisualCandidateRequest $request, Bot $bot, File $file): BotResource
    {
        return BotResource::make(
            $this->service->removeCandidate($bot, $file)->loadMissing('creator')
        );
    }
}
