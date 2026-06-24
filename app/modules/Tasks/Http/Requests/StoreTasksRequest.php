<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Forms\Models\Form;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Tasks\Models\Task;
use App\Rules\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check if creating new task or updating existing
        $task = $this->route('task');

        if ($task) {
            return $this->user()->can('update', $task);
        }

        return $this->user()->can('create', Task::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2500'],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'deadline' => ['nullable', 'date'],
            'assigned_id' => ['required', 'uuid', new ScopedExists(User::class)],
            'labels' => ['array', 'min:0', 'max:5'],
            'labels.*' => ['required', 'uuid'],
            'attachments' => ['array', 'min:0', 'max:5'],
            'attachments.*' => ['required', 'uuid'],
            'form_id' => ['nullable', 'uuid', new ScopedExists(Form::class)],
            'approval_pipeline_id' => ['nullable', 'uuid', new ScopedExists(ApprovalPipeline::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Task title is required.',
            'title.max' => 'Task title cannot exceed 255 characters.',
            'description.max' => 'Task description cannot exceed 2.500 characters.',
        ];
    }
}
