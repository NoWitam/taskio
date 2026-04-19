<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

class ChangeTaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');
        $status = $this->route('status');
        return $this->user()->can('changeStatus', [$task, $status]);
    }

    public function rules(): array
    {
        return [];
    }
}
