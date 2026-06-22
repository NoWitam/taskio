<?php

namespace App\Modules\FilterTabs\Models;

use App\Enums\IconEnum;
use App\Models\AbstractModel;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FilterTab extends AbstractModel
{
    use HasFactory, HasUuids, TenantAware;

    protected $table = 'filter_tabs';

    protected $fillable = [
        'user_id',
        'context',
        'name',
        'icon',
        'filters',
        'sort_order',
    ];

    protected $casts = [
        'filters' => 'array',
        'icon' => IconEnum::class,
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function newFactory()
    {
        return \Database\Factories\FilterTabFactory::new();
    }
}
