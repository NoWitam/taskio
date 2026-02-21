<?php

namespace App\Modules\History\Models;

use App\Models\AbstractModel;
use App\Models\User;
use App\Modules\History\Enums\ActivityEvent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Activity extends AbstractModel
{
    use HasUuids;

    protected $table = 'activities';

    protected $fillable = [
        'subject_type',
        'subject_id',
        'causer_id',
        'event',
        'details',
    ];

    protected $casts = [
        'event' => ActivityEvent::class,
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
