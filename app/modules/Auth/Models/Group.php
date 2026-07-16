<?php

namespace App\Modules\Auth\Models;

use App\Models\AbstractModel;
use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Group extends AbstractModel
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'name',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_user');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(GroupPermission::class);
    }
}
