<?php

namespace App\Modules\Disk\Services;

use App\Modules\Changelog\Managers\BagTracker;
use App\Modules\Changelog\Managers\ChangelogManager;
use App\Modules\Disk\DTOs\DiskItemFilters;
use App\Modules\Disk\DTOs\FolderDTO;
use App\Modules\Disk\DTOs\UpdateFolderDTO;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Modules\Labels\Models\Label;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The folder tree. Every read and write here rides Eloquent, so WorkspaceScope (shared mode)
 * and the tenant connection (own mode) apply exactly as everywhere else.
 *
 * Folders are DB-logical only: nothing in this service touches storage, because a file's blob
 * path is a flat uuid that never encodes its folder.
 */
class FolderService
{
    /**
     * The direct children of $parent (or the workspace roots when null), name-ordered — the
     * shape the lazy-loading tree asks for one level at a time.
     */
    public function children(?Folder $parent): Collection
    {
        return Folder::query()
            ->where('parent_id', $parent?->getKey())
            ->orderBy('name')
            ->get();
    }

    /**
     * The folders shown for the unified items endpoint's "folders" stage, filtered/sorted per
     * $filters. `where` decides the reach — this folder's children, its whole subtree, or every
     * folder (the whole disk). Counts are eager-loaded for the "N items" hint, and the order ENDS
     * in the primary key — a cursor seek on `name` alone would skip/duplicate at a page edge.
     */
    public function childrenQuery(?Folder $parent, DiskItemFilters $filters): Builder
    {
        $query = Folder::query()->withCount(['children', 'files']);

        // Subtree at the ROOT is the whole disk (no prefix to anchor on).
        if ($filters->where === DiskItemFilters::WHERE_EVERYWHERE || ($filters->where === DiskItemFilters::WHERE_SUBTREE && $parent === null)) {
            // every folder — no scoping
        } elseif ($filters->where === DiskItemFilters::WHERE_SUBTREE) {
            $query->where('path', 'like', $parent->descendantPrefix() . '%');
        } else {
            $query->where('parent_id', $parent?->getKey());
        }

        $query->search(['name'], $filters->search); // folders match on name only

        $column = $filters->sort === 'created_at' ? 'created_at' : 'name';

        return $query->orderBy($column, $filters->dir)->orderBy('id', $filters->dir);
    }

    /**
     * Every trashed folder of the workspace, FLAT and name-ordered. Deliberately not a tree:
     * only empty folders can be trashed, and a trashed folder's parent may itself be gone, so
     * hierarchy would be an illusion — the trash view lists them side by side.
     */
    public function trashed(): Collection
    {
        return Folder::onlyTrashed()
            ->orderBy('name')
            ->get();
    }

    /**
     * A folder's ancestors, root first — ONE query, no recursive walk: the materialized path
     * already IS the ancestor list. Trashed ancestors are included so a breadcrumb never
     * loses a name (the caller decides how to render them).
     */
    public function breadcrumbs(Folder $folder): Collection
    {
        $ids = $folder->ancestorIds();

        if ($ids === []) {
            return new Collection;
        }

        $byId = Folder::withTrashed()->whereIn('id', $ids)->get()->keyBy('id');

        // whereIn returns arbitrary order; the path defines the real one.
        return new Collection(array_values(array_filter(array_map(
            fn (string $id) => $byId->get($id),
            $ids,
        ))));
    }

    public function create(FolderDTO $dto): Folder
    {
        $parent = $dto->parentId !== null ? Folder::query()->findOrFail($dto->parentId) : null;

        $this->guardDepth($parent === null ? 1 : $parent->depth() + 1);
        $this->guardSiblingName($dto->name, $parent?->getKey());

        // path is computed by the model from the parent — never accepted from input.
        $folder = Folder::create([
            'name' => $dto->name,
            'parent_id' => $parent?->getKey(),
        ]);

        $this->inheritGovernance($folder, $parent);

        return $folder;
    }

    /**
     * A new subfolder inherits its parent's RECOMMENDED labels as its OWN (an adjustable starting
     * point — the subfolder can change them). ENFORCED labels are deliberately NOT copied: they are
     * inherited by DERIVATION ({@see inheritedEnforcedLabels}) and shown locked, so un-enforcing at
     * an ancestor cleanly removes them everywhere and a descendant can never opt out. A root folder
     * inherits nothing.
     */
    private function inheritGovernance(Folder $folder, ?Folder $parent): void
    {
        if ($parent === null) {
            return;
        }

        $recommended = $parent->labels()
            ->wherePivot('mode', Folder::LABEL_MODE_RECOMMENDED)
            ->pluck('labels.id')
            ->all();

        if ($recommended !== []) {
            $folder->labels()->sync(
                collect($recommended)->mapWithKeys(fn (string $id) => [$id => ['mode' => Folder::LABEL_MODE_RECOMMENDED]])->all()
            );
        }
    }

    /**
     * The labels ENFORCED by any ANCESTOR of $folder (its strict ancestors on the materialized path,
     * NOT its own) — the enforced governance a folder INHERITS. Not materialized onto the folder:
     * folders are never label-filtered, so this is derived at read time and surfaced as locked
     * labels in the drawer. Soft-deleted labels are dropped.
     */
    public function inheritedEnforcedLabels(Folder $folder): Collection
    {
        $ancestorIds = $folder->ancestorIds();

        if ($ancestorIds === []) {
            return new Collection;
        }

        $labelIds = DB::table('folder_label')
            ->whereIn('folder_id', $ancestorIds)
            ->where('mode', Folder::LABEL_MODE_ENFORCED)
            ->distinct()
            ->pluck('label_id')
            ->all();

        if ($labelIds === []) {
            return new Collection;
        }

        return Label::query()->whereIn('id', $labelIds)->get();
    }

    public function rename(Folder $folder, string $name): Folder
    {
        $this->guardSiblingName($name, $folder->parent_id, $folder->getKey());

        $folder->update(['name' => $name]);

        return $folder;
    }

    /**
     * Metadata edits — name, description, icon and governance labels. Absent DTO fields are left
     * alone; null clears (drop a description/icon). Field changes ride the model save (the
     * changelog auto-diffs name/description/icon); label governance is synced onto the
     * `folder_label` pivot with its per-label mode, changelog-tracked as membership.
     */
    public function update(Folder $folder, UpdateFolderDTO $dto): Folder
    {
        return DB::transaction(function () use ($folder, $dto) {
            if ($dto->hasName) {
                $this->guardSiblingName($dto->name, $folder->parent_id, $folder->getKey());
                $folder->name = $dto->name;
            }

            if ($dto->hasDescription) {
                $folder->description = $dto->description;
            }

            if ($dto->hasIcon) {
                $folder->icon = $dto->icon;
            }

            $folder->save();

            if ($dto->hasLabels) {
                $this->syncLabels($folder, $dto->labels ?? []);
                // The enforced set may have changed → re-materialize onto every file in the subtree.
                app(FolderLabelEnforcer::class)->syncSubtree($folder);
            }

            $folder->load('labels');
            // Carry the inherited-enforced set too, so the edit response shows the SAME governance as
            // the show payload (own + inherited) — the drawer re-seeds from it after a save.
            $folder->setRelation('inheritedEnforcedLabels', $this->inheritedEnforcedLabels($folder));

            return $folder;
        });
    }

    /**
     * Sync the folder's governance labels to exactly $labels (`[{id, mode}]`). Labels are
     * re-queried under the workspace scope, so a foreign id can never attach; the changelog bag
     * records membership add/remove (a mode-only flip is not a membership change).
     *
     * @param  array<int, array{id: string, mode: string}>  $labels
     */
    private function syncLabels(Folder $folder, array $labels): void
    {
        // Keep only ids that resolve in scope, mapping each to its requested mode (last wins).
        $modeById = [];
        foreach ($labels as $label) {
            $modeById[$label['id']] = $label['mode'];
        }

        $scoped = Label::query()->whereIn('id', array_keys($modeById))->pluck('id')->all();
        $modeById = array_intersect_key($modeById, array_flip($scoped));

        app(ChangelogManager::class)->manual($folder, 'labels', function (BagTracker $tracker) use ($folder, $modeById) {
            $current = $folder->labels()->pluck('labels.id')->all();

            foreach (Label::query()->whereIn('id', array_diff(array_keys($modeById), $current))->get() as $added) {
                $tracker->attach($added);
            }

            foreach (Label::query()->whereIn('id', array_diff($current, array_keys($modeById)))->get() as $removed) {
                $tracker->detach($removed);
            }

            // belongsToMany sync with pivot payload: sets each row's mode and drops the rest.
            $folder->labels()->sync(array_map(fn (string $mode) => ['mode' => $mode], $modeById));
        });
    }

    /**
     * Re-parent a folder WITH ITS WHOLE SUBTREE.
     *
     * The materialized path is what makes this cheap: every descendant's path starts with the
     * moved folder's descendant prefix, so re-anchoring that prefix updates the entire subtree
     * in ONE statement — no recursion, no N+1, regardless of size.
     */
    public function move(Folder $folder, ?Folder $target): Folder
    {
        $this->guardMoveTarget($folder, $target);

        $oldPrefix = $folder->descendantPrefix();
        $newPath = Folder::buildPath($target);
        $newPrefix = $newPath . $folder->getKey() . Folder::PATH_SEPARATOR;

        $this->guardSiblingName($folder->name, $target?->getKey(), $folder->getKey());
        $this->guardDepth($this->deepestDescendantDepth($folder, $oldPrefix, $newPath));

        DB::transaction(function () use ($folder, $target, $newPath, $oldPrefix, $newPrefix) {
            // `path` is deliberately NOT fillable (no client may forge an ancestry), so it is
            // assigned directly — mass assignment would drop it silently and leave the moved
            // folder pointing at its old ancestors while its subtree moved on.
            $folder->parent_id = $target?->getKey();
            $folder->path = $newPath;
            $folder->save();

            $this->reanchorDescendants($oldPrefix, $newPrefix);

            // The subtree now sits under different ancestors: recompute enforced labels for every
            // file in it (adds the new parents' enforced labels, drops the old parents').
            app(FolderLabelEnforcer::class)->syncSubtree($folder);
        });

        return $folder->refresh();
    }

    /** Trash a folder. Refuses while it still holds anything — no surprise mass-deletes. */
    public function trash(Folder $folder): void
    {
        $hasChildren = Folder::query()->where('parent_id', $folder->getKey())->exists();
        $hasFiles = File::query()->diskNative()->where('fileable_id', $folder->getKey())->exists();

        if ($hasChildren || $hasFiles) {
            throw ValidationException::withMessages([
                'folder' => [__('disk.validation.folder_not_empty')],
            ]);
        }

        $folder->delete();
    }

    public function restore(Folder $folder): Folder
    {
        // A folder whose parent was trashed in the meantime would come back invisible; put it
        // at the root instead, which is also what P5's restore-elsewhere rule does for files.
        // (Both attributes are set directly: `path` is not fillable by design.)
        if ($folder->parent_id !== null && !Folder::query()->whereKey($folder->parent_id)->exists()) {
            $folder->parent_id = null;
            $folder->path = Folder::buildPath(null);
        }

        $folder->restore();

        return $folder;
    }

    /**
     * ONE UPDATE for the whole subtree: replace the old prefix with the new one, keeping each
     * descendant's own tail.
     *
     * The prefixes are embedded as literals because Eloquent cannot bind values inside a raw
     * update expression — so their shape is ASSERTED first (they are built from uuids we
     * generated, never from input). The query itself stays Eloquent, which is what keeps
     * WorkspaceScope, soft-deletes and the tenant connection applied.
     */
    private function reanchorDescendants(string $oldPrefix, string $newPrefix): void
    {
        $this->assertPathShape($oldPrefix);
        $this->assertPathShape($newPrefix);

        $tailFrom = strlen($oldPrefix) + 1;

        Folder::query()
            ->where('path', 'like', $oldPrefix . '%')
            ->update([
                'path' => DB::raw("concat('" . $newPrefix . "', substr(path, " . $tailFrom . '))'),
            ]);
    }

    /** A path is structurally '/', or '/{uuid}/{uuid}/…/'. Anything else must never be embedded. */
    private function assertPathShape(string $path): void
    {
        if (preg_match('#^/([0-9a-fA-F-]{36}/)*$#', $path) !== 1) {
            throw new InvalidArgumentException("Refusing to build a folder path update from an unexpected path [{$path}].");
        }
    }

    /** Depth the deepest descendant would reach after the move. */
    private function deepestDescendantDepth(Folder $folder, string $oldPrefix, string $newPath): int
    {
        $movedDepth = substr_count($newPath, Folder::PATH_SEPARATOR);

        $deepestBelow = Folder::query()
            ->where('path', 'like', $oldPrefix . '%')
            ->get(['path'])
            ->map(fn (Folder $descendant) => $descendant->depth())
            ->max();

        if ($deepestBelow === null) {
            return $movedDepth;
        }

        // How far the subtree extends below the moved folder, re-based onto its new depth.
        return $movedDepth + ($deepestBelow - $folder->depth());
    }

    private function guardMoveTarget(Folder $folder, ?Folder $target): void
    {
        if ($target === null) {
            return;
        }

        if ($folder->is($target)) {
            throw ValidationException::withMessages([
                'target_folder_id' => [__('disk.validation.folder_move_into_self')],
            ]);
        }

        // Moving a folder into its own subtree would detach that subtree from the tree.
        if ($folder->isAncestorOf($target)) {
            throw ValidationException::withMessages([
                'target_folder_id' => [__('disk.validation.folder_move_into_descendant')],
            ]);
        }
    }

    private function guardDepth(int $depth): void
    {
        if ($depth > Folder::MAX_DEPTH) {
            throw ValidationException::withMessages([
                'parent_id' => [__('disk.validation.folder_too_deep', ['max' => Folder::MAX_DEPTH])],
            ]);
        }
    }

    /**
     * Sibling names stay unique. The DB unique index covers this for nested folders, but
     * Postgres treats NULLs as distinct — so root-level collisions would slip through it.
     */
    private function guardSiblingName(string $name, ?string $parentId, ?string $ignoreId = null): void
    {
        $exists = Folder::query()
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => [__('disk.validation.folder_name_taken')],
            ]);
        }
    }
}
