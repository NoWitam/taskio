<?php

namespace App\Modules\Disk\Http\Resources;

use App\Modules\Disk\Models\DiskFileDraft;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DiskFileDraft
 *
 * One user's file-edit draft. The `manifest` is the FE-owned, opaque JSON blob the editor autosaved
 * (loaded from storage into the transient `manifest_data` by {@see \App\Modules\Disk\Services\DraftService});
 * `base_ids` lists the live image base blobs the FE can fetch via GET /disk/{file}/draft/base/{baseId}.
 * `base_version` is the file's updated_at captured when the draft began, so the FE can warn if the
 * file changed underneath it. Shared by the POST response and the GET fetch.
 */
class DraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $manifest = (array) ($this->manifest_data ?? []);

        return [
            'kind' => $this->kind,
            'manifest' => $manifest,
            // Live image bases (ints). Authoritative source is manifest.baseIds, which the service
            // GCs storage against; a text draft has none.
            'base_ids' => $this->kind === 'image'
                ? array_values(array_map('intval', $manifest['baseIds'] ?? []))
                : [],
            'base_version' => $this->base_version,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
