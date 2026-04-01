<?php

namespace App\Modules\Forms\Models;

use App\Models\AbstractModel;
use App\Traits\HasCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FormSubmission extends AbstractModel
{
    use HasCreator, HasUuids;

    protected $table = 'form_submissions';

    protected $fillable = [
        'form_id',
        'submittable_type',
        'submittable_id',
        'data',
        'creator_id',
    ];

    protected $casts = [
        'data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function submittable(): MorphTo
    {
        return $this->morphTo();
    }
}
