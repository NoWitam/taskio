<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasCreator
{
    private const CREATOR_ID_DEFAULT_COLUMN = 'creator_id';

    public static function bootHasCreator()
    {
        static::saving(function (Model $model) {
            $column = self::getColumName();
            if(!isset($model->{$column})) {
                $model->{$column} = auth()->id();
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, self::getColumName());
    }

    private static function getColumName(): string
    {
        return defined('static::CREATOR_ID_COLUMN') ? static::CREATOR_ID_COLUMN : self::CREATOR_ID_DEFAULT_COLUMN;
    }

}
