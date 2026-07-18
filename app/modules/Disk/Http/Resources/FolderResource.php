<?php

namespace App\Modules\Disk\Http\Resources;

use App\Modules\Disk\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Folder
 */
class FolderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'depth' => $this->depth(),
            // Ancestor ids, root first. The raw `path` is an internal encoding and is NOT
            // exposed — clients get the list they would parse out of it anyway.
            'ancestor_ids' => $this->ancestorIds(),
            // Only present when the caller asked for the counts (the tree needs it to know
            // whether a node is expandable without loading a level it may never open).
            'has_children' => $this->whenCounted('children', fn () => $this->children_count > 0),
            // Both counts, so the browser can show "N items" = subfolders + files (a folder
            // holding only subfolders is not "0 items").
            'children_count' => $this->whenCounted('children'),
            'files_count' => $this->whenCounted('files'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),

            // Server-authoritative capability flags: the UI hides what it may not do, the
            // policy still enforces it.
            'can_be_updated' => $user?->can('update', $this->resource) ?? false,
            'can_be_moved' => $user?->can('move', $this->resource) ?? false,
            'can_be_deleted' => $user?->can('delete', $this->resource) ?? false,
            // Restore is ownership-gated (the trash lists every member's folders); the browser
            // gates its Restore action on this so a non-owner is never offered a 403.
            'can_be_restored' => $user?->can('restore', $this->resource) ?? false,
        ];
    }
}
