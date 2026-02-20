<?php

namespace App\Modules\Changelog\Traits;

use App\Modules\Changelog\Enums\ChangelogEvent;
use App\Modules\Changelog\Models\Changelog;
use App\Modules\Changelog\Managers\ChangelogManager;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasChangelog
{
    protected static function bootHasChangelog(): void
    {
        static::created(function ($model) {
            app(ChangelogManager::class)->log(
                subject: $model,
                event: ChangelogEvent::CREATED,
            );
        });

        static::updating(function ($model) {
            app(ChangelogManager::class)->handleUpdating($model);
        });

        static::updated(function ($model) {
            app(ChangelogManager::class)->log(
                subject: $model,
                event: ChangelogEvent::UPDATED,
            );
        });

        static::deleted(function ($model) {
            app(ChangelogManager::class)->log(
                subject: $model,
                event: ChangelogEvent::DELETED,
            );
 
        });

        static::restored(function ($model) {
            app(ChangelogManager::class)->log(
                subject: $model,
                event: ChangelogEvent::RESTORED
            );
        });
    }

    public function changelogs(): MorphMany
    {
        return $this->morphMany(Changelog::class, 'subject')->orderBy('created_at', 'desc');
    }
}
