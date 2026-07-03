<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Bot\Models\Bot;
use App\Modules\Forms\Models\Form;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Tasks\Models\Task;
use App\Rules\ScopedExists;
use Closure;
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
            // Legacy user-only assignee. Still required UNLESS the explicit
            // polymorphic assignee_type is supplied (the new path).
            'assigned_id' => ['required_without:assignee_type', 'nullable', 'uuid', new ScopedExists(User::class)],
            // New polymorphic assignee. When present it wins over assigned_id.
            'assignee_type' => ['nullable', 'in:user,bot'],
            'assignee_id' => ['nullable', 'required_with:assignee_type', 'uuid', $this->scopedAssigneeRule()],
            'labels' => ['array', 'min:0', 'max:5'],
            'labels.*' => ['required', 'uuid'],
            'attachments' => ['array', 'min:0', 'max:5'],
            'attachments.*' => ['required', 'uuid'],
            'form_id' => ['nullable', 'uuid', new ScopedExists(Form::class)],
            'approval_pipeline_id' => ['nullable', 'uuid', new ScopedExists(ApprovalPipeline::class)],
        ];
    }

    /**
     * Validate assignee_id against the model named by assignee_type (User or Bot),
     * through the workspace-scoped existence rule. Skipped when assignee_type is absent.
     */
    private function scopedAssigneeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $type = $this->string('assignee_type')->value() ?: null;

            if ($type === null || $value === null || $value === '') {
                return;
            }

            $modelClass = match ($type) {
                'user' => User::class,
                'bot' => Bot::class,
                default => null,
            };

            if ($modelClass === null) {
                return; // invalid type already caught by the `in:` rule.
            }

            (new ScopedExists($modelClass))->validate($attribute, $value, $fail);
        };
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
