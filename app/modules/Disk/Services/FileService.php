<?php

namespace App\Modules\Disk\Services;

use App\Modules\Changelog\Managers\BagTracker;
use App\Modules\Changelog\Managers\ChangelogManager;
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
            // A temp upload has no parent, no folder AND was never placed on the disk: it is
            // nobody's file yet. disk_placed_at is what lets a ROOT-level disk file (no parent,
            // no folder) still show here without dragging in-flight temps along. (An explicit
            // OR rather than coalesce(): the columns are varchar/uuid/timestamp, which Postgres
            // refuses to reconcile.)
            ->where(fn ($query) => $query->whereNotNull('fileable_type')->orWhereNotNull('folder_id')->orWhereNotNull('disk_placed_at'))
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
            // `folder_id=` (present but empty) means the workspace ROOT, which is a different
            // question from "any folder" (absent) — hence has() rather than filled().
            ->when(
                $request->has('folder_id'),
                fn ($query) => $query->where('folder_id', $request->string('folder_id')->value() ?: null)
            )
            // Where a file came from: 'disk' = uploaded here, otherwise a morph alias
            // ('task', 'form_report', …) — the browser's origin facet.
            ->when(
                filled($request->get('source')),
                fn ($query) => $request->get('source') === 'disk'
                    ? $query->whereNull('fileable_type')
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
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->cursorPaginate(24);
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
     * produces a file that BELONGS to the disk rather than a temp waiting to be attached.
     * disk_placed_at is stamped so a ROOT-level file (folder_id NULL) is still distinguishable
     * from an in-flight temp (see File::scopeTemp).
     */
    public function store(UploadedFile $upload, ?Folder $folder): File
    {
        return File::create($this->attributesFor($upload) + [
            'folder_id' => $folder?->getKey(),
            'disk_placed_at' => now(),
        ]);
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
                $file->folder_id = $this->resolveFolder($dto->folderId)?->getKey();
            }

            $file->save();

            if ($dto->hasLabels) {
                $this->syncLabels($file, $dto->labelIds ?? []);
            }

            return $file->load(['labels', 'folder']);
        });
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
     * A restore ALWAYS severs fileable_*: a file that was detached from a task must never
     * reappear inside a task that believes it removed it — it comes back as a standalone disk
     * file. That is also why a detached file MUST be given a target: it has no folder of its
     * own to return to.
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

            if ($targetProvided || !$preview['can_restore_in_place']) {
                $file->folder_id = $target?->getKey();
            }

            // Severing the parent is what makes a restore safe by construction.
            $file->fileable_id = null;
            $file->fileable_type = null;

            // It is now a disk-native file — mark it placed so it shows even at the root
            // (folder_id NULL) rather than being mistaken for a temp.
            $file->disk_placed_at = now();

            $file->save();

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
            ->temp()
            ->whereNull('folder_id')
            ->where('created_at', '<', $before)
            ->get();

        foreach ($stale as $file) {
            Storage::delete($file->path);
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
            // No fileable, no folder, no disk_placed_at: this IS a temp.
        ]);
    }

    /**
     * Duplicate a file INTO the disk under a chosen name and folder — the "Copy" action. Unlike
     * {@see copyToTemp()} (which produces an unowned temp) this yields a real DISK file
     * (disk_placed_at stamped), so it shows in the browser immediately and lives on its own.
     * Any file the caller can read may be copied, including a resource-owned one — the copy is
     * always a standalone disk file (no fileable), owned by the actor via HasCreator.
     */
    public function copyToDisk(File $source, ?Folder $folder, string $name): File
    {
        $extension = pathinfo((string) $source->path, PATHINFO_EXTENSION);
        $storedName = Str::uuid() . ($extension !== '' ? '.' . $extension : '');
        $path = $this->uploadDirectory() . '/' . $storedName;

        if (!Storage::copy($source->path, $path)) {
            throw new \RuntimeException("Failed to copy blob for file [{$source->getKey()}].");
        }

        return File::create([
            'name' => $name,
            'description' => $source->description,
            'path' => $path,
            'type' => $source->type,
            'mime_type' => $source->mime_type,
            'size' => $source->size,
            'folder_id' => $folder?->getKey(),
            'disk_placed_at' => now(),
        ])->load(['labels', 'folder']);
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

    /** Labels are re-queried under the workspace scope, so a foreign id can never attach. */
    private function syncLabels(File $file, array $labelIds): void
    {
        $scoped = Label::query()->whereIn('id', $labelIds)->pluck('id')->all();

        app(ChangelogManager::class)->manual($file, 'labels', function (BagTracker $tracker) use ($file, $scoped) {
            $current = $file->labels()->pluck('labels.id')->all();

            foreach (Label::query()->whereIn('id', array_diff($scoped, $current))->get() as $added) {
                $tracker->attach($added);
            }

            foreach (Label::query()->whereIn('id', array_diff($current, $scoped))->get() as $removed) {
                $tracker->detach($removed);
            }

            $file->labels()->sync($scoped);
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
