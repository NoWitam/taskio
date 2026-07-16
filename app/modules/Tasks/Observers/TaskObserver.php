<?php

namespace App\Modules\Tasks\Observers;

use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Repositories\TasksRepository;
use App\Tenancy\TenantContext;

class TaskObserver
{
    public function __construct(
        private readonly TasksRepository $repository
    ) {}

    /**
     * Handle the Task "created" event.
     */
    public function created(Task $task): void
    {
        $this->forgetCounts($task);
    }

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
        // Any persisted change can shift a per-status count (status moves, soft-delete
        // toggles via the status=trash update, etc.), so invalidate unconditionally.
        $this->forgetCounts($task);

        if (!$task->wasChanged('status') || $task->status !== TaskStatus::DONE) {
            return;
        }

        $task->formSubmission?->approve();
    }

    /**
     * Handle the Task "deleted" event (soft delete → trash bucket).
     */
    public function deleted(Task $task): void
    {
        $this->forgetCounts($task);
    }

    /**
     * Handle the Task "restored" event (trash → to_do).
     */
    public function restored(Task $task): void
    {
        $this->forgetCounts($task);
    }

    /**
     * Handle the Task "forceDeleted" event (permanent delete).
     */
    public function forceDeleted(Task $task): void
    {
        $this->forgetCounts($task);
    }

    /**
     * Invalidate the cached per-status counts for the task's workspace. Fires for
     * user-, workflow- and bot-authored mutations alike (all go through the model).
     *
     * Shared mode carries the workspace_id column; own-database mode has no such
     * column, so fall back to the active tenant context (the own-DB workspace during
     * the mutation). No workspace at all → the shared 'none' key.
     */
    private function forgetCounts(Task $task): void
    {
        $workspaceId = $task->getAttribute('workspace_id')
            ?? app(TenantContext::class)->id();

        $this->repository->forgetCounts($workspaceId);
    }
}
