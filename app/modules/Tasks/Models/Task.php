<?php

namespace App\Modules\Tasks\Models;

use App\Models\AbstractModel;
use App\Models\User;
use App\Modules\Disk\Traits\HasFiles;
use App\Modules\Labels\Traits\HasLabels;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Traits\Archiving;
use App\Traits\HasCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends AbstractModel
{
    use HasCreator, HasUuids, SoftDeletes, HasFiles, HasLabels, Archiving;
    
    protected $table = 'tasks';

    protected $fillable = [
        'title',
        'description',
        'status',
        'priority',
        'deadline',
        'creator_id',
        'assigned_id'
    ];

    protected $casts = [
        'priority' => TaskPriority::class,
        'status' => TaskStatus::class,
        'deadline' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'archived_at' => 'datetime'
    ];

    public function assigned()
    {
        return $this->belongsTo(User::class);
    }

    public function isCompleted(): bool // TODO czy formularz jest poprawnie uzupełniony
    {
        return true;
    }

    public function isDeadlineOverdue(): bool
    {
        if(is_null($this->deadline)) {
            return false;
        }

        return $this->deadline->format('Y-m-d') < now()->format('Y-m-d');
    }

    public function isDeadlineAtRisk(): bool
    {
        if(is_null($this->deadline)) {
            return false;
        }

        if($this->isDeadlineOverdue()) {
            return false;
        }

        return $this->deadline->clone()->subDays(2)->format('Y-m-d') >= now()->format('Y-m-d');
    }
}
