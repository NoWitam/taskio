<?php

namespace App\Modules\Changelog\Models;

use App\Models\AbstractModel;
use App\Models\User;
use App\Modules\Changelog\Enums\ChangelogEvent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Changelog extends AbstractModel
{
    use HasUuids;

    protected $table = 'changelogs';

    protected $fillable = [
        'subject_type',
        'subject_id',
        'causer_id',
        'event',
        'details',
    ];

    protected $casts = [
        'event' => ChangelogEvent::class,
        'details' => 'json',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }
}
