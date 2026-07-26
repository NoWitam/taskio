<?php

namespace App\Modules\Disk\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Disk\DTOs\DiskItemFilters;
use App\Modules\Disk\DTOs\UpdateFileDTO;
use App\Modules\Disk\Http\Requests\CopyFileRequest;
use App\Modules\Disk\Http\Requests\ReplaceFileContentRequest;
use App\Modules\Disk\Http\Requests\RestoreFileRequest;
use App\Modules\Disk\Http\Requests\StoreFileRequest;
use App\Modules\Disk\Http\Requests\UpdateFileRequest;
use App\Modules\Disk\Http\Requests\UploadTempFileRequest;
use App\Modules\Disk\Http\Resources\DiskItemResource;
use App\Modules\Disk\Http\Resources\FileResource;
use App\Modules\Disk\Http\Resources\FolderResource;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Modules\Disk\Services\FileService;
use App\Modules\Disk\Services\FolderService;
use App\Modules\Disk\Services\ThumbnailService;
use App\Support\Pagination\StagedCursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FilesController extends Controller
{
    /**
     * Mime types a browser may RENDER on our own origin (?inline=1). Everything else is
     * forced to download, so an uploaded .html can never execute as a same-origin document
     * (a stored-XSS shape). Keep these lists minimal and additive.
     */
    private const INLINE_SAFE_MIME_PREFIXES = ['image/'];

    private const INLINE_SAFE_MIME_TYPES = ['application/pdf'];

    /**
     * Carve-outs that the prefixes above would otherwise wave through. SVG is an XML
     * DOCUMENT, not a bitmap: rendered top-level it executes its own <script> same-origin,
     * and `nosniff` cannot help because the declared type is honest. It downloads instead.
     * (An <img src> embed stays safe and is unaffected — browsers never run scripts there.)
     */
    private const INLINE_UNSAFE_MIME_TYPES = ['image/svg+xml', 'image/svg'];

    public function __construct(
        private FileService $service,
        private FolderService $folders,
        private ThumbnailService $thumbnails,
    ) {}

    /** Browse the disk (or its trash with ?trashed=1). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', File::class);

        return FileResource::collection($this->service->index($request));
    }

    /**
     * The unified contents of a folder (null = the workspace root): its subfolders AND its
     * disk-native files as ONE cursor-paginated list (folders first, then files), plus the
     * folder's breadcrumbs. Replaces the old pair of `?parent_id=` (folders) + `?folder_id=`
     * (files) calls for browsing. Backed by {@see StagedCursorPaginator} — folders are stage 0,
     * files stage 1 — so a single `?cursor=` walks both.
     */
    public function items(Request $request, ?Folder $folder = null): AnonymousResourceCollection
    {
        $this->authorize('viewAny', File::class);

        $filters = DiskItemFilters::fromRequest($request);

        // A type filter can drop a whole stage (e.g. "only images" → no folders stage; "only folders"
        // → no files stage). Folders come first, so their stage stays declared first when present.
        $paginator = StagedCursorPaginator::make(24);
        if ($filters->includeFolders) {
            $paginator->stage('folders', fn () => $this->folders->childrenQuery($folder, $filters));
        }
        if ($filters->includeFiles) {
            $paginator->stage('files', fn () => $this->service->diskFilesQuery($folder, $filters));
        }
        $page = $paginator->paginate($request->query('cursor'));

        // Eager-load the creator on every item (folders + files both use HasCreator) so the
        // per-item can_be_* capability flags — which resolve through the policy → creator — don't
        // N+1. The staged result's loadMissing batches this per model type.
        $page->loadMissing('creator');

        return DiskItemResource::collection($page)->additional([
            // The open folder itself (null at the root) + its ancestors (root first). The
            // materialized path never holds self, so the client appends `folder` to `breadcrumbs`
            // to get a trail ending at the current level.
            'folder' => $folder ? FolderResource::make($folder->loadCount(['children', 'files'])) : null,
            'breadcrumbs' => FolderResource::collection($folder ? $this->folders->breadcrumbs($folder) : []),
        ]);
    }

    /**
     * The read-only "Zasoby" tree: resource-owned files (attachments, report outputs) grouped
     * by type and created-at bucket. A bucket's files are then browsed through index() with
     * ?source=<type>&bucket=<key>.
     */
    public function resources(): JsonResponse
    {
        $this->authorize('viewAny', File::class);

        return response()->json(['data' => $this->service->resourceTree()]);
    }

    public function store(StoreFileRequest $request): FileResource
    {
        $folder = $request->filled('folder_id')
            ? Folder::query()->findOrFail($request->string('folder_id')->value())
            : null;

        return FileResource::make(
            $this->service->store($request->file('file'), $folder)->load(['labels', 'folder'])
        );
    }

    /**
     * Stream a file's bytes. `?inline=1` asks the browser to render it in place (honoured
     * only for {@see INLINE_SAFE_MIME_PREFIXES}/{@see INLINE_SAFE_MIME_TYPES}); otherwise
     * it downloads under its original name.
     *
     * Streamed, never buffered: reading a 100MB upload into a string (the old
     * `Storage::get()` path) put the whole binary in PHP's memory per request.
     *
     * The workspace gate + tenant-scoped binding happen upstream in middleware, so a file
     * that binds here is already this workspace's.
     */
    public function show(File $file): StreamedResponse
    {
        // The row can outlive its blob (legacy/interrupted uploads). Without this guard
        // Storage::response() throws on the Content-Length lookup -> a 500 instead of a 404.
        abort_unless(Storage::exists($file->path), Response::HTTP_NOT_FOUND);

        $mime = $file->mime_type ?: 'application/octet-stream';

        return Storage::response(
            $file->path,
            // makeDisposition() throws on a filename containing a slash. Uploads can't carry
            // one (Symfony basenames the client name), but a renamed file could.
            basename($file->name),
            [
                'Content-Type' => $mime,
                // Serve exactly the type we declare: never let a browser sniff an upload
                // into something executable.
                'X-Content-Type-Options' => 'nosniff',
            ],
            request()->boolean('inline') && $this->rendersSafelyInline($mime) ? 'inline' : 'attachment',
        );
    }

    /**
     * A file's metadata as JSON — the preview's deep-link/refresh source. Deliberately separate
     * from show(): the bare GET /{file} serves the BINARY and cannot double as a JSON endpoint.
     */
    public function info(File $file): FileResource
    {
        $this->authorize('view', $file);

        // The draft flag too, so a DEEP-LINKED file (opened outside the grid) still tells the editor
        // whether to fetch its draft — the frontend probes GET /{file}/draft only when `has_draft`
        // (serialized from `draft_exists`) is true.
        $file->load(['labels', 'folder'])->loadExists('draft');

        return FileResource::make($file);
    }

    /**
     * A small first-page PNG raster of a PDF — the file grid's real thumbnail instead of a glyph.
     * Authorized like info(). The service returns null for a non-PDF, when thumbnails are disabled,
     * or when rendering is unavailable / fails, which is a 404 here (never a 500) — the grid then
     * falls back to the type glyph. Privately cacheable: the bytes are derived from a file only the
     * workspace can read.
     */
    public function thumbnail(File $file): Response
    {
        $this->authorize('view', $file);

        $png = $this->thumbnails->render($file);

        abort_if($png === null, Response::HTTP_NOT_FOUND);

        return response($png, Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** Metadata only: rename, describe, tag, move between folders. */
    public function update(UpdateFileRequest $request, File $file): FileResource
    {
        return FileResource::make(
            $this->service->update($file, UpdateFileDTO::fromRequest($request))
        );
    }

    /**
     * Overwrite the file's CONTENT (the preview editor's "Zapisz") — the row keeps its identity,
     * the blob and content-derived columns swap. Disk-native files only (the service guards).
     */
    public function replaceContent(ReplaceFileContentRequest $request, File $file): FileResource
    {
        return FileResource::make(
            $this->service->replaceContent($file, $request->file('file'))
        );
    }

    /** Move to the disk trash (recoverable). */
    public function destroy(File $file): JsonResponse
    {
        $this->authorize('delete', $file);

        $this->service->trash($file);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * What a restore would do: where the file came from, whether that place still exists, and
     * therefore whether the user must pick a new one. The dialog renders this.
     */
    public function restorePreview(string $id): JsonResponse
    {
        $file = File::withTrashed()->findOrFail($id);

        $this->authorize('restore', $file);

        $preview = $this->service->restorePreview($file, $this->folders);

        // The resources are passed as OBJECTS, never ->toArray()'d by hand: that would skip
        // the pipeline that strips unloaded relations, and a nested whenLoaded() would blow up
        // on its MissingValue placeholder.
        return response()->json([
            'data' => [
                'file' => FileResource::make($file->load(['labels', 'folder'])),
                'original_folder' => $preview['original_folder']
                    ? FolderResource::make($preview['original_folder'])
                    : null,
                'breadcrumbs' => FolderResource::collection($preview['breadcrumbs']),
                'can_restore_in_place' => $preview['can_restore_in_place'],
                'reason' => $preview['reason'],
            ],
        ]);
    }

    /**
     * Restore from the trash. Resolved with withTrashed() rather than route-model binding,
     * which would 404 a soft-deleted row (mirrors the Forms/Workflows restore endpoints).
     */
    public function restore(RestoreFileRequest $request, string $id): FileResource
    {
        $file = File::withTrashed()->findOrFail($id);

        $this->authorize('restore', $file);

        $target = $request->filled('target_folder_id')
            ? Folder::query()->findOrFail($request->string('target_folder_id')->value())
            : null;

        return FileResource::make(
            $this->service->restore($file, $target, $request->has('target_folder_id'), $this->folders)
        );
    }

    /** Permanent deletion — the file AND its bytes. */
    public function forceDestroy(string $id): JsonResponse
    {
        $file = File::withTrashed()->findOrFail($id);

        $this->authorize('forceDelete', $file);

        $this->service->forceDelete($file);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    public function uploadTemp(UploadTempFileRequest $request): FileResource
    {
        return FileResource::make(
            $this->service->upload($request->file('file'), null)
        );
    }

    /**
     * "Pick from Disk": copy an existing file into a fresh temp the caller owns, returning it
     * exactly like /disk/temp does so a form/workflow file field treats a pick and an upload
     * identically. Any workspace member may read (and therefore copy) a workspace file; the
     * route binding already scoped $file to this workspace and 404'd a trashed one.
     */
    public function copyToTemp(File $file): FileResource
    {
        $this->authorize('view', $file);

        return FileResource::make(
            $this->service->copyToTemp($file)->load(['labels', 'folder'])
        );
    }

    /**
     * "Copy" action: duplicate a file into the disk under a new name and (optional) folder. The
     * source only needs to be readable ({@see FilePolicy::view}); the destination + name are
     * validated by CopyFileRequest, whose authorize() gates the create.
     */
    public function copy(CopyFileRequest $request, File $file): FileResource
    {
        $this->authorize('view', $file);

        $folder = $request->filled('folder_id')
            ? Folder::query()->findOrFail($request->string('folder_id')->value())
            : null;

        return FileResource::make(
            $this->service->copyToDisk($file, $folder, $request->string('name')->trim()->value())
        );
    }

    /** Whether $mime is safe for a browser to render on our origin. */
    private function rendersSafelyInline(string $mime): bool
    {
        // Normalize before matching: compare the bare type, never a cased or
        // parameterised variant (`IMAGE/SVG+XML`, `image/svg+xml; charset=utf-8`).
        $mime = strtolower(trim(explode(';', $mime, 2)[0]));

        // Deny wins over allow — the prefixes below are broad on purpose.
        if (in_array($mime, self::INLINE_UNSAFE_MIME_TYPES, true)) {
            return false;
        }

        if (in_array($mime, self::INLINE_SAFE_MIME_TYPES, true)) {
            return true;
        }

        foreach (self::INLINE_SAFE_MIME_PREFIXES as $prefix) {
            if (str_starts_with($mime, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
