<?php

namespace App\Modules\Disk\Models;

use App\Models\AbstractModel;
use App\Modules\Changelog\Interfaces\HasChangelog as InterfacesHasChangelog;
use App\Modules\Changelog\Managers\BagTracker;
use App\Modules\Changelog\Managers\FieldTracker;
use App\Modules\Changelog\Managers\ModelChangelogManager;
use App\Modules\Changelog\Traits\HasChangelog;
use App\Modules\Disk\Enums\FileType;
use App\Modules\Labels\Models\Label;
use App\Modules\Labels\Traits\HasLabels;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class File extends AbstractModel implements InterfacesHasChangelog
{
    use HasChangelog, HasCreator, HasFactory, HasLabels, HasUuids, SoftDeletes, TenantAware;

    protected const CREATOR_ID_COLUMN = 'uploader_id';

    protected const CREATOR_TYPE_COLUMN = 'uploader_type';

    protected $table = 'files';

    protected $fillable = [
        'name',
        'description',
        'path',
        'type',
        'folder_id',
        'disk_placed_at',
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
        'disk_placed_at' => 'datetime',
        'disk_trashed_at' => 'datetime',
    ];

    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The disk folder this file sits in; NULL means the workspace root. */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'folder_id');
    }

    /**
     * A temp upload: not owned by any resource AND never placed on the disk. The
     * disk_placed_at guard is what keeps a file dropped at the ROOT (folder_id NULL, no owner)
     * out of the temp bucket — otherwise it would be indistinguishable from an in-flight upload
     * and could be rebound to a resource or swept.
     */
    public function scopeTemp(Builder $query): void
    {
        $query->whereNull('fileable_type')->whereNull('disk_placed_at');
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
     * Whether this file belongs to another resource (a task attachment, a report's output)
     * rather than living on the disk itself. Those are shown under read-only virtual folders,
     * and their lifecycle stays with the owning module.
     */
    public function isOwnedByResource(): bool
    {
        return $this->fileable_type !== null;
    }

    public function getChangelogManager(): ModelChangelogManager
    {
        return new ModelChangelogManager($this, [
            FieldTracker::make('name')->withComparison(),
            FieldTracker::make('description')->withComparison(),
            // Renders the folder NAME, resolved withTrashed: audit history must not lose the
            // name just because the folder was deleted afterwards (mirrors Task's assignee).
            FieldTracker::make('folder_id')->withMap(function (?string $folderId, File $file) {
                if (!$folderId) {
                    return null;
                }

                $folder = Folder::withTrashed()->find($folderId);

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
