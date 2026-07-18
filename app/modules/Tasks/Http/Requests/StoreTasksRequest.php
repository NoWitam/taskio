<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Bot\Models\Bot;
use App\Modules\Disk\Models\File;
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
            // ScopedExists (not a bare uuid): a file id from another workspace must be
            // rejected at write time. The claim rule below narrows it further.
            'attachments.*' => ['required', 'uuid', new ScopedExists(File::class), $this->claimableAttachmentRule()],
            'form_id' => ['nullable', 'uuid', new ScopedExists(Form::class)],
            'approval_pipeline_id' => ['nullable', 'uuid', new ScopedExists(ApprovalPipeline::class)],
        ];
    }

    /**
     * Which files this request may put on the task.
     *
     * `attachments` carries the FULL list on an update (the client re-sends what is already
     * there alongside anything new), so two shapes are legitimate:
     *   - a TEMP upload of MINE (fresh from /disk/temp, not yet owned by anything), or
     *   - a file ALREADY attached to this very task.
     *
     * Everything else is refused rather than silently ignored: attachToModel() only rebinds
     * temp rows, so without this a member could quietly hand another member's pending upload
     * to their own task, and any other id would vanish without a word.
     */
    private function claimableAttachmentRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $file = File::query()->find($value);

            if ($file === null) {
                return; // ScopedExists already reported it.
            }

            $task = $this->route('task');

            if ($task instanceof Task && $file->fileable_type === $task->getMorphClass() && $file->fileable_id === $task->getKey()) {
                return; // already this task's attachment — re-sent unchanged.
            }

            if (!$file->isOwnedByResource() && $file->folder_id === null && $file->uploader_id === $this->user()?->id) {
                return; // my own pending upload.
            }

            $fail('This file cannot be attached to the task.');
        };
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
