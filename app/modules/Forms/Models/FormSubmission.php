<?php

namespace App\Modules\Forms\Models;

use App\Models\AbstractModel;
use App\Modules\Forms\Observers\FormSubmissionObserver;
use App\Traits\HasCreator;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(FormSubmissionObserver::class)]
class FormSubmission extends AbstractModel
{
    use HasCreator, HasUuids, HasFactory, SoftDeletes;

    protected $table = 'form_submissions';

    protected $fillable = [
        'form_id',
        'submittable_type',
        'submittable_id',
        'data',
        'form_content_version_id',
        'indexed_at',
        'approved_at',
        'creator_id',
    ];

    protected $casts = [
        'data' => 'array',
        'indexed_at' => 'datetime',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Scope to filter only approved submissions
     */
    public function scopeOnlyApproved(Builder $query): void
    {
        $query->whereNotNull('approved_at');
    }

    /**
     * Scope to filter only draft submissions
     */
    public function scopeOnlyDrafts(Builder $query): void
    {
        $query->whereNull('approved_at');
    }

    /**
     * Check if the submission is approved
     */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * Check if the submission can be edited (only drafts can be edited)
     */
    public function canBeEdited(): bool
    {
        return !$this->isApproved();
    }

    /**
     * Approve the submission
     */
    public function approve(): void
    {
        if (!$this->isApproved()) {
            $this->update(['approved_at' => now()]);
        }
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function contentVersion(): BelongsTo
    {
        return $this->belongsTo(FormContentVersion::class, 'form_content_version_id');
    }

    public function submittable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Database\Factories\FormSubmissionFactory::new();
    }
}
