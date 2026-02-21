<?php

namespace App\Modules\Tasks\Enums;

use App\Models\User;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;

enum TaskStatus: string
{
    CASE TO_DO = 'to_do';
    case IN_PROGRESS = 'in_progress';
    case IN_TEST = 'in_test';
    case DONE = 'done';

    case ARCHIVE = 'archive';
    case TRASH = 'trash';

    public function resolveSelectQuery(Builder $query): void
    {
        match($this)
        {
            self::ARCHIVE => $query->onlyArchived(),
            self::TRASH => $query->onlyTrashed(),
            default => $query->where('status', $this)
        };
    }

    public function label(): string
    {
        return match($this)
        {
            self::TO_DO => 'Do zrobienia',
            self::IN_PROGRESS => 'W trakcie',
            self::IN_TEST => 'W testach',
            self::DONE => 'Zrobione',
            self::ARCHIVE => 'Archiwum',
            self::TRASH => 'Kosz'
        };
    }

    public function tone(): string
    {
        return match($this)
        {
            self::TO_DO => 'neutral',
            self::IN_PROGRESS => 'primary',
            self::IN_TEST => 'warning',
            self::DONE => 'success',
            self::ARCHIVE => 'neutral',
            self::TRASH => 'neutral'
        };
    }

    public function icon(): string
    {
        return match($this)
        {
            self::TO_DO => 'circle-check',
            self::IN_PROGRESS => 'circle-check',
            self::IN_TEST => 'circle-check',
            self::DONE => 'circle-check',
            self::ARCHIVE => 'archive',
            self::TRASH => 'trash'
        };
    }

    public static function canSetOn(Task $task, TaskStatus $newStatus, ?User $user): bool
    {
        if ($task->deleted_at != null) {
            return false;
        }

        if ($newStatus == self::TRASH) {
            return $task->creator_id == $user?->id;
        }

        if ($task->archived_at != null) {
            return false;
        }

        if ($newStatus == self::ARCHIVE) {
            return $task->status == self::DONE && $task->creator_id == $user?->id;
        }

        if ($newStatus == self::TO_DO) {
            return $task->assigned_id == $user?->id;
        }

        if ($newStatus == self::IN_PROGRESS) {
            return $task->assigned_id == $user?->id && $task->status != self::IN_PROGRESS;
        }

        if ($newStatus == self::IN_TEST) {
            return $task->assigned_id == $user?->id && $task->status == self::IN_PROGRESS && $task->isCompleted();
        }

        if ($newStatus == self::DONE) {
            return true; // TODO, jeśli jest na in_progress to tylko wtedy jeśli nie ma podpiętego flow testu, jeśli jest na in_test to jeśli przeszedł wszystkie testy pozytywnie
        }

        return false;
    }
}
