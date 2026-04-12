<?php

namespace App\Modules\Forms\Models;

use App\Models\AbstractModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormSubmissionField extends AbstractModel
{
    use HasUuids;

    protected $table = 'form_submission_fields';

    protected $fillable = [
        'form_submission_id',
        'field_path',
        'field_key',
        'field_value',
        'field_value_numeric',
        'field_value_boolean',
        'field_value_date',
        'field_type',
    ];

    protected $casts = [
        'field_value_numeric' => 'decimal:6',
        'field_value_boolean' => 'boolean',
        'field_value_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the submission that owns this field.
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'form_submission_id');
    }
}
