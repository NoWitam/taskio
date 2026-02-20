<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Disk\Models\File;
use App\Modules\Disk\Services\FileService;
use App\Modules\Labels\Models\Label;
use App\Modules\Tasks\DTOs\TaskDTO;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskService
{
    private FileService $fileService;

    public function __construct()
    {
        $this->fileService = new FileService();
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
                'assigned_id' => $dto->assigned
            ]);

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
                'assigned_id' => $dto->assigned
            ]);

            $this->fileService->attachToModel($task, $dto->attachments, deleteAnother: true);

            // Synchronizacja labels z ręcznym trackowaniem
            $newLabelIds = Label::whereIn('id', $dto->labels)->pluck('id');
            $changes = $task->labels()->sync($newLabelIds);
            
            // Loguj zmiany etykiet przez manual()
            if (!empty($changes['attached']) || !empty($changes['detached'])) {
                app(\App\Modules\Changelog\Managers\ChangelogManager::class)->manual($task, 'labels', function ($tracker) use ($changes) {
                    foreach ($changes['attached'] as $labelId) {
                        $tracker->attach($labelId);
                    }
                    
                    foreach ($changes['detached'] as $labelId) {
                        $tracker->dettach($labelId);
                    }
                });
            }

            return $task;
        });
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
            ->when(
                $request->has('search'), 
                function (Builder $query) use ($request) {
                    $query->where(function (Builder $searchQuery) use ($request) {
                        $term = '%' . $request->get('search') . '%';
                        $searchQuery->whereLike('title', $term)->orWhereLike('description', $term);
                    });
                }
            )
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
}
