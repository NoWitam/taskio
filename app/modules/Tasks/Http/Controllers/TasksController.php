<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\DTOs\TaskDTO;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Http\Requests\ChangeTaskStatusRequest;
use App\Modules\Tasks\Http\Requests\DeleteTaskRequest;
use App\Modules\Tasks\Http\Requests\ForceDeleteTaskRequest;
use App\Modules\Tasks\Http\Requests\RestoreTaskRequest;
use App\Modules\Tasks\Http\Requests\StoreTasksRequest;
use App\Modules\Tasks\Http\Requests\SubmitTaskFormRequest;
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
        $paginator = $this->service->index($request);

        return TaskListResource::collection($paginator)->additional(['meta' => [
            'total' => !$request->has('cursor') 
                ? $this->service->count($request) 
                : null
        ]]);
    }

    public function show(Request $request, string $id): TaskResource
    {
        $task = Task::withTrashed()->findOrFail($id);

        return TaskResource::make(
            $task->loadMissing(['assigned', 'creator', 'labels', 'files', 'form', 'formSubmission'])
        );
    }

    public function store(StoreTasksRequest $request): TaskResource
    {
        return TaskResource::make(
            $this->service->create(
                TaskDTO::fromRequest($request)
            )
        );
    }

    public function update(StoreTasksRequest $request, Task $task): TaskResource
    {
        $this->service->update(
            $task,
            TaskDTO::fromRequest($request)
        );
        
        return TaskResource::make(
            $task->loadMissing(['assigned', 'creator', 'labels', 'files', 'form', 'formSubmission'])
        ); 
    }

    public function destroy(DeleteTaskRequest $request, Task $task): \Illuminate\Http\JsonResponse
    {
        $this->service->delete($task);

        return response()->json([
            'message' => 'Task moved to trash successfully'
        ]);
    }

    public function forceDestroy(ForceDeleteTaskRequest $request, Task $task): \Illuminate\Http\JsonResponse
    {
        $task->forceDelete();

        return response()->json([
            'message' => 'Task permanently deleted'
        ]);
    }

    public function restore(RestoreTaskRequest $request, string $id): TaskResource
    {
        $task = Task::withTrashed()->findOrFail($id);

        return TaskResource::make(
            $this->service->restore($task)->loadMissing([
                'assigned', 'creator', 'labels', 'files', 'form', 'formSubmission'
            ])
        );
    }

    public function changeStatus(ChangeTaskStatusRequest $request, Task $task, TaskStatus $status): TaskResource
    {
        if ($status === TaskStatus::TRASH) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => ['Use DELETE endpoint for moving task to trash']
            ]);
        }

        $task->update(['status' => $status]);

        return TaskResource::make(
            $task->loadMissing(['assigned', 'creator', 'labels', 'files', 'form', 'formSubmission'])
        );
    }

    public function submitForm(SubmitTaskFormRequest $request, Task $task): TaskResource
    {
        return TaskResource::make(
            $this->service->submitForm($task, $request->input('data'))
        );
    }
}
