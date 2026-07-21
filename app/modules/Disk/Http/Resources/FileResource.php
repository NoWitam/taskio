<?php

namespace App\Modules\Disk\Http\Resources;

use App\Modules\Disk\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin File
 *
 * STRICTLY ADDITIVE: this resource is consumed verbatim by the tasks frontend (the
 * TaskAttachment type), the report card and the report preview. The original keys — notably
 * `path` (the download URL, NOT the storage path) and the legacy `created_at` string format —
 * keep their exact shape; the disk's own fields were added alongside, with ISO dates under
 * new keys.
 */
class FileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            // --- The original attachment contract (do not reshape) -------------------
            'id' => $this->id,
            'name' => $this->name,
            'path' => route('disk.show', ['file' => $this->id]),
            'type' => $this->type,
            'size' => $this->size,
            'size_human' => $this->formatBytes($this->size),
            'created_at' => $this->created_at->format('Y-m-d H:i'),

            // --- Disk metadata --------------------------------------------------------
            'description' => $this->description,
            'mime_type' => $this->mime_type,
            'folder_id' => $this->folder_id,
            'folder' => FolderResource::make($this->whenLoaded('folder')),
            // Each label plus `locked` — true when it was materialized by a folder's governance
            // (pivot `enforced`), so the UI shows a lock instead of a remove control (F3). The flag
            // is coerced representation-agnostically (a non-cast pivot may arrive as bool / 1 / 't').
            'labels' => $this->whenLoaded('labels', fn () => $this->labels->map(fn ($label) => [
                'id' => $label->id,
                'name' => $label->name,
                'color' => $label->color,
                'description' => $label->description,
                'icon' => $label->icon?->value,
                'locked' => in_array($label->pivot->enforced, [true, 1, '1', 't', 'true'], true),
            ])->all()),

            // Where the file came from: 'disk' when it lives here in its own right (its container
            // is a folder, or it is a not-yet-placed temp), else the owning resource's morph alias
            // ('task', 'form_report', …).
            'source' => ($this->fileable_type === null || $this->fileable_type === File::FOLDER_TYPE)
                ? 'disk'
                : $this->fileable_type,
            'created_at_iso' => $this->created_at?->toIso8601String(),
            'updated_at_iso' => $this->updated_at?->toIso8601String(),
            'disk_trashed_at' => $this->disk_trashed_at?->toIso8601String(),

            // Whether the CURRENT user has an in-progress autosave DRAFT of this file (their
            // unsaved edits) — the grid flags those tiles. Computed only by the browser LIST
            // query (a per-row withExists); every other response (info/upload/replace) leaves the
            // attribute unset → false, which is correct: a fresh upload has no draft, and an
            // explicit Save clears it.
            'has_draft' => (bool) ($this->draft_exists ?? false),

            // --- Server-authoritative capabilities ------------------------------------
            'can_be_updated' => $user?->can('update', $this->resource) ?? false,
            'can_be_moved' => $user?->can('move', $this->resource) ?? false,
            'can_be_deleted' => $user?->can('delete', $this->resource) ?? false,
            // Trash actions are ownership-gated (a member sees the whole workspace's trash but
            // may only restore/purge what they own), so the browser must gate on these — never
            // on can_be_deleted — or a non-owner is offered actions the policy will 403.
            'can_be_restored' => $user?->can('restore', $this->resource) ?? false,
            'can_be_force_deleted' => $user?->can('forceDelete', $this->resource) ?? false,
        ];
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(log($bytes) / log(1024));
        $pow = min($pow, count($units) - 1);

        return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
    }
}
