<?php

namespace App\Modules\Disk\Models;

use App\Models\AbstractModel;
use App\Modules\Changelog\Interfaces\HasChangelog as InterfacesHasChangelog;
use App\Modules\Changelog\Managers\BagTracker;
use App\Modules\Changelog\Managers\FieldTracker;
use App\Modules\Changelog\Managers\ModelChangelogManager;
use App\Modules\Changelog\Traits\HasChangelog;
use App\Modules\Comments\Traits\HasComments;
use App\Modules\Disk\Enums\FileType;
use App\Modules\Labels\Models\Label;
use App\Modules\Labels\Traits\HasLabels;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class File extends AbstractModel implements InterfacesHasChangelog
{
    use HasChangelog, HasComments, HasCreator, HasFactory, HasLabels, HasUuids, SoftDeletes, TenantAware;

    protected const CREATOR_ID_COLUMN = 'uploader_id';

    protected const CREATOR_TYPE_COLUMN = 'uploader_type';

    protected $table = 'files';

    /**
     * The fileable_type marking a DISK file: its container is a Folder (the morph alias registered
     * in DiskModuleServiceProvider). `fileable_id` = the folder, or NULL for the workspace root.
     */
    public const FOLDER_TYPE = 'folder';

    protected $fillable = [
        'name',
        'description',
        'path',
        'type',
        'uploader_id',
        'mime_type',
        'size',
        'fileable_id',
        'fileable_type',
    ];

    protected $casts = [
        'size' => 'integer',
        'type' => FileType::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'disk_trashed_at' => 'datetime',
    ];

    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * ALL users' autosave DRAFTS of this file — one row per user ({@see DiskFileDraft}). Both live on
     * the same tenant connection, and DiskFileDraft is TenantAware, so the relation is workspace-
     * scoped automatically. The per-user grid flag uses {@see draft()} instead.
     */
    public function drafts(): HasMany
    {
        return $this->hasMany(DiskFileDraft::class, 'file_id');
    }

    /**
     * The CURRENT user's autosave draft of this file — at most one (`UNIQUE(file_id, user_id)`), null
     * when they have none. Scoped to the authenticated user so a list can derive a per-user `has_draft`
     * via `withExists(['draft as has_draft'])` WITHOUT threading the user id through the query builder.
     * Reads `auth()->id()` at query-build time, so it is only meaningful in an authenticated request
     * (the reaper/console use {@see DiskFileDraft} directly, never this relation).
     */
    public function draft(): HasOne
    {
        return $this->hasOne(DiskFileDraft::class, 'file_id')->where('user_id', auth()->id());
    }

    /**
     * Labels, carrying the `enforced` pivot flag (F3): true when the row was materialized onto this
     * file by an ancestor folder's governance (locked in the UI), false for a manual label. Overrides
     * {@see HasLabels::labels()} to expose the flag; the shared pivot's other consumers ignore it.
     */
    public function labels(): MorphToMany
    {
        return $this->morphToMany(Label::class, 'labelable', 'labelables', 'labelable_id', 'label_id')
            ->withTimestamps()
            ->withPivot('enforced');
    }

    /**
     * The disk folder this file sits in — the `fileable` when it is a Folder; NULL for the
     * workspace root or a resource-owned file. Kept a `belongsTo` on `fileable_id` so it eager-
     * loads (`with('folder')`); a non-folder file's fileable_id won't match any folder → null.
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'fileable_id');
    }

    /**
     * Backwards-compatible virtual attribute (the `folder_id` column is gone — placement lives in
     * `fileable`). Reads as the containing folder's id for a disk file, NULL otherwise, so the API
     * Resource and callers keep working unchanged.
     */
    public function getFolderIdAttribute(): ?string
    {
        return $this->fileable_type === self::FOLDER_TYPE ? $this->fileable_id : null;
    }

    /**
     * The canonical, ACCESS-CONTROLLED URL that serves this file's bytes — the named `disk.show`
     * route. It is the ONLY safe public reference to a file: NEVER the raw storage `path`, and gated
     * end-to-end (auth:sanctum + RequireWorkspace + a tenant-scoped {file} binding that 404s a
     * foreign/trashed id), so it is not a forever-public or unguarded link. This is the single source
     * of truth other layers reuse — {@see FileResource} exposes it as `path`, and the Workflows file
     * snapshot embeds it as `url` — so the serve URL is defined in exactly one place.
     */
    public function serveUrl(): string
    {
        return route('disk.show', ['file' => $this->id]);
    }

    /**
     * A temp upload: no container yet — `fileable_type` NULL, an uploaded-but-not-attached file.
     * A disk file (fileable_type 'folder', even at the ROOT where fileable_id is NULL) and a
     * resource file (task attachment, report) both carry a fileable_type, so neither is a temp.
     */
    public function scopeTemp(Builder $query): void
    {
        $query->whereNull('fileable_type');
    }

    /** Disk-native files: their container is a folder (root or nested). */
    public function scopeDiskNative(Builder $query): void
    {
        $query->where('fileable_type', self::FOLDER_TYPE);
    }

    /**
     * Files thrown away FROM the disk. Deliberately NOT the same as soft-deleted: detaching a
     * task attachment also soft-deletes its file (FileService::detach), and those must not
     * turn up in the disk's trash.
     */
    public function scopeDiskTrashed(Builder $query): void
    {
        $query->whereNotNull('disk_trashed_at');
    }

    /**
     * Whether this file belongs to another RESOURCE (a task attachment, a report's output) rather
     * than living on the disk itself. A disk file's fileable is a Folder — that is NOT resource
     * ownership. Those resource files are shown under read-only virtual folders, and their
     * lifecycle stays with the owning module.
     */
    public function isOwnedByResource(): bool
    {
        return $this->fileable_type !== null && $this->fileable_type !== self::FOLDER_TYPE;
    }

    public function getChangelogManager(): ModelChangelogManager
    {
        return new ModelChangelogManager($this, [
            FieldTracker::make('name')->withComparison(),
            FieldTracker::make('description')->withComparison(),
            // The containing folder — tracked on the real `fileable_id` column (a disk file's
            // fileable IS its folder). Renders the folder NAME, resolved withTrashed so audit
            // history keeps it even after the folder is deleted (mirrors Task's assignee); a
            // non-folder fileable (a resource owner) maps to null, so a bind never logs here.
            FieldTracker::make('fileable_id')->withMap(function (?string $fileableId, File $file) {
                if (!$fileableId || $file->fileable_type !== self::FOLDER_TYPE) {
                    return null;
                }

                $folder = Folder::withTrashed()->find($fileableId);

                return $folder ? ['id' => $folder->id, 'name' => $folder->name] : null;
            }),
            BagTracker::make('labels')->asClass(Label::class)->manualOnly()->withMap(function (Label $label, File $file) {
                return [
                    'id' => $label->id,
                    'name' => $label->name,
                    'color' => $label->color,
                    'icon' => $label->icon?->value,
                ];
            }),
        ]);
    }

    /**
     * The default resolver looks for Database\Factories\Modules\Disk\Models\FileFactory
     * (it derives the namespace from the model's), which does not exist — every module
     * model points at its flat Database\Factories class explicitly.
     */
    protected static function newFactory()
    {
        return \Database\Factories\FileFactory::new();
    }
}
