<?php

namespace App\Modules\Disk\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Disk\DTOs\FolderDTO;
use App\Modules\Disk\Http\Requests\MoveFolderRequest;
use App\Modules\Disk\Http\Requests\StoreFolderRequest;
use App\Modules\Disk\Http\Requests\UpdateFolderRequest;
use App\Modules\Disk\Http\Resources\FolderResource;
use App\Modules\Disk\Models\Folder;
use App\Modules\Disk\Services\FolderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class FoldersController extends Controller
{
    public function __construct(
        private FolderService $service,
    ) {}

    /**
     * One level of the tree: the children of ?parent_id, or the workspace roots without it.
     * Level-at-a-time by design — the browser's tree loads lazily, so a workspace with a large
     * tree never pays for branches nobody opened.
     *
     * `?trashed=1` lists the workspace's trashed folders instead — FLAT (only empty folders
     * can be trashed and their parents may be gone, so hierarchy would be an illusion).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Folder::class);

        if ($request->boolean('trashed')) {
            return FolderResource::collection(
                $this->service->trashed()->loadCount(['children', 'files'])
            );
        }

        $parent = $request->filled('parent_id')
            ? Folder::query()->findOrFail($request->string('parent_id')->value())
            : null;

        return FolderResource::collection(
            $this->service->children($parent)->loadCount(['children', 'files'])
        );
    }

    /** A folder plus its breadcrumbs (root first) — the header of the browser. */
    public function show(Folder $folder): JsonResource|FolderResource
    {
        $this->authorize('view', $folder);

        return FolderResource::make($folder->loadCount(['children', 'files']))
            ->additional([
                'breadcrumbs' => FolderResource::collection($this->service->breadcrumbs($folder)),
            ]);
    }

    public function store(StoreFolderRequest $request): FolderResource
    {
        return FolderResource::make(
            $this->service->create(FolderDTO::fromRequest($request))
        );
    }

    public function update(UpdateFolderRequest $request, Folder $folder): FolderResource
    {
        return FolderResource::make(
            $this->service->rename($folder, $request->string('name')->trim()->value())
        );
    }

    /** Re-parent a folder with its whole subtree (null target = the workspace root). */
    public function move(MoveFolderRequest $request, Folder $folder): FolderResource
    {
        $target = $request->filled('target_folder_id')
            ? Folder::query()->findOrFail($request->string('target_folder_id')->value())
            : null;

        return FolderResource::make(
            $this->service->move($folder, $target)
        );
    }

    public function destroy(Folder $folder): JsonResponse
    {
        $this->authorize('delete', $folder);

        $this->service->trash($folder);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Restore a trashed folder. Resolved with withTrashed() rather than route-model binding,
     * which would 404 a soft-deleted row (mirrors the Forms/Workflows restore endpoints).
     */
    public function restore(string $id): FolderResource
    {
        $folder = Folder::withTrashed()->findOrFail($id);

        $this->authorize('restore', $folder);

        return FolderResource::make($this->service->restore($folder));
    }
}
