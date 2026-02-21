<?php

namespace App\Modules\Labels\Models;

use App\Enums\IconEnum;
use App\Models\AbstractModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Label extends AbstractModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'labels';

    protected $fillable = [
        'name',
        'color',
        'description',
        'icon'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'icon' => IconEnum::class
    ];
}
