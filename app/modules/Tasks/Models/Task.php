<?php

namespace App\Modules\Tasks\Models;

use App\Models\AbstractModel;
use App\Models\User;
use App\Modules\Comments\Traits\HasComments;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Traits\HasFiles;
use App\Modules\History\Interfaces\HasHistory as InterfacesHasHistory;
use App\Modules\History\Managers\BagLog;
use App\Modules\History\Managers\FieldLog;
use App\Modules\History\Traits\HasHistory;
use App\Modules\Labels\Traits\HasLabels;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Traits\Archiving;
use App\Traits\HasCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends AbstractModel implements InterfacesHasHistory
{
    use HasCreator, HasUuids, SoftDeletes, HasFiles, HasLabels, HasComments, HasHistory, Archiving;
    
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

    public static function getHistoryOptions(): array
    {
        return [
            FieldLog::make('title')->withComparision(),
            FieldLog::make('description')->withComparision(),
            FieldLog::make('status')->asComponent('badge')->withMap(function (TaskStatus $status, Task $task) {
                return [
                    'label' => $status->label(),
                    'tone' => $status->tone(),
                    'icon' => $status->icon(),
                    'dot' => true
                ];
            }),
            BagLog::make('files')->asClass(File::class)->withMap(function (File $file, Task $task) {
                return [
                    'name' => $file->name,
                    'type' => $file->type,
                    'size' => $file->size
                ];
            })
        ];
    }

    public function assigned()
    {
        return $this->belongsTo(User::class, 'assigned_id');
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
