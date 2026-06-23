<?php

namespace App\Modules\Tasks\Enums;

use App\Models\User;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;

enum TaskStatus: string
{
    case TO_DO = 'to_do';
    case IN_PROGRESS = 'in_progress';
    case IN_TEST = 'in_test';
    case DONE = 'done';

    case ARCHIVE = 'archive';
    case TRASH = 'trash';

    public function resolveSelectQuery(Builder $query): void
    {
        match ($this) {
            self::ARCHIVE => $query->onlyArchived(),
            self::TRASH => $query->onlyTrashed(),
            default => $query->where('status', $this)
        };
    }

    public function label(): string
    {
        return match ($this) {
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
        return match ($this) {
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
        return match ($this) {
            self::TO_DO => 'circle-help',
            self::IN_PROGRESS => 'loader',
            self::IN_TEST => 'info-circle',
            self::DONE => 'check-circle',
            self::ARCHIVE => 'archive',
            self::TRASH => 'trash'
        };
    }

    public static function canSetOn(Task $task, TaskStatus $newStatus, ?User $user): bool
    {
        if ($task->deleted_at != null) {
            return false;
        }

        // Trash (delete path): creator-only. Evaluated before the archived guard.
        if ($newStatus === self::TRASH) {
            return $task->creator_id === $user?->id;
        }

        if ($task->archived_at != null) {
            return false;
        }

        // Block all manual status changes while a pending approval process exists.
        // The approval outcome moves the task automatically (see Task::onApproval*).
        if ($task->isInApproval()) {
            return false;
        }

        // Archive is a separate lifecycle (not part of the manual transition tree):
        // only a DONE task can be archived, and only by its creator.
        if ($newStatus === self::ARCHIVE) {
            return $task->status === self::DONE && $task->creator_id === $user?->id;
        }

        $isAssignee = $task->assigned_id === $user?->id;
        $isCreator = $task->creator_id === $user?->id;

        return match ([$task->status, $newStatus]) {
            [self::TO_DO, self::IN_PROGRESS] => $isAssignee,
            [self::IN_PROGRESS, self::TO_DO] => $isAssignee,
            [self::IN_PROGRESS, self::IN_TEST] => $isAssignee,
            [self::IN_TEST, self::TO_DO] => $isCreator && !$task->hasApprovalPipeline(),
            [self::IN_TEST, self::DONE] => $isCreator && !$task->hasApprovalPipeline(),
            [self::DONE, self::TO_DO] => $isAssignee || $isCreator,
            [self::DONE, self::IN_PROGRESS] => $isAssignee,
            default => false,
        };
    }
}
