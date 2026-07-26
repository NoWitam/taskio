<?php

namespace App\Modules\Disk\Services;

use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Modules\Labels\Models\Label;
use Illuminate\Support\Facades\DB;

/**
 * Materializes folders' ENFORCED labels onto the files beneath them (F3).
 *
 * A folder can enforce labels down its subtree (see the `folder_label` pivot, mode = 'enforced').
 * Rather than deriving that at read time, we keep real `labelables` rows on each file, marked
 * `enforced = true`, so the existing label filter, resource and changelog all keep working
 * unchanged — an enforced label is just a label that the user cannot remove.
 *
 * A file's TARGET enforced set is the union of the enforced labels of EVERY ancestor folder
 * (its own folder plus each folder on the materialized path). Recomputation is a FULL recompute
 * of that target — never incremental — so "un-enforce here while a parent still enforces" resolves
 * correctly. Manual (user-attached, `enforced = false`) rows are always preserved.
 *
 * Perf: enforcing on a root recomputes every file in the workspace. Fine at R1 volumes; a
 * per-folder memo is the obvious optimization if a workspace's file count grows.
 */
class FolderLabelEnforcer
{
    /**
     * Bring ONE file's enforced label rows in line with its ancestor folders' enforced labels.
     * Inserts newly-enforced labels, upgrades a colliding manual row to enforced, and drops rows
     * no longer enforced by any ancestor — leaving manual rows untouched.
     */
    public function syncFile(File $file): void
    {
        // Only disk-native files live in the folder tree; a resource-owned file or a temp has no
        // ancestor folders to inherit from.
        if ($file->fileable_type !== File::FOLDER_TYPE) {
            return;
        }

        $target = $this->enforcedLabelIdsFor($file);

        // Compare against the file's CURRENT rows split by the flag — let the DB evaluate the
        // boolean (pivot values are not cast, so a PHP-side (bool) on 'f'/'t' would misfire).
        $currentEnforced = $file->labels()->wherePivot('enforced', true)->pluck('labels.id')->all();
        $currentManual = $file->labels()->wherePivot('enforced', false)->pluck('labels.id')->all();

        $targetSet = array_flip($target);
        $enforcedSet = array_flip($currentEnforced);
        $manualSet = array_flip($currentManual);

        foreach ($target as $labelId) {
            if (isset($enforcedSet[$labelId])) {
                continue; // already enforced — nothing to do
            }
            if (isset($manualSet[$labelId])) {
                $file->labels()->updateExistingPivot($labelId, ['enforced' => true]); // upgrade manual → enforced
            } else {
                $file->labels()->attach($labelId, ['enforced' => true]); // newly enforced
            }
        }

        // Enforced rows no longer required by any ancestor are removed entirely.
        foreach ($currentEnforced as $labelId) {
            if (!isset($targetSet[$labelId])) {
                $file->labels()->detach($labelId);
            }
        }
    }

    /**
     * Seed a NEW file with its folder's RECOMMENDED labels as manual (removable) rows — the
     * default-on-but-optional half of governance. Unlike enforced labels this does not cascade from
     * ancestors and applies once at creation; a label already on the file (manual or enforced) is
     * left untouched, and a root file (no folder) gets nothing.
     */
    public function seedRecommended(File $file, ?Folder $folder): void
    {
        if ($folder === null) {
            return;
        }

        $recommended = $this->recommendedLabelIdsFor($folder);
        $existing = $file->labels()->pluck('labels.id')->all();

        foreach (array_diff($recommended, $existing) as $labelId) {
            $file->labels()->attach($labelId, ['enforced' => false]);
        }
    }

    /**
     * Recompute every disk file in $folder's subtree ($folder itself + all descendants). Used when
     * a folder's enforced set changes, or when a subtree is moved (its files' ancestors changed).
     */
    public function syncSubtree(Folder $folder): void
    {
        $subtreeFolderIds = Folder::query()
            ->where(fn ($q) => $q->whereKey($folder->getKey())->orWhere('path', 'like', $folder->descendantPrefix() . '%'))
            ->pluck('id');

        File::query()
            ->diskNative()
            ->whereIn('fileable_id', $subtreeFolderIds)
            ->get()
            ->each(fn (File $file) => $this->syncFile($file));
    }

    /**
     * The label ids enforced onto $file: the DISTINCT enforced labels of its folder and every
     * ancestor on the materialized path. A root file (no folder) inherits nothing. Soft-deleted
     * labels are dropped so a removed label never materializes.
     *
     * @return array<int, string>
     */
    private function enforcedLabelIdsFor(File $file): array
    {
        // A disk file's container folder is its fileable_id; NULL means the workspace root.
        if ($file->fileable_id === null) {
            return [];
        }

        $folder = Folder::query()->find($file->fileable_id);

        if ($folder === null) {
            return [];
        }

        // The folder itself plus its ancestors (the path holds ancestor ids, root first).
        $folderIds = array_merge([$folder->getKey()], $folder->ancestorIds());

        $rawLabelIds = DB::table('folder_label')
            ->whereIn('folder_id', $folderIds)
            ->where('mode', Folder::LABEL_MODE_ENFORCED)
            ->distinct()
            ->pluck('label_id')
            ->all();

        if ($rawLabelIds === []) {
            return [];
        }

        // Re-scope through the workspace-scoped, soft-delete-aware Label query so a deleted or
        // foreign id can never be materialized.
        return Label::query()->whereIn('id', $rawLabelIds)->pluck('id')->all();
    }

    /**
     * The label ids a folder RECOMMENDS (its own governance only — recommendations do not cascade).
     * Soft-deleted labels are dropped.
     *
     * @return array<int, string>
     */
    private function recommendedLabelIdsFor(Folder $folder): array
    {
        $rawLabelIds = DB::table('folder_label')
            ->where('folder_id', $folder->getKey())
            ->where('mode', Folder::LABEL_MODE_RECOMMENDED)
            ->pluck('label_id')
            ->all();

        if ($rawLabelIds === []) {
            return [];
        }

        return Label::query()->whereIn('id', $rawLabelIds)->pluck('id')->all();
    }
}
