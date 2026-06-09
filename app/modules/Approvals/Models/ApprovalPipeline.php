<?php

namespace App\Modules\Approvals\Models;

use App\Enums\IconEnum;
use App\Models\AbstractModel;
use App\Traits\HasCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalPipeline extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'approval_pipelines';

    protected $fillable = [
        'name',
        'icon',
        'description',
        'creator_id',
    ];

    protected $casts = [
        'icon' => IconEnum::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function stages(): HasMany
    {
        return $this->hasMany(ApprovalStage::class)->orderBy('order');
    }

    public function processes(): HasMany
    {
        return $this->hasMany(ApprovalProcess::class);
    }

    public function hasActiveProcesses(): bool
    {
        return $this->processes()
            ->where('status', 'pending')
            ->exists();
    }

    public function canBeEdited(): bool
    {
        return !$this->hasActiveProcesses();
    }

    public function canBeDeleted(): bool
    {
        return !$this->hasActiveProcesses();
    }

    protected static function newFactory()
    {
        return \Database\Factories\ApprovalPipelineFactory::new();
    }
}
