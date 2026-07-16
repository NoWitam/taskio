<?php

namespace App\Modules\Auth\Models;

use App\Models\AbstractModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupPermission extends AbstractModel
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'group_permission';

    protected $fillable = [
        'group_id',
        'permission',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
