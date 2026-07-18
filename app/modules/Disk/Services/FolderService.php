<?php

namespace App\Modules\Disk\Services;

use App\Modules\Disk\DTOs\FolderDTO;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
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
        return Folder::create([
            'name' => $dto->name,
            'parent_id' => $parent?->getKey(),
        ]);
    }

    public function rename(Folder $folder, string $name): Folder
    {
        $this->guardSiblingName($name, $folder->parent_id, $folder->getKey());

        $folder->update(['name' => $name]);

        return $folder;
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
        });

        return $folder->refresh();
    }

    /** Trash a folder. Refuses while it still holds anything — no surprise mass-deletes. */
    public function trash(Folder $folder): void
    {
        $hasChildren = Folder::query()->where('parent_id', $folder->getKey())->exists();
        $hasFiles = File::query()->where('folder_id', $folder->getKey())->exists();

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
