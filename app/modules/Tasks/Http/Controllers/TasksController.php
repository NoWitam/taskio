<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\DTOs\TaskDTO;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Http\Requests\StoreTasksRequest;
use App\Modules\Tasks\Http\Resources\TaskListResource;
use App\Modules\Tasks\Http\Resources\TaskResource;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TasksController extends Controller
{
    public function __construct(
        private TaskService $service
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Task::class);

        $paginator = $this->service->index($request);

        return TaskListResource::collection($paginator)->additional(['meta' => [
            'total' => !$request->has('cursor') 
                ? $this->service->count($request) 
                : null
        ]]);
    }

    public function show(Request $request, Task $task): TaskResource
    {
        $this->authorize('view', $task);

        return TaskResource::make(
            $task->loadMissing(['assigned', 'creator', 'labels', 'files'])
        );
    }

    public function store(StoreTasksRequest $request): TaskResource
    {
        $this->authorize('create', Task::class);

        return TaskResource::make(
            $this->service->create(
                TaskDTO::fromRequest($request)
            )
        );
    }

    public function update(StoreTasksRequest $request, Task $task): TaskResource
    {
        $this->authorize('update', $task);

        $this->service->update(
            $task,
            TaskDTO::fromRequest($request)
        );
        
        return TaskResource::make(
            $task->loadMissing(['assigned', 'creator', 'labels', 'files'])
        ); 
    }

    public function destroy(Task $task): \Illuminate\Http\JsonResponse
    {
        $this->authorize('delete', $task);

        $task->update(['status' => TaskStatus::TRASH]);
        $task->delete();

        return response()->json([
            'message' => 'Task moved to trash successfully'
        ]);
    }

    public function forceDestroy(Task $task): \Illuminate\Http\JsonResponse
    {
        $this->authorize('forceDelete', $task);

        $task->forceDelete();

        return response()->json([
            'message' => 'Task permanently deleted'
        ]);
    }

    public function restore(string $id): TaskResource
    {
        $task = Task::withTrashed()->findOrFail($id);
        $this->authorize('restore', $task);

        $task->restore();
        $task->update(['status' => TaskStatus::TO_DO]);

        return TaskResource::make(
            $task->loadMissing(['assigned', 'creator', 'labels', 'files'])
        );
    }

    public function changeStatus(Task $task, TaskStatus $status): TaskResource
    {
        if ($status === TaskStatus::TRASH) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => ['Use DELETE endpoint for moving task to trash']
            ]);
        }

        $this->authorize('changeStatus', [$task, $status]);

        $task->update(['status' => $status]);

        return TaskResource::make(
            $task->loadMissing(['assigned', 'creator', 'labels', 'files'])
        );
    }
}
