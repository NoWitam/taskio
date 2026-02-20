<?php

namespace App\Modules\Tasks\Policies;

use App\Models\User;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;

class TaskPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(?User $user): bool
    {
        return true; // Każdy może przeglądać listę zadań
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(?User $user, Task $task): bool
    {
        return true; // Każdy może zobaczyć szczegóły zadania
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(?User $user): bool
    {
        return $user !== null; // Tylko zalogowani użytkownicy mogą tworzyć zadania
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(?User $user, Task $task): bool
    {
        // Creator lub assigned user może edytować zadanie
        return $task->creator_id === $user->id || $task->assigned_id === $user->id;
    }

    /**
     * Determine whether the user can delete the model (move to trash).
     */
    public function delete(?User $user, Task $task): bool
    {
        // Tylko creator może usunąć zadanie
        return $task->creator_id === $user->id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(?User $user, Task $task): bool
    {
        // Tylko creator może permanentnie usunąć zadanie
        return $task->creator_id === $user->id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(?User $user, Task $task): bool
    {
        // Tylko creator może przywrócić zadanie
        return $task->creator_id === $user->id;
    }

    /**
     * Determine whether the user can change status of the model.
     */
    public function changeStatus(?User $user, Task $task, TaskStatus $newStatus): bool
    {
        if ($newStatus === TaskStatus::TRASH) {
            return false;
        }

        // Używamy logiki biznesowej z enum
        return TaskStatus::canSetOn($task, $newStatus, $user);
    }
}
