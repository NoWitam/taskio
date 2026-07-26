<?php

namespace App\Modules\Disk\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Disk\Http\Requests\DraftRequest;
use App\Modules\Disk\Http\Resources\DraftResource;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Services\DraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Per-user autosave DRAFTS of a Disk file edit. The preview editor autosaves its in-progress state
 * (POST) so a refresh/crash never loses work, fetches it back on reopen (GET / GET base) and clears it
 * on an explicit Save (DELETE). Nothing here touches the MAIN file — that is overwritten only through
 * FileService::replaceContent.
 *
 * Every method authorizes `view` on the file (like disk.info) and then scopes to the AUTH user, so a
 * member may only ever reach their OWN draft of a file they can see.
 */
class DraftController extends Controller
{
    public function __construct(
        private DraftService $service,
    ) {}

    /**
     * Autosave: upsert the draft, store the manifest + any new base blobs, GC dropped bases. A plain
     * POST (like disk.replace-content) since PHP only parses a multipart body on POST. Always 200 —
     * an idempotent upsert, never a "created" resource (so a first autosave and a re-save read
     * identically to the FE, instead of the Resource's default 201-on-recently-created).
     */
    public function store(DraftRequest $request, File $file): JsonResponse
    {
        return DraftResource::make(
            $this->service->put($file, $request->user(), $request->manifest(), $request->baseVersion(), $request->baseFiles())
        )->response()->setStatusCode(HttpResponse::HTTP_OK);
    }

    /** Fetch this user's draft for the file, or 404 when none exists. */
    public function show(Request $request, File $file): DraftResource
    {
        $this->authorize('view', $file);

        $draft = $this->service->get($file, $request->user());

        abort_if($draft === null, HttpResponse::HTTP_NOT_FOUND);

        return DraftResource::make($draft);
    }

    /** Stream one base PNG of this user's draft (private cache), or 404. */
    public function base(Request $request, File $file, string $baseId): Response
    {
        $this->authorize('view', $file);

        $bytes = $this->service->baseBytes($file, $request->user(), (int) $baseId);

        abort_if($bytes === null, HttpResponse::HTTP_NOT_FOUND);

        return response($bytes, HttpResponse::HTTP_OK, [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** Discard this user's draft (row + storage dir). Idempotent — 204 even when none exists. */
    public function destroy(Request $request, File $file): Response
    {
        $this->authorize('view', $file);

        $this->service->forget($file, $request->user());

        return response()->noContent();
    }
}
