<?php

namespace App\Modules\Forms\Models;

use App\Models\AbstractModel;
use App\Modules\Disk\Models\File;
use App\Modules\Forms\Jobs\CreateFormReport as CreateFormReportJob;
use App\Traits\HasCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class FormReport extends AbstractModel
{
    use HasCreator, HasUuids, HasFactory, SoftDeletes;

    protected $table = 'form_reports';

    protected $fillable = [
        'form_id',
        'name',
        'guidelines',
        'sources',
        'submissions_from',
        'submissions_to',
        'completed_at',
        'creator_id',
    ];

    protected $casts = [
        'sources' => 'array',
        'submissions_from' => 'date',
        'submissions_to' => 'date',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (FormReport $report) {
            CreateFormReportJob::dispatch($report);
        });
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function file(): MorphOne
    {
        return $this->morphOne(File::class, 'fileable');
    }

    public function isCompleted(): bool
    {
        return !is_null($this->completed_at);
    }

    public function getViewName(): string
    {
        return 'form_' . str_replace('-', '_', $this->form_id) . '_submissions';
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'completed_at' => now()
        ]);
    }
}
