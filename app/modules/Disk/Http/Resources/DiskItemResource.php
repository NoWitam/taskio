<?php

namespace App\Modules\Disk\Http\Resources;

use App\Modules\Disk\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One item in the unified folder browse (the `GET /disk/items/{folder?}` staged paginator). The
 * items are a MIX of folders and files, so this resource delegates by model type to the existing
 * {@see FolderResource}/{@see FileResource} — keeping their shapes byte-for-byte — and only prepends
 * a `kind` discriminator so the frontend can split the one list back into folders and files.
 */
class DiskItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isFolder = $this->resource instanceof Folder;

        $inner = $isFolder
            ? (new FolderResource($this->resource))->toArray($request)
            : (new FileResource($this->resource))->toArray($request);

        return ['kind' => $isFolder ? 'folder' : 'file'] + $inner;
    }
}
