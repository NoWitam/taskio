<?php

namespace App\Modules\Disk\Models;

use App\Models\AbstractModel;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
class Folder extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, SoftDeletes, TenantAware;

    /** How deep the tree may go; the root's children are depth 1. */
    public const MAX_DEPTH = 10;

    public const PATH_SEPARATOR = '/';

    protected $table = 'folders';

    protected $fillable = [
        'name',
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

    /** Files placed directly in this folder (attachments live outside the tree). */
    public function files(): HasMany
    {
        return $this->hasMany(File::class, 'folder_id');
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

    protected static function newFactory()
    {
        return \Database\Factories\FolderFactory::new();
    }
}
