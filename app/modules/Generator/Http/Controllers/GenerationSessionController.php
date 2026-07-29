<?php

namespace App\Modules\Generator\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Disk\Http\Resources\FileResource;
use App\Modules\Generator\DTOs\CreateGenerationSessionDTO;
use App\Modules\Generator\DTOs\UpdateGenerationSessionDTO;
use App\Modules\Generator\Enums\GenerationRunMode;
use App\Modules\Generator\Http\Requests\RefineSessionPartRequest;
use App\Modules\Generator\Http\Requests\SaveGeneratedImageRequest;
use App\Modules\Generator\Http\Requests\StoreGenerationSessionRequest;
use App\Modules\Generator\Http\Requests\UpdateGenerationSessionRequest;
use App\Modules\Generator\Http\Resources\GenerationSessionResource;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\GeneratedImageExporter;
use App\Modules\Generator\Services\GeneratedImageStore;
use App\Modules\Generator\Services\GenerationSessionRefiner;
use App\Modules\Generator\Services\GenerationSessionRunManager;
use App\Modules\Generator\Services\GenerationSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generation SESSIONS — one execution of a template recipe into content. Thin: each method converts
 * request → DTO, calls a service/manager, and returns a resource. Authorization lives in the FormRequests
 * (mutations, via GenerationSessionPolicy) and the explicit authorize() calls (reads + the generate
 * action). The async run is kicked off by {@see GenerationSessionRunManager}, not inline.
 */
class GenerationSessionController extends Controller
{
    public function __construct(
        private GenerationSessionService $service,
        private GenerationSessionRunManager $runManager,
        private GenerationSessionRefiner $refiner,
        private GeneratedImageStore $images,
        private GeneratedImageExporter $exporter,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', GenerationSession::class);

        return GenerationSessionResource::lean(
            $this->service->index($request)
        );
    }

    public function store(StoreGenerationSessionRequest $request): GenerationSessionResource
    {
        return GenerationSessionResource::make(
            $this->service->create(CreateGenerationSessionDTO::fromRequest($request))->loadMissing('creator')
        );
    }

    public function show(GenerationSession $session): GenerationSessionResource
    {
        $this->authorize('view', $session);

        return GenerationSessionResource::make($session->loadMissing('creator'));
    }

    public function update(UpdateGenerationSessionRequest $request, GenerationSession $session): GenerationSessionResource
    {
        return GenerationSessionResource::make(
            $this->service->update($session, UpdateGenerationSessionDTO::fromRequest($request))->loadMissing('creator')
        );
    }

    /**
     * Kick off a generation run: atomically CLAIM the session and queue the async job. 202 + the session
     * now in `generating`; 409 when a run is already in flight (the claim did not win).
     */
    public function generate(GenerationSession $session): JsonResponse
    {
        $this->authorize('update', $session);

        if (!$this->runManager->claimAndDispatch($session)) {
            abort(409, __('generator.sessions.already_generating'));
        }

        return GenerationSessionResource::make($session->refresh()->loadMissing('creator'))
            ->response()
            ->setStatusCode(202);
    }

    public function destroy(GenerationSession $session): JsonResponse
    {
        $this->authorize('delete', $session);

        $this->service->delete($session);

        return response()->json(['message' => __('generator.sessions.deleted')]);
    }

    /**
     * Archive a session (R2 sub-stage 2d): set `archived_at`, exempting it from the lifecycle reaper's
     * trash + purge windows. Creator-only (`update`); the {session} binding is already tenant-scoped
     * (foreign/soft-deleted → 404 at bind), so archive operates only on a LIVE session in this workspace.
     * Idempotent, 200 + the updated session.
     */
    public function archive(GenerationSession $session): GenerationSessionResource
    {
        $this->authorize('update', $session);

        return GenerationSessionResource::make(
            $this->service->archive($session)->loadMissing('creator')
        );
    }

    /** Un-archive a session: clear `archived_at`, re-enrolling it in the retention windows. Creator-only. */
    public function unarchive(GenerationSession $session): GenerationSessionResource
    {
        $this->authorize('update', $session);

        return GenerationSessionResource::make(
            $this->service->unarchive($session)->loadMissing('creator')
        );
    }

    /**
     * REGENERATE one part (R2 sub-stage 2d): claim the session + queue a `regenerate` run that re-renders ONLY
     * this part fresh from the snapshot. 404 when the part key is unknown; 409 when a run is already in flight
     * (one op per session). 202 + the session now `generating`.
     */
    public function regenerate(GenerationSession $session, string $partKey): JsonResponse
    {
        $this->authorize('update', $session);
        $this->refiner->assertPart($session, $partKey);

        if (!$this->runManager->claimAndDispatch($session, GenerationRunMode::Regenerate, $partKey)) {
            abort(Response::HTTP_CONFLICT, __('generator.sessions.already_generating'));
        }

        return GenerationSessionResource::make($session->refresh()->loadMissing('creator'))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * REFINE one part with a free-text instruction (R2 sub-stage 2d): claim the session + queue a `refine` run
     * that revises this part's CURRENT output (text → an AI text revision; image → an AI edit of the current
     * image). This ONE endpoint backs both the per-part refine affordance AND the chat composer — the FE sends
     * the target partKey. 404 unknown part / 422 non-refinable part ({@see RefineSessionPartRequest} rejects a
     * blank instruction 422); 409 when already generating. 202 + the session now `generating`.
     */
    public function refine(RefineSessionPartRequest $request, GenerationSession $session, string $partKey): JsonResponse
    {
        $this->refiner->assertRefinablePart($session, $partKey);

        if (!$this->runManager->claimAndDispatch($session, GenerationRunMode::Refine, $partKey, $request->instruction())) {
            abort(Response::HTTP_CONFLICT, __('generator.sessions.already_generating'));
        }

        return GenerationSessionResource::make($session->refresh()->loadMissing('creator'))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * UNDO one part (R2 sub-stage 2d) — SYNCHRONOUS (no AI): restore the part's previous version and discard the
     * just-undone one. 404 unknown part; 409 while a run is generating OR when there is nothing to undo (empty
     * history). The generating / nothing-to-undo re-check is authoritative UNDER a row lock inside
     * {@see GenerationSessionRefiner::undo} (a concurrent double-undo / an undo racing a completing refine can't
     * double-apply). Returns 200 + the updated session.
     */
    public function undo(GenerationSession $session, string $partKey): GenerationSessionResource
    {
        $this->authorize('update', $session);
        $this->refiner->assertPart($session, $partKey);

        $this->refiner->undo($session, $partKey);

        return GenerationSessionResource::make($session->refresh()->loadMissing('creator'));
    }

    /**
     * Stream a part's CURRENT produced image (R2 sub-stage 2c; version-aware in 2d) so the chat can preview it.
     * Read-gated (any workspace member of a session they can `view`); the {session} binding is already tenant-
     * scoped (a foreign/soft-deleted id 404s at bind), and the store path is namespaced by workspace + session,
     * so a crafted $partKey can only ever reach THIS session's own images. The served version is the part's
     * CURRENT one resolved from `results` (an old/undone version is not publicly addressable); a part with no
     * current produced image (a failed / never-run part, or one with no version) → 404. Inline-safe like the
     * Disk serve: exact declared type + nosniff, and it is always a PNG we produced.
     */
    public function partImage(GenerationSession $session, string $partKey): StreamedResponse
    {
        $this->authorize('view', $session);

        $version = $this->refiner->currentImageVersion($session, $partKey);

        abort_if($version === null, Response::HTTP_NOT_FOUND);

        $path = $this->images->path($session->id, $partKey, $version);

        abort_unless(Storage::exists($path), Response::HTTP_NOT_FOUND);

        return Storage::response($path, 'image.png', [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    /**
     * Promote a produced image onto the user's Disk ("Zapisz na Dysk"). Authorization is DOUBLE (session
     * `update` + Disk `create`) in {@see SaveGeneratedImageRequest}; the export itself (current version →
     * bytes → the Disk-owned create path) lives in {@see GeneratedImageExporter}, so a server-side caller
     * can export the same way. A part with no produced image (failed / never run) → 404.
     */
    public function saveToDisk(SaveGeneratedImageRequest $request, GenerationSession $session, string $partKey): FileResource
    {
        return FileResource::make(
            $this->exporter->saveToDisk(
                $session,
                $partKey,
                $request->string('name')->trim()->value(),
                $request->string('folder_id')->value() ?: null,
            )
        );
    }
}
