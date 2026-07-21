<?php

namespace App\Modules\Disk\Models;

use App\Models\AbstractModel;
use App\Modules\Changelog\Interfaces\HasChangelog as InterfacesHasChangelog;
use App\Modules\Changelog\Managers\BagTracker;
use App\Modules\Changelog\Managers\FieldTracker;
use App\Modules\Changelog\Managers\ModelChangelogManager;
use App\Modules\Changelog\Traits\HasChangelog;
use App\Modules\Comments\Traits\HasComments;
use App\Modules\Labels\Models\Label;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A folder on a workspace's disk. Folders are PURELY LOGICAL: blobs keep their flat uuid
 * path on the filesystem, so nothing here ever touches storage.
 *
 * Besides parent_id, every folder stores a MATERIALIZED PATH of its ANCESTORS —
 * '/{root}/{child}/', slash-delimited and slash-terminated, the root's path being just '/'.
 * That single column turns the expensive tree operations into single queries: breadcrumbs ARE
 * the path (no lookup walk), and a whole subtree is one LIKE against {@see descendantPrefix()},
 * which a subtree move then re-anchors in one UPDATE.
 *
 * The path deliberately does NOT include the folder's own id: it is already known from the
 * row (and from the URL), so storing it would only add a self-reference — which would force
 * the path to be built after key generation. Ancestors-only depends solely on the parent, so
 * it can be computed before insert with no ordering games.
 *
 * The trailing separator is load-bearing: without it the prefix '/a/b' would also match
 * '/a/bc...'.
 */
class Folder extends AbstractModel implements InterfacesHasChangelog
{
    use HasChangelog, HasComments, HasCreator, HasFactory, HasUuids, SoftDeletes, TenantAware;

    /** How deep the tree may go; the root's children are depth 1. */
    public const MAX_DEPTH = 10;

    public const PATH_SEPARATOR = '/';

    /** Governance modes on the folder_label pivot. */
    public const LABEL_MODE_ENFORCED = 'enforced';

    public const LABEL_MODE_RECOMMENDED = 'recommended';

    public const LABEL_MODES = [self::LABEL_MODE_ENFORCED, self::LABEL_MODE_RECOMMENDED];

    protected $table = 'folders';

    protected $fillable = [
        'name',
        'description',
        'icon',
        'parent_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Folder $folder): void {
            $folder->path = self::buildPath($folder->resolveParent());
        });
    }

    /**
     * The ancestor path a folder under $parent must carry: the parent's own ancestors plus the
     * parent itself. A root folder has no ancestors, hence just the separator.
     */
    public static function buildPath(?Folder $parent): string
    {
        if ($parent === null) {
            return self::PATH_SEPARATOR;
        }

        return $parent->path . $parent->getKey() . self::PATH_SEPARATOR;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Files placed directly in this folder — the disk files whose `fileable` IS this folder
     * (resource attachments live outside the tree). A morphMany, so `withCount('files')` counts
     * exactly `fileable_type = 'folder' AND fileable_id = <this>`.
     */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    /**
     * The labels this folder GOVERNS, each carrying a `mode` on the pivot: 'enforced' (materialized
     * onto every file in the subtree) or 'recommended' (pre-selected on new items here). This is the
     * dedicated `folder_label` pivot — NOT the shared `labelables` a file/task uses — because a
     * folder declares labels for its contents rather than being tagged itself.
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'folder_label')
            ->withPivot('mode')
            ->withTimestamps();
    }

    /** Ancestor ids, root first — the breadcrumb order, exactly as stored. */
    public function ancestorIds(): array
    {
        return array_values(array_filter(explode(self::PATH_SEPARATOR, (string) $this->path)));
    }

    /** A root folder is depth 1; each ancestor adds one. */
    public function depth(): int
    {
        return substr_count((string) $this->path, self::PATH_SEPARATOR);
    }

    /**
     * LIKE prefix matching every DESCENDANT of this folder (not the folder itself — which is
     * what the callers actually want: cascade checks, depth checks and subtree moves all
     * handle this row separately anyway, since its own parent_id changes too).
     */
    public function descendantPrefix(): string
    {
        return $this->path . $this->getKey() . self::PATH_SEPARATOR;
    }

    /**
     * Whether $other sits anywhere beneath this folder. NOT reflexive: a move guard must also
     * reject the target being the folder itself (`$folder->is($target)`).
     */
    public function isAncestorOf(Folder $other): bool
    {
        return str_starts_with((string) $other->path, $this->descendantPrefix());
    }

    /**
     * The parent as a model, loaded through the tenant-scoped query. A parent_id that does not
     * resolve is a bug or a cross-workspace reference — fail loudly rather than silently
     * writing the folder to the root.
     */
    private function resolveParent(): ?Folder
    {
        if ($this->parent_id === null) {
            return null;
        }

        return $this->relationLoaded('parent') && $this->parent !== null
            ? $this->parent
            : self::query()->findOrFail($this->parent_id);
    }

    public function getChangelogManager(): ModelChangelogManager
    {
        return new ModelChangelogManager($this, [
            FieldTracker::make('name')->withComparison(),
            FieldTracker::make('description')->withComparison(),
            // A plain icon-name string (a FE render hint) — tracked as-is.
            FieldTracker::make('icon'),
            // Governance labels — tracked as membership (attach/detach), manual because the mode
            // pivot is synced outside a plain attribute save (see FolderService::update).
            BagTracker::make('labels')->asClass(Label::class)->manualOnly()->withMap(fn (Label $label) => [
                'id' => $label->id,
                'name' => $label->name,
                'color' => $label->color,
                'icon' => $label->icon?->value,
            ]),
        ]);
    }

    protected static function newFactory()
    {
        return \Database\Factories\FolderFactory::new();
    }
}
