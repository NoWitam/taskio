<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

class RestoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Task is soft deleted, need to get from route parameter 'id'
        $taskId = $this->route('id');
        $task = Task::withTrashed()->findOrFail($taskId);
        return $this->user()->can('restore', $task);
    }

    public function rules(): array
    {
        return [];
    }
}
