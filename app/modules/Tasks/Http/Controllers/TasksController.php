<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Modules\Tasks\DTOs\TaskDTO;
use App\Modules\Tasks\Http\Requests\StoreTasksRequest;
use App\Modules\Tasks\Http\Resources\TaskListResource;
use App\Modules\Tasks\Http\Resources\TaskResource;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TasksController
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

    public function show(Request $request, Task $task): TaskResource
    {
        return TaskResource::make(
            $task->loadMissing(['assigned', 'creator', 'labels', 'files'])
        );
    }

    public function store(StoreTasksRequest $request): TaskResource
    {
        sleep(5);
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
            $task->loadMissing(['assigned', 'creator', 'labels', 'files'])
        ); 
    }
}
