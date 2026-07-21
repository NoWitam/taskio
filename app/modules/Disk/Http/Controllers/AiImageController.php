<?php

namespace App\Modules\Disk\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Disk\Http\Requests\AiImageRequest;
use App\Modules\Disk\Http\Resources\DiskAiEditResource;
use App\Modules\Disk\Models\DiskAiEdit;
use App\Modules\Disk\Services\ImageAiService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * ASYNC AI image edit for the Disk preview editor. The provider call is slow (tens of seconds), so
 * the edit is QUEUED: store() validates + dispatches and returns 202 with a status id; the client
 * polls show() until the edit is done (image returned) or failed. Nothing lands on the disk here —
 * the result returns to the canvas and is saved through the normal content endpoints.
 */
class AiImageController extends Controller
{
    public function __construct(
        private ImageAiService $service,
    ) {}

    /** Dispatch: validate, enforce+count budget, persist inputs, queue the job. 202 + status id. */
    public function store(AiImageRequest $request): JsonResponse
    {
        $edit = $this->service->dispatch(
            $request->file('image'),
            $request->string('prompt')->value(),
            $request->file('mask'),
        );

        return DiskAiEditResource::make($edit)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    /**
     * Poll one edit's status. The route-model binding + WorkspaceScope resolve only the ACTIVE
     * workspace's edits, so a foreign id 404s at bind — no ownership check is needed beyond that.
     */
    public function show(DiskAiEdit $diskAiEdit): DiskAiEditResource
    {
        return DiskAiEditResource::make($diskAiEdit);
    }
}
