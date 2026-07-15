<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Bot\Services\BotTaskExecutionService;
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
        $task = DB::transaction(function () use ($dto) {
            $task = Task::create([
                'title' => $dto->title,
                'description' => $dto->description,
                'status' => TaskStatus::TO_DO,
                'priority' => $dto->priority,
                'deadline' => $dto->deadline,
                'assignee_type' => $dto->assigneeType,
                'assignee_id' => $dto->assigneeId,
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

        // Kick off bot execution if the task was assigned to an executing bot.
        app(BotTaskExecutionService::class)->maybeDispatch($task);

        return $task;
    }

    public function update(Task $task, TaskDTO $dto)
    {
        $task = DB::transaction(function () use ($task, $dto) {
            $task->update([
                'title' => $dto->title,
                'description' => $dto->description,
                'priority' => $dto->priority,
                'deadline' => $dto->deadline,
                'assignee_type' => $dto->assigneeType,
                'assignee_id' => $dto->assigneeId,
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

        // A task re-assigned to an executing bot starts execution (idempotent).
        app(BotTaskExecutionService::class)->maybeDispatch($task);

        return $task;
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
            // `pendingApprovalProcess` feeds TaskListResource::is_in_approval via
            // Task::isInApproval()'s relationLoaded() short-circuit — eager-loading
            // it here turns a per-row exists() query into one batched query.
            // `assigned` (user-only, back-compat) + `assignee` (polymorphic User|Bot).
            ->with('assigned', 'assignee', 'labels', 'pendingApprovalProcess')
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
                    // A task matches a user filter if the user created it OR is its
                    // (User) assignee. Both sides are morph-type-guarded: creator_type='user'
                    // (a run/bot-created task never appears in a user's "mine") and
                    // assignee_type='user' (a bot id must not collide with a user id).
                    $query->where(function (Builder $usersQuery) use ($users) {
                        $usersQuery->where(function (Builder $creatorQuery) use ($users) {
                            $creatorQuery->where('creator_type', 'user')
                                ->whereIn('creator_id', $users);
                        })
                            ->orWhere(function (Builder $assigneeQuery) use ($users) {
                                $assigneeQuery->where('assignee_type', 'user')
                                    ->whereIn('assignee_id', $users);
                            });
                    });
                }
            )
            ->when(
                $bots = $request->array('bot_id'),
                function (Builder $query) use ($bots) {
                    $query->where('assignee_type', 'bot')->whereIn('assignee_id', $bots);
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

        return Task::with(Task::detailRelations())
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
                $this->startApprovalForInTest($task);
            }

            return $task;
        });
    }

    /**
     * Bot-driven transition to IN_PROGRESS. Bots are not interactive users, so they
     * bypass the user-centric TaskStatus::canSetOn guard — this is the single internal
     * seam through which a bot moves a task it is executing into progress.
     */
    public function botStart(Task $task): Task
    {
        $task->update(['status' => TaskStatus::IN_PROGRESS]);

        return $task;
    }

    /**
     * Bot-driven transition to IN_TEST. Bypasses canSetOn (bot, not user) and reuses
     * the existing in_test -> approval trigger so an attached pipeline starts exactly
     * as it would for a human submission.
     */
    public function botSubmitToTest(Task $task): Task
    {
        return DB::transaction(function () use ($task) {
            $task->update(['status' => TaskStatus::IN_TEST]);

            if ($task->hasApprovalPipeline()) {
                $this->startApprovalForInTest($task);
            }

            return $task;
        });
    }

    /**
     * Start the approval process for a task entering IN_TEST and reassign it to the
     * first (User) stage approver. Shared by the human (changeStatus) and bot
     * (botSubmitToTest) paths so the behavior is identical.
     */
    private function startApprovalForInTest(Task $task): void
    {
        $task->loadMissing('approvalPipeline.stages');

        $process = app(ApprovalService::class)->startProcess($task);

        // Assign task to the first stage approver (always a User approver).
        if ($process->approver_id) {
            $task->update([
                'assignee_type' => 'user',
                'assignee_id' => $process->approver_id,
            ]);
        }
    }
}
