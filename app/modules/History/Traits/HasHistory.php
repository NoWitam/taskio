<?php

namespace App\Modules\History\Traits;

use App\Modules\History\Enums\ActivityEvent;
use App\Modules\History\Models\Activity;
use App\Modules\History\Managers\HistoryManager;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasHistory
{
    protected static function bootHasHistory(): void
    {
        static::created(function ($model) {
            app(HistoryManager::class)->log(
                subject: $model,
                event: ActivityEvent::CREATED,
            );
        });

        static::updated(function ($model) {
            app(HistoryManager::class)->log(
                subject: $model,
                event: ActivityEvent::UPDATED,
            );
        });

        static::deleted(function ($model) {
            app(HistoryManager::class)->log(
                subject: $model,
                event: ActivityEvent::DELETED,
            );
 
        });

        static::restored(function ($model) {
            app(HistoryManager::class)->log(
                subject: $model,
                event: ActivityEvent::RESTORED
            );
        });
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->orderBy('created_at', 'desc');
    }
}
