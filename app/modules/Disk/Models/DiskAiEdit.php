<?php

namespace App\Modules\Disk\Models;

use App\Models\AbstractModel;
use App\Modules\Disk\Enums\DiskAiEditStatus;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Status record for ONE async Disk AI image edit (F2-2). The preview dispatches an edit
 * (POST /disk/ai/image → a queued row + {@see \App\Modules\Disk\Jobs\EditDiskImageJob}), a
 * worker fills result_image, and the preview polls (GET /disk/ai/image/{id}) until done/failed.
 *
 * Tenant-scoped: WorkspaceScope + route-model binding mean a poll can only ever resolve the
 * ACTIVE workspace's own edits (a foreign id 404s at bind). Rows are transient — the
 * `disk:reap-stale-ai-edits` reaper fails stuck ones and prunes terminal ones, so the multi-MB
 * base64 result never lingers.
 */
class DiskAiEdit extends AbstractModel
{
    use HasUuids, TenantAware;

    protected $table = 'disk_ai_edits';

    protected $fillable = [
        'status',
        'prompt',
        'has_mask',
        'error',
        'result_image',
        'input_image_path',
        'input_mask_path',
    ];

    protected $casts = [
        'status' => DiskAiEditStatus::class,
        'has_mask' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
