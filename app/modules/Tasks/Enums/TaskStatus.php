<?php

namespace App\Modules\Tasks\Enums;

use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;

enum TaskStatus: string
{
    CASE TO_DO = 'to_do';
    case IN_PROGRESS = 'in_progress';
    case IN_TEST = 'in_test';
    case DONE = 'done';

    case ARCHVIE = 'archive';
    case TRASH = 'trash';

    public function resolveSelectQuery(Builder $query): void
    {
        match($this)
        {
            self::ARCHVIE => $query->onlyArchived(),
            self::TRASH => $query->onlyTrashed(),
            default => $query->where('status', $this)
        };
    }

    public function canSetOn(Task $task): bool // TODO
    {
        if($task->deleted_at != null) {
            return false;
        }

        if($this == self::TRASH) {
            return $this->creator_id == auth()->id();
        }

        if($task->archived_at != null) {
            return false;
        }

        if($this == self::ARCHVIE) {
            return $task->status == self::DONE AND $this->creator_id == auth()->id();
        }

        if($this == self::TO_DO) {
            return $task->assigned_id == auth()->id();
        }

        if($this == self::IN_PROGRESS) {
            return $task->assigned_id != auth()->id() AND $this->task->status != self::IN_PROGRESS;
        }

        if($this == self::IN_TEST) {
            return $task->assigned_id == auth()->id() AND $task->status == self::IN_PROGRESS AND $task->isCompleted();
        }

        if($this == self::DONE) {
            return true; // TODO, jeśli jest na in_progress to tylko wtedy jeśli nie ma podpiętego flow testu, jeśli jest na in_test to jeśli przeszedł wszystkie testy pozytywnie
        }
    }
}
