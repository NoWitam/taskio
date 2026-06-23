<?php

namespace App\Modules\Tasks\Observers;

use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;

class TaskObserver
{
    /**
     * Handle the Task "updated" event.
     *
     * When a task transitions to DONE and has an attached form submission,
     * mark that submission as approved. A task's form is filled while the task
     * is being worked on, so its submission stays a draft until the task is
     * finished — reaching DONE confirms it.
     *
     * Covers both ways a task reaches DONE: the manual status change
     * (TaskService::changeStatus) and approval-pipeline completion
     * (Task::onApprovalCompleted), since both persist the status via update().
     */
    public function updated(Task $task): void
    {
        if (!$task->wasChanged('status') || $task->status !== TaskStatus::DONE) {
            return;
        }

        $task->formSubmission?->approve();
    }
}
