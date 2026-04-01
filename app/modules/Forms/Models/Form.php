<?php

namespace App\Modules\Forms\Models;

use App\Enums\IconEnum;
use App\Models\AbstractModel;
use App\Models\User;
use App\Modules\Changelog\Interfaces\HasChangelog as InterfacesHasChangelog;
use App\Modules\Changelog\Managers\FieldTracker;
use App\Modules\Changelog\Managers\ModelChangelogManager;
use App\Modules\Changelog\Traits\HasChangelog;
use App\Traits\HasCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Form extends AbstractModel implements InterfacesHasChangelog
{
    use HasCreator, HasUuids, SoftDeletes, HasChangelog;

    protected $table = 'forms';

    protected $fillable = [
        'name',
        'icon',
        'description',
        'content',
        'is_anonymous',
        'creator_id',
    ];

    protected $casts = [
        'content' => 'array',
        'is_anonymous' => 'boolean',
        'icon' => IconEnum::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function getChangelogManager(): ModelChangelogManager
    {
        return new ModelChangelogManager($this, [
            FieldTracker::make('name')->withComparison(),
            FieldTracker::make('icon')->withMap(function (?IconEnum $icon, Form $form) {
                return $icon?->value;
            }),
            FieldTracker::make('description')->withComparison(),
        ]);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }
}
