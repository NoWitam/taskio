<?php

namespace App\Modules\Workflows\Http\Requests;

use App\Modules\Workflows\Enums\WorkflowStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Toggle a workflow's status (active|inactive). Authorization is creator-only (mirrors
 * update) via WorkflowPolicy::changeStatus. This is the ONLY path that mutates status.
 */
class ChangeWorkflowStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('changeStatus', $this->route('workflow'));
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(WorkflowStatus::class)],
        ];
    }
}
