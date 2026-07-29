<?php

namespace App\Modules\Disk\Services;

use App\Modules\Changelog\Enums\ChangelogEvent;
use App\Modules\Changelog\Managers\BagTracker;
use App\Modules\Changelog\Managers\ChangelogManager;
use App\Modules\Disk\DTOs\DiskItemFilters;
use App\Modules\Disk\DTOs\UpdateFileDTO;
use App\Modules\Disk\Enums\FileType;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Modules\Labels\Models\Label;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FileService
{
    /** How long an uploaded-but-never-attached file may linger before the sweep prunes it. */
    public const TEMP_RETENTION_HOURS = 48;

    /**
     * Browse the disk. Temp uploads (never attached, never placed) are ALWAYS excluded — they
     * are an implementation detail of the two-step upload, not something a user owns.
     *
     * `trashed=1` lists the disk's trash: files thrown away FROM the disk, which is NOT the
     * same as soft-deleted (detaching a task attachment soft-deletes its file too, and those
     * must not surface here — see the disk_trashed_at column).
     */
    public function index(Request $request): CursorPaginator
    {
        $trashed = $request->boolean('trashed');

        return File::query()
            ->when($trashed, fn ($query) => $query->withTrashed()->diskTrashed())
            ->when(!$trashed, fn ($query) => $query->whereNull('disk_trashed_at'))
            // Temp uploads (fileable_type NULL — not yet a disk file or an attachment) are never
            // anyone's file yet, so they never show. A ROOT-level disk file is fileable_type
            // 'folder' with fileable_id NULL, so it is kept in without dragging temps along.
            ->whereNotNull('fileable_type')
            ->search(['name', 'description'], $request->get('search'))
            ->when(
                filled($request->get('type')),
                fn ($query) => $query->where('type', $request->get('type'))
            )
            ->when(
                filled($request->get('labels')),
                fn ($query) => $query->filterByLabels(
                    (array) $request->get('labels'),
                    $request->string('label_operator')->value() ?: 'OR'
                )
            )
            // `folder_id=` (present but empty) means the workspace ROOT (distinct from absent =
            // "any folder"). Placement is the fileable, so this narrows to disk files in it.
            ->when(
                $request->has('folder_id'),
                fn ($query) => $query->diskNative()->where('fileable_id', $request->string('folder_id')->value() ?: null)
            )
            // Where a file came from: 'disk' = a disk file (fileable is a folder), otherwise a
            // resource morph alias ('task', 'form_report', …) — the browser's origin facet.
            ->when(
                filled($request->get('source')),
                fn ($query) => $request->get('source') === 'disk'
                    ? $query->diskNative()
                    : $query->where('fileable_type', $request->get('source'))
            )
            ->when(
                filled($request->get('size_min')),
                fn ($query) => $query->where('size', '>=', (int) $request->get('size_min'))
            )
            ->when(
                filled($request->get('size_max')),
                fn ($query) => $query->where('size', '<=', (int) $request->get('size_max'))
            )
            // A `bucket` (opening a virtual resources folder) pins a PRECISE half-open range
            // and takes over date filtering — scopeFilterByDate compares date_to against the
            // START of the day, which would drop most of a bucket's final day.
            ->when(
                $this->bucketRange($request) !== null,
                fn ($query) => $query
                    ->where('created_at', '>=', $this->bucketRange($request)[0])
                    ->where('created_at', '<', $this->bucketRange($request)[1]),
                fn ($query) => $query->filterByDate('created_at', $request),
            )
            ->with(['labels', 'folder'])
            // Per-user draft flag (same as the items grid): does the CURRENT user have an in-progress
            // autosave draft of this file? ONE correlated subquery per row (no hydration, no N+1). The
            // `draft` relation self-scopes to the auth user; TenantAware also workspace-scopes it. The
            // default `draft_exists` attribute surfaces as `has_draft` in FileResource.
            ->withExists('draft')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->cursorPaginate(24);
    }

    /**
     * The disk-native files shown for the unified items endpoint's "files" stage, filtered/sorted
     * per $filters. `where` decides the reach — this folder, its subtree, or the whole disk. Resource
     * files (Zasoby) and temps are excluded by `diskNative()`. Order ENDS in the primary key so a
     * cursor seek is a total order.
     */
    public function diskFilesQuery(?Folder $folder, DiskItemFilters $filters): \Illuminate\Database\Eloquent\Builder
    {
        $query = File::query()
            ->whereNull('disk_trashed_at')
            // Per-user draft flag for the grid: does the CURRENT user have an in-progress autosave
            // draft of this file? ONE correlated subquery per row (no hydration, no N+1). The `draft`
            // relation self-scopes to the auth user; DiskFileDraft is TenantAware so it is also
            // workspace-scoped. Surfaces as `has_draft` in FileResource (from `draft_exists`) — the
            // frontend uses it to fetch a file's draft ONLY when one exists.
            ->withExists('draft')
            ->diskNative()
            // Cross-folder results (subtree / everywhere) show which folder each file lives in, so
            // eager-load it there; a plain folder browse already knows the folder.
            ->with($filters->where === DiskItemFilters::WHERE_FOLDER ? ['labels'] : ['labels', 'folder']);

        if ($filters->where === DiskItemFilters::WHERE_EVERYWHERE || ($filters->where === DiskItemFilters::WHERE_SUBTREE && $folder === null)) {
            // every disk file — no folder scoping (subtree at the root = the whole disk)
        } elseif ($filters->where === DiskItemFilters::WHERE_SUBTREE) {
            // Files whose container is $folder OR one of its descendants. The id/path condition is
            // GROUPED so the tenant global scope's `workspace_id = ?` still wraps the whole OR.
            $query->whereIn('fileable_id', Folder::query()
                ->where(fn ($q) => $q->whereKey($folder->getKey())->orWhere('path', 'like', $folder->descendantPrefix() . '%'))
                ->select('id'));
        } else {
            $query->where('fileable_id', $folder?->getKey());
        }

        if ($filters->fileTypes !== []) {
            $query->whereIn('type', $filters->fileTypes);
        }

        $query->search($filters->searchDescription ? ['name', 'description'] : ['name'], $filters->search);

        $column = $filters->sort === 'created_at' ? 'created_at' : 'name';

        return $query->orderBy($column, $filters->dir)->orderBy('id', $filters->dir);
    }

    /**
     * The read-only "Zasoby" tree: every registered resource type that actually has files,
     * with its files grouped into created_at buckets. Synthetic nodes carry `sys:` ids that no
     * folder query can ever resolve, so they can never be mutated as folders.
     *
     * Buckets are grouped in PHP (see {@see ResourceFolderRegistry}) rather than with a
     * driver-specific GROUP BY. At R1 volumes a workspace's attachment set is small; when it
     * is not, this is where a per-bucket count query would move.
     *
     * @return array<int, array{id: string, type: string, label_key: string, icon: string, files_count: int, buckets: array<int, array{id: string, key: string, granularity: string, files_count: int}>}>
     */
    public function resourceTree(): array
    {
        $tree = [];

        foreach (ResourceFolderRegistry::TYPES as $alias => $meta) {
            // Live, non-disk-trashed files of this resource type (attachments are never
            // disk-trashed, but the guard keeps the tree honest regardless).
            $dates = File::query()
                ->where('fileable_type', $alias)
                ->whereNull('disk_trashed_at')
                ->pluck('created_at');

            if ($dates->isEmpty()) {
                continue; // a registered type with no files does not appear
            }

            $buckets = $dates
                ->groupBy(fn ($createdAt) => ResourceFolderRegistry::bucketKey($alias, $createdAt))
                ->map->count()
                ->sortKeysDesc()
                ->map(fn (int $count, string $key) => [
                    'id' => 'sys:res:' . $alias . ':' . $key,
                    'key' => $key,
                    'granularity' => ResourceFolderRegistry::granularityOf($alias),
                    'files_count' => $count,
                ])
                ->values()
                ->all();

            $tree[] = [
                'id' => 'sys:res:' . $alias,
                'type' => $alias,
                'label_key' => $meta['label_key'],
                'icon' => $meta['icon'],
                'files_count' => $dates->count(),
                'buckets' => $buckets,
            ];
        }

        return $tree;
    }

    /**
     * The [start, end) range for the request's bucket, or null when there is none / it does not
     * parse for the source type. Requires a registered `source`, since a bucket only means
     * something within a resource type.
     *
     * @return array{0: \Carbon\CarbonImmutable, 1: \Carbon\CarbonImmutable}|null
     */
    private function bucketRange(Request $request): ?array
    {
        $bucket = $request->string('bucket')->value();
        $source = $request->string('source')->value();

        if ($bucket === '' || !ResourceFolderRegistry::isRegistered($source)) {
            return null;
        }

        return ResourceFolderRegistry::bucketRange($source, $bucket);
    }

    /**
     * Upload straight onto the disk (optionally into a folder). Unlike {@see upload()} this
     * produces a file that BELONGS to the disk: its container is the folder via `fileable`
     * (fileable_type 'folder'), or the workspace root when $folder is null (fileable_id NULL) —
     * which is what tells it apart from an in-flight temp (fileable_type NULL). See File::scopeTemp.
     */
    public function store(UploadedFile $upload, ?Folder $folder): File
    {
        $file = File::create($this->attributesFor($upload) + $this->folderPlacement($folder));

        // A new disk file inherits the enforced labels of its folder and every ancestor, and starts
        // with the folder's recommended labels (removable) — the default-on-but-optional half.
        $enforcer = app(FolderLabelEnforcer::class);
        $enforcer->syncFile($file);
        $enforcer->seedRecommended($file, $folder);

        return $file;
    }

    /**
     * Store raw BYTES as a DISK-native file (the Generator "Zapisz na Dysk" path — a produced image the
     * caller has in memory, not an UploadedFile). Mirrors {@see store()} exactly — the workspace blob prefix,
     * the folder placement via `fileable` (fileable_type 'folder', or root when null), and the folder label
     * enforcement — so a saved generation is indistinguishable from an uploaded disk file. The uploader is
     * stamped by HasCreator (the request's user), so no explicit uploader is needed. Reused instead of
     * hand-rolling storage in the Generator module.
     *
     * The target folder is passed as an OPTIONAL id and resolved HERE through the tenant-scoped model
     * ({@see resolveFolder}): a foreign/unknown id 404s at `findOrFail` (never a cross-tenant write), a null
     * lands the file at the workspace root — so the Disk module owns folder resolution, keeping the caller
     * (a thin controller) out of the persistence layer.
     */
    public function storeDiskContent(string $content, string $name, string $mimeType, ?string $folderId = null): File
    {
        $folder = $this->resolveFolder($folderId);

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $storedName = Str::uuid() . ($extension !== '' ? '.' . $extension : '');
        $path = $this->uploadDirectory() . '/' . $storedName;

        Storage::put($path, $content);

        $file = File::create([
            'name' => $name,
            'path' => $path,
            'type' => FileType::fromMimeType($mimeType),
            'mime_type' => $mimeType,
            'size' => strlen($content),
        ] + $this->folderPlacement($folder));

        // A new disk file inherits the enforced labels of its folder and every ancestor, and starts with the
        // folder's recommended labels — identical to store().
        $enforcer = app(FolderLabelEnforcer::class);
        $enforcer->syncFile($file);
        $enforcer->seedRecommended($file, $folder);

        return $file->load(['labels', 'folder']);
    }

    /** The fileable attributes that place a file in $folder (null = the workspace root). */
    private function folderPlacement(?Folder $folder): array
    {
        return [
            'fileable_type' => File::FOLDER_TYPE,
            'fileable_id' => $folder?->getKey(),
        ];
    }

    /** Metadata edits. Absent fields are left alone; null clears (root folder, no description). */
    public function update(File $file, UpdateFileDTO $dto): File
    {
        return DB::transaction(function () use ($file, $dto) {
            if ($dto->hasName) {
                $file->name = $dto->name;
            }

            if ($dto->hasDescription) {
                $file->description = $dto->description;
            }

            if ($dto->hasFolderId) {
                $this->guardMovable($file);
                // Re-place the disk file in the target folder (null = root) via its fileable.
                $file->fileable_type = File::FOLDER_TYPE;
                $file->fileable_id = $this->resolveFolder($dto->folderId)?->getKey();
            }

            $file->save();

            // A move changes the file's ancestor folders, so its enforced set is recomputed FIRST
            // (adds the new location's enforced labels, drops the old one's); the manual sync then
            // runs against the corrected enforced rows.
            if ($dto->hasFolderId) {
                app(FolderLabelEnforcer::class)->syncFile($file);
            }

            if ($dto->hasLabels) {
                $this->syncLabels($file, $dto->labelIds ?? []);
            }

            return $file->load(['labels', 'folder']);
        });
    }

    /**
     * Replace a file's CONTENT in place — the preview editor's "Zapisz" (overwrite). The row keeps
     * its identity (name, description, placement, labels, uploader, created_at); only the blob and
     * the content-derived columns (path/mime_type/size/type) change, and the swap is audited as a
     * CONTENT_REPLACED changelog entry.
     *
     * Blob lifecycle is write-new → swap-row → delete-old: the new bytes land under a fresh uuid in
     * the workspace prefix BEFORE the row moves, and the old blob is deleted only AFTER the commit —
     * a crash can leak an orphan blob (the same tolerance forceDelete documents) but never lose
     * content. Mime/type are recomputed from the ACTUAL bytes, never trusted from the client;
     * inline-serving safety stays enforced at read time (B0's allowlist + nosniff).
     */
    public function replaceContent(File $file, UploadedFile $upload): File
    {
        $this->guardContentReplaceable($file);

        $attrs = $this->attributesFor($upload);
        $oldPath = $file->path;
        $oldSize = $file->size;

        DB::transaction(function () use ($file, $attrs, $oldSize) {
            $file->path = $attrs['path'];
            $file->mime_type = $attrs['mime_type'];
            $file->size = $attrs['size'];
            $file->type = $attrs['type'];
            $file->save();

            app(ChangelogManager::class)->handleCustomEvent($file, ChangelogEvent::CONTENT_REPLACED, [
                'content' => [
                    'size' => $file->size,
                    'previous_size' => $oldSize,
                    'mime_type' => $file->mime_type,
                ],
            ]);
        });

        Storage::delete($oldPath);

        // The bytes changed, so any cached first-page thumbnail is now stale — drop it so the next
        // request re-renders from the new content.
        app(ThumbnailService::class)->forget($file);

        return $file->load(['labels', 'folder']);
    }

    /**
     * Only a DISK-NATIVE file may be overwritten. A resource-owned file (a task attachment, a
     * report output) is the owning module's record — replacing its bytes from the disk would
     * silently change what that module shows; the preview offers "Zapisz jako" (a new disk file)
     * instead.
     */
    private function guardContentReplaceable(File $file): void
    {
        if ($file->isOwnedByResource()) {
            throw ValidationException::withMessages([
                'file' => [__('disk.validation.file_owned_by_resource')],
            ]);
        }
    }

    /**
     * Throw a file into the DISK's trash. Deliberately a marker of its own rather than a plain
     * soft-delete: the disk trash must show what was deleted FROM the disk, never every
     * attachment somebody detached from a task.
     */
    public function trash(File $file): void
    {
        $this->guardTrashable($file);

        $file->disk_trashed_at = now();
        $file->save();
        $file->delete();
    }

    /**
     * Everything a restore dialog needs to tell the user where the file would land — and
     * whether it may land there at all.
     *
     * @return array{original_folder: ?Folder, breadcrumbs: \Illuminate\Support\Collection, can_restore_in_place: bool, reason: ?string}
     */
    public function restorePreview(File $file, FolderService $folders): array
    {
        $folder = $file->folder_id !== null
            ? Folder::withTrashed()->find($file->folder_id)
            : null;

        $reason = match (true) {
            $file->isOwnedByResource() => 'detached',
            $file->folder_id !== null && $folder === null => 'folder_missing',
            $folder?->trashed() => 'folder_trashed',
            default => null,
        };

        return [
            'original_folder' => $folder,
            'breadcrumbs' => $folder !== null ? $folders->breadcrumbs($folder) : collect(),
            'can_restore_in_place' => $reason === null,
            'reason' => $reason,
        ];
    }

    /**
     * Bring a file back from the disk trash, optionally somewhere else.
     *
     * A restore ALWAYS brings the file back as a DISK file: its container becomes a folder
     * (fileable_type 'folder'). A file detached from a task must never reappear inside a task
     * that believes it removed it — so its fileable is re-pointed at a folder, which is also why
     * a detached file MUST be given a target (it has no folder of its own to return to).
     */
    public function restore(File $file, ?Folder $target, bool $targetProvided, FolderService $folders): File
    {
        $preview = $this->restorePreview($file, $folders);

        if (!$preview['can_restore_in_place'] && !$targetProvided) {
            throw ValidationException::withMessages([
                'target_folder_id' => [__('disk.validation.restore_target_required.' . $preview['reason'])],
            ]);
        }

        return DB::transaction(function () use ($file, $target, $targetProvided, $preview) {
            $file->restore();

            $file->disk_trashed_at = null;

            // Come back as a disk-native file. A chosen/required target sets the folder (null =
            // root); an in-place restore keeps the original folder — which for a disk file is
            // already its fileable_id, so only the type is (re)affirmed.
            $file->fileable_type = File::FOLDER_TYPE;
            if ($targetProvided || !$preview['can_restore_in_place']) {
                $file->fileable_id = $target?->getKey();
            }

            $file->save();

            // Restored into a folder → re-materialize whatever that folder's tree enforces.
            app(FolderLabelEnforcer::class)->syncFile($file);

            return $file->load(['labels', 'folder']);
        });
    }

    /** Permanently delete a file AND its bytes — the only path that ever removes a blob. */
    public function forceDelete(File $file): void
    {
        DB::transaction(function () use ($file) {
            // 'throw' => false on the disk means a wrong path deletes NOTHING and still
            // reports success, so the row is only dropped after the blob call returns.
            Storage::delete($file->path);

            $file->forceDelete();
        });

        // The bytes are gone; drop any cached thumbnail derived from them.
        app(ThumbnailService::class)->forget($file);
    }

    /**
     * Prune uploads that were never attached or placed. Without this every abandoned upload
     * (a dropzone the user closed) keeps its row and its bytes forever.
     *
     * @return int the number of files pruned
     */
    public function pruneTempFiles(?Carbon $before = null): int
    {
        $before ??= now()->subHours(self::TEMP_RETENTION_HOURS);

        $stale = File::query()
            ->temp() // fileable_type NULL — never became a disk file or an attachment
            ->where('created_at', '<', $before)
            ->get();

        foreach ($stale as $file) {
            Storage::delete($file->path);
            app(ThumbnailService::class)->forget($file);
            $file->forceDelete();
        }

        return $stale->count();
    }

    // ---- Existing attachment contract (unchanged) ---------------------------------

    public function upload(UploadedFile $file, ?Model $parent): File
    {
        return File::create($this->attributesFor($file) + [
            'fileable_type' => $parent?->getMorphClass(),
            'fileable_id' => $parent?->getKey(),
        ]);
    }

    /**
     * Store generated CONTENT as a file (bot tool output, an AI report). The producers here
     * have bytes in memory rather than an UploadedFile, and used to hand-roll their own
     * File::create + flat path — so folder/tag/history invariants and the per-workspace blob
     * prefix depended on each writer remembering them.
     *
     * $attributes lets a caller pass through what only it knows (e.g. an explicit uploader for
     * a bot, which has no authenticated user).
     */
    public function storeContent(
        string $content,
        string $name,
        string $mimeType,
        ?Model $parent = null,
        array $attributes = [],
    ): File {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $storedName = Str::uuid() . ($extension !== '' ? '.' . $extension : '');
        $path = $this->uploadDirectory() . '/' . $storedName;

        Storage::put($path, $content);

        return File::create([
            'name' => $name,
            'path' => $path,
            'type' => FileType::fromMimeType($mimeType),
            'mime_type' => $mimeType,
            'size' => strlen($content),
            'fileable_type' => $parent?->getMorphClass(),
            'fileable_id' => $parent?->getKey(),
        ] + $attributes);
    }

    /**
     * Copy a file's bytes into a NEW file owned by $target — the "copy-on-attach" path. Used
     * when a producer references a file it does NOT own (a workflow attaching a submission's
     * file, or a Disk pick, to a task): rebinding via {@see attachToModel()} would STEAL the
     * original from its owner, so we duplicate instead. The copy gets its own blob under the
     * workspace prefix; the source is left untouched. The uploader is stamped by HasCreator
     * (the active workflow run in a run context), so no explicit uploader is needed.
     */
    public function copyToModel(File $source, Model $target): File
    {
        $extension = pathinfo((string) $source->path, PATHINFO_EXTENSION);
        $storedName = Str::uuid() . ($extension !== '' ? '.' . $extension : '');
        $path = $this->uploadDirectory() . '/' . $storedName;

        if (!Storage::copy($source->path, $path)) {
            throw new \RuntimeException("Failed to copy blob for file [{$source->getKey()}].");
        }

        return File::create([
            'name' => $source->name,
            'description' => $source->description,
            'path' => $path,
            'type' => $source->type,
            'mime_type' => $source->mime_type,
            'size' => $source->size,
            'fileable_type' => $target->getMorphClass(),
            'fileable_id' => $target->getKey(),
        ]);
    }

    /**
     * Copy an existing disk file into a NEW UNOWNED temp owned by the actor — the "pick from
     * Disk" path for a form/task file field.
     *
     * A file field expects a TEMP the submitter uploaded: it is bound to the record on submit,
     * and only unowned temps are ever rebound ({@see bindTempTo()}/{@see attachToModel()}).
     * Referencing an existing disk file by id instead would either be refused by those claim
     * rules (it lives in a folder / was uploaded by someone else) or, worse, make ONE blob
     * shared between the disk and the submission — trashing it from the disk would then break
     * the submission. So a pick DUPLICATES: the copy is a fresh temp (no owner, no folder, never
     * placed → {@see File::scopeTemp}), stamped to the current user by HasCreator, and is
     * indistinguishable from a /disk/temp upload from here on. The source is left untouched.
     */
    public function copyToTemp(File $source): File
    {
        $extension = pathinfo((string) $source->path, PATHINFO_EXTENSION);
        $storedName = Str::uuid() . ($extension !== '' ? '.' . $extension : '');
        $path = $this->uploadDirectory() . '/' . $storedName;

        if (!Storage::copy($source->path, $path)) {
            throw new \RuntimeException("Failed to copy blob for file [{$source->getKey()}].");
        }

        return File::create([
            'name' => $source->name,
            'description' => $source->description,
            'path' => $path,
            'type' => $source->type,
            'mime_type' => $source->mime_type,
            'size' => $source->size,
            // No fileable_type: this IS a temp (bound to a record on submit / attach).
        ]);
    }

    /**
     * Duplicate a file INTO the disk under a chosen name and folder — the "Copy" action. Unlike
     * {@see copyToTemp()} (which produces an unowned temp) this yields a real DISK file: its
     * container is the target folder via `fileable` (fileable_type 'folder', or root when null),
     * so it shows in the browser immediately. Any file the caller can read may be copied, including
     * a resource-owned one — the copy is always a standalone disk file, owned by the actor.
     */
    public function copyToDisk(File $source, ?Folder $folder, string $name): File
    {
        $extension = pathinfo((string) $source->path, PATHINFO_EXTENSION);
        $storedName = Str::uuid() . ($extension !== '' ? '.' . $extension : '');
        $path = $this->uploadDirectory() . '/' . $storedName;

        if (!Storage::copy($source->path, $path)) {
            throw new \RuntimeException("Failed to copy blob for file [{$source->getKey()}].");
        }

        $copy = File::create([
            'name' => $name,
            'description' => $source->description,
            'path' => $path,
            'type' => $source->type,
            'mime_type' => $source->mime_type,
            'size' => $source->size,
        ] + $this->folderPlacement($folder));

        // The copy is a fresh disk file in $folder — materialize that tree's enforced labels.
        app(FolderLabelEnforcer::class)->syncFile($copy);

        return $copy->load(['labels', 'folder']);
    }

    public function attachToModel(Model $model, array $file_ids, bool $deleteAnother = false): void
    {
        $files = File::temp()
            ->whereIn('id', $file_ids)
            ->get();

        File::whereIn('id', $files->pluck('id'))
            ->update([
                'fileable_id' => $model->id,
                'fileable_type' => $model->getMorphClass(),
            ]);

        // Zbierz zarówno dodane jak i usunięte pliki w jednym trackerze
        app(ChangelogManager::class)->manual($model, 'files', function (BagTracker $tracker) use ($files, $model, $file_ids, $deleteAnother) {
            // Dodaj nowe pliki
            foreach ($files as $file) {
                $tracker->attach($file);
            }

            // Usuń stare pliki jeśli deleteAnother
            if ($deleteAnother) {
                $toDelete = $model->files()->whereNotIn('id', $file_ids)->get();

                foreach ($toDelete as $file) {
                    $tracker->detach($file);
                    $file->delete();
                }
            }
        });
    }

    /**
     * Bind already-uploaded temp files to an owning record WITHOUT writing a changelog entry.
     * Task attachments use {@see attachToModel()}, which records a changelog bag on a
     * HasChangelog model; a form submission is an immutable record with no changelog of its
     * own, so its files just need to be promoted from temp to owned.
     *
     * Only unowned temps are ever rebound (the ->temp() scope), so a foreign or already-placed
     * id in the list is silently ignored rather than stolen.
     *
     * @param  array<int, string>  $fileIds
     */
    public function bindTempTo(Model $model, array $fileIds): void
    {
        if ($fileIds === []) {
            return;
        }

        File::temp()
            ->whereIn('id', $fileIds)
            ->update([
                'fileable_id' => $model->getKey(),
                'fileable_type' => $model->getMorphClass(),
            ]);
    }

    public function detach(Model $model, File $file): void
    {
        app(ChangelogManager::class)->manual($model, 'files', function (BagTracker $tracker) use ($file) {
            $tracker->detach($file);
        });

        $file->delete();
    }

    // ---- Internals ------------------------------------------------------------------

    /**
     * Shape a stored upload into File attributes. Blobs are namespaced per workspace — a flat
     * bucket makes quotas, per-tenant GC and any future move to object storage impossible.
     * Legacy blobs stay where they are: File.path is authoritative, so nothing has to move.
     */
    private function attributesFor(UploadedFile $file): array
    {
        $extension = $file->getClientOriginalExtension();
        $name = Str::uuid() . ($extension !== '' ? '.' . $extension : '');
        $path = $file->storeAs($this->uploadDirectory(), $name);

        return [
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'type' => FileType::fromMimeType($file->getMimeType()),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ];
    }

    private function uploadDirectory(): string
    {
        $workspaceId = app(TenantContext::class)->id();

        return $workspaceId !== null ? 'uploads/' . $workspaceId : 'uploads';
    }

    private function resolveFolder(?string $folderId): ?Folder
    {
        return $folderId !== null ? Folder::query()->findOrFail($folderId) : null;
    }

    /**
     * Sync the user's MANUAL labels, leaving the folder-ENFORCED rows (F3) untouched — a metadata
     * edit can neither strip an enforced label nor add/remove one. Labels are re-queried under the
     * workspace scope, so a foreign id can never attach; the changelog records only the manual diff
     * (enforced churn is folder governance, audited on the folder, not the file).
     */
    private function syncLabels(File $file, array $labelIds): void
    {
        $scoped = Label::query()->whereIn('id', $labelIds)->pluck('id')->all();

        // Enforced rows are managed by the enforcer only — keep them exactly as they are, and drop
        // them from the user's intent so the manual diff below is honest.
        $enforced = $file->labels()->wherePivot('enforced', true)->pluck('labels.id')->all();
        $manualTarget = array_values(array_diff($scoped, $enforced));

        app(ChangelogManager::class)->manual($file, 'labels', function (BagTracker $tracker) use ($file, $enforced, $manualTarget) {
            $manualCurrent = $file->labels()->wherePivot('enforced', false)->pluck('labels.id')->all();

            foreach (Label::query()->whereIn('id', array_diff($manualTarget, $manualCurrent))->get() as $added) {
                $tracker->attach($added);
            }

            foreach (Label::query()->whereIn('id', array_diff($manualCurrent, $manualTarget))->get() as $removed) {
                $tracker->detach($removed);
            }

            // Rebuild the full pivot set: enforced rows preserved (enforced=true), manual rows set
            // to the user's target (enforced=false). sync() drops only manual rows the user removed.
            $syncSet = [];
            foreach ($enforced as $id) {
                $syncSet[$id] = ['enforced' => true];
            }
            foreach ($manualTarget as $id) {
                $syncSet[$id] = ['enforced' => false];
            }

            $file->labels()->sync($syncSet);
        });
    }

    /** A file owned by another resource lives under a virtual folder; it cannot be moved. */
    private function guardMovable(File $file): void
    {
        if ($file->isOwnedByResource()) {
            throw ValidationException::withMessages([
                'folder_id' => [__('disk.validation.file_owned_by_resource')],
            ]);
        }
    }

    /** Its lifecycle belongs to the owning module — detach it there instead. */
    private function guardTrashable(File $file): void
    {
        if ($file->isOwnedByResource()) {
            throw ValidationException::withMessages([
                'file' => [__('disk.validation.file_owned_by_resource')],
            ]);
        }
    }
}
