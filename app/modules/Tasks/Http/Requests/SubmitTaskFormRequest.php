<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

class SubmitTaskFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');
        return $this->user()->can('update', $task);
    }

    public function rules(): array
    {
        return [
            'data' => ['required', 'array'],
        ];
    }
}
