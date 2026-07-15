<?php

namespace App\Modules\Disk\Models;

use App\Models\AbstractModel;
use App\Modules\Disk\Enums\FileType;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class File extends AbstractModel
{
    use HasCreator, HasUuids, SoftDeletes, TenantAware;

    protected const CREATOR_ID_COLUMN = 'uploader_id';

    protected const CREATOR_TYPE_COLUMN = 'uploader_type';

    protected $table = 'files';

    protected $fillable = [
        'name',
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
    ];

    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeTemp(Builder $query): void
    {
        $query->whereNull('fileable_type');
    }
}
