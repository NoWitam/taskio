<?php

namespace App\Modules\Disk\Http\Resources;

use App\Modules\Disk\Models\Folder;
use App\Modules\Labels\Models\Label;
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
            'description' => $this->description,
            'icon' => $this->icon,
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
            // Governance labels: each label with its `mode` (enforced | recommended) and `locked`.
            // The folder's OWN declarations (from `folder_label`, editable) PLUS the enforced labels
            // it INHERITS from ancestors (locked, `mode=enforced`) — so an enforced label applies to
            // subfolders exactly as it does to files. Only present when eager-loaded (the drawer/show
            // path); the tile grid does not carry them.
            'labels' => $this->when(
                $this->relationLoaded('labels'),
                fn () => $this->governanceLabels(),
            ),
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

    /**
     * The merged governance list: inherited-enforced labels (locked) first, then the folder's own
     * declarations — with any own row for a label that is ALSO inherited dropped (the inherited
     * lock wins, since a descendant cannot un-enforce what an ancestor enforces).
     *
     * @return array<int, array{id: string, name: string, color: ?string, icon: ?string, mode: string, locked: bool}>
     */
    private function governanceLabels(): array
    {
        $inherited = $this->relationLoaded('inheritedEnforcedLabels')
            ? $this->inheritedEnforcedLabels
            : collect();

        $inheritedIds = $inherited->pluck('id')->all();

        $inheritedRows = $inherited->map(fn (Label $label) => [
            'id' => $label->id,
            'name' => $label->name,
            'color' => $label->color,
            'icon' => $label->icon?->value,
            'mode' => Folder::LABEL_MODE_ENFORCED,
            'locked' => true,
        ]);

        $ownRows = $this->labels
            ->reject(fn (Label $label) => in_array($label->id, $inheritedIds, true))
            ->map(fn (Label $label) => [
                'id' => $label->id,
                'name' => $label->name,
                'color' => $label->color,
                'icon' => $label->icon?->value,
                'mode' => $label->pivot->mode,
                'locked' => false,
            ]);

        return $inheritedRows->concat($ownRows)->values()->all();
    }
}
