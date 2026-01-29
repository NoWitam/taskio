<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Tasks\Enums\TaskPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2500'],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'deadline' => ['nullable', 'date'],
            'assigned_id' => ['required', 'uuid', 'exists:users,id'],
            'labels' => ['array', 'min:0', 'max:5'],
            'labels.*' => ['required', 'uuid'],
            'attachments' => ['array', 'min:0', 'max:5'],
            'attachments.*' => ['required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Task title is required.',
            'title.max' => 'Task title cannot exceed 255 characters.',
            'description.max' => 'Task description cannot exceed 2.500 characters.'
        ];
    }
}
