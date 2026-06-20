<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Changelog\Enums\ChangelogEvent;
use App\Modules\Changelog\Managers\ChangelogManager;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Services\FileService;
use App\Modules\Forms\DTOs\FormSubmissionDTO;
use App\Modules\Forms\Services\FormSubmissionService;
use App\Modules\Labels\Models\Label;
use App\Modules\Tasks\DTOs\TaskDTO;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskService
{
    private const MAX_ATTACHMENTS = 5;

    private FileService $fileService;

    public function __construct()
    {
        $this->fileService = new FileService;
    }

    public function create(TaskDTO $dto)
    {
        return DB::transaction(function () use ($dto) {
            $task = Task::create([
                'title' => $dto->title,
                'description' => $dto->description,
                'status' => TaskStatus::TO_DO,
                'priority' => $dto->priority,
                'deadline' => $dto->deadline,
                'assigned_id' => $dto->assigned,
                'form_id' => $dto->form_id,
                'approval_pipeline_id' => $dto->approval_pipeline_id,
            ]);

            $this->guardAttachmentLimit($task, $dto->attachments);
            $this->fileService->attachToModel($task, $dto->attachments);

            $task->labels()->attach(
                Label::whereIn('id', $dto->labels)->pluck('id')
            );

            return $task;
        });
    }

    public function update(Task $task, TaskDTO $dto)
    {
        return DB::transaction(function () use ($task, $dto) {
            $task->update([
                'title' => $dto->title,
                'description' => $dto->description,
                'priority' => $dto->priority,
                'deadline' => $dto->deadline,
                'assigned_id' => $dto->assigned,
                'form_id' => $dto->form_id,
                'approval_pipeline_id' => $dto->approval_pipeline_id,
            ]);

            // Załączniki dodawane są addytywnie — przesłane ID to wyłącznie nowe
            // pliki tymczasowe; istniejące pozostają i usuwane są tylko przez
            // dedykowany endpoint removeAttachment.
            $this->guardAttachmentLimit($task, $dto->attachments);
            $this->fileService->attachToModel($task, $dto->attachments);

            // Synchronize labels with manual tracking
            $newLabelIds = Label::whereIn('id', $dto->labels)->pluck('id');
            $changes = $task->labels()->sync($newLabelIds);

            // Log label changes using manual()
            if (!empty($changes['attached']) || !empty($changes['detached'])) {
                app(\App\Modules\Changelog\Managers\ChangelogManager::class)->manual($task, 'labels', function ($tracker) use ($changes) {
                    foreach ($changes['attached'] as $labelId) {
                        $tracker->attach($labelId);
                    }

                    foreach ($changes['detached'] as $labelId) {
                        $tracker->detach($labelId);
                    }
                });
            }

            return $task;
        });
    }

    public function removeAttachment(Task $task, File $file): void
    {
        if ($file->fileable_type !== $task->getMorphClass() || $file->fileable_id !== $task->getKey()) {
            throw ValidationException::withMessages([
                'file' => ['This attachment does not belong to the task.'],
            ]);
        }

        $this->fileService->detach($task, $file);
    }

    private function guardAttachmentLimit(Task $task, array $newFileIds): void
    {
        $current = $task->files()->whereNotIn('id', $newFileIds)->count();

        if ($current + count($newFileIds) > self::MAX_ATTACHMENTS) {
            throw ValidationException::withMessages([
                'attachments' => ['A task can have at most ' . self::MAX_ATTACHMENTS . ' attachments.'],
            ]);
        }
    }

    public function index(Request $request)
    {
        return $this->listQuery($request)->cursorPaginate(8);
    }

    public function count(Request $request)
    {
        return $this->listQuery($request)->count();
    }

    protected function listQuery(Request $request)
    {
        return Task::query()
            ->with('assigned', 'labels')
            ->withCount('comments')
            ->when(
                $status = $request->enum('status', TaskStatus::class),
                function (Builder $query) use ($status) {
                    $status->resolveSelectQuery($query);
                }
            )
            ->search(['title', 'description'], $request->get('search'))
            ->when(
                $priority = $request->enum('priority', TaskPriority::class),
                function (Builder $query) use ($priority) {
                    $query->where('priority', $priority);
                }
            )
            ->when(
                $users = $request->array('user_id'),
                function (Builder $query) use ($users) {
                    $query->where(function (Builder $usersQuery) use ($users) {
                        $usersQuery->whereIn('creator_id', $users)->orWhereIn('assigned_id', $users);
                    });
                }
            )
            ->when(
                $request->boolean('hide_without_deadline'),
                function (Builder $query) {
                    $query->whereNotNull('deadline');
                }
            )
            ->filterByDate('deadline', $request)
            ->filterByLabels($request->array('labels'), $request->string('label_operator'));
    }

    public function submitForm(Task $task, array $data): Task
    {
        $submissionService = app(FormSubmissionService::class);
        $changelogManager = app(ChangelogManager::class);

        DB::transaction(function () use ($task, $data, $submissionService, $changelogManager) {

            $task->loadMissing('formSubmission');

            if ($task->formSubmission) {
                $submissionService->update($task->formSubmission, $data);
            } else {
                $submissionService->create(
                    new FormSubmissionDTO(
                        form_id: $task->form_id,
                        submittable_type: $task->getMorphClass(),
                        submittable_id: $task->getKey(),
                        data: $data
                    )
                );
            }

            $changelogManager->handleCustomEvent(
                $task,
                ChangelogEvent::FORM_FILLED,
                []
            );
        });

        return Task::with(['assigned', 'creator', 'labels', 'files', 'form', 'formSubmission'])
            ->findOrFail($task->id);
    }

    public function delete(Task $task): void
    {
        $task->update(['status' => TaskStatus::TRASH]);
        $task->delete();
    }

    public function restore(Task $task): Task
    {
        $task->restore();
        $task->update(['status' => TaskStatus::TO_DO]);

        return $task;
    }

    public function changeStatus(Task $task, TaskStatus $status): Task
    {
        return DB::transaction(function () use ($task, $status) {
            $task->update(['status' => $status]);

            // If task moves to IN_TEST and has an approval pipeline, start approval process
            if ($status === TaskStatus::IN_TEST && $task->hasApprovalPipeline()) {
                $task->loadMissing('approvalPipeline.stages');

                $approvalService = app(ApprovalService::class);
                $process = $approvalService->startProcess($task);

                // Assign task to the first stage approver (if user)
                if ($process->approver_id) {
                    $task->update(['assigned_id' => $process->approver_id]);
                }
            }

            return $task;
        });
    }
}
