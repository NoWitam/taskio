<?php

namespace App\Modules\Disk\Models;

use App\Models\AbstractModel;
use App\Models\User;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's in-progress DRAFT of a single Disk file edit. The editor autosaves its state here (POST
 * /disk/{file}/draft) so a refresh/crash never loses work; the MAIN file is overwritten only on an
 * explicit Save. The heavy payload (an opaque manifest.json + image base blobs) lives on the tenant
 * Storage disk — this row indexes it and carries just enough metadata to reason about staleness.
 *
 * Tenant-scoped (WorkspaceScope + own-DB connection routing via TenantAware). UNIQUE(file_id,
 * user_id) means one draft per user per file — writes upsert. Rows are transient: the
 * `disk:reap-stale-drafts` reaper prunes drafts (row + storage dir) past the retention window.
 */
class DiskFileDraft extends AbstractModel
{
    use HasUuids, TenantAware;

    protected $table = 'disk_file_drafts';

    protected $fillable = [
        'file_id',
        'user_id',
        'kind',
        'base_version',
        'byte_size',
    ];

    protected $casts = [
        'byte_size' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Narrow to a single user's drafts — drafts are strictly per user, never shared. */
    public function scopeForUser(Builder $query, User $user): void
    {
        $query->where('user_id', $user->getKey());
    }
}
