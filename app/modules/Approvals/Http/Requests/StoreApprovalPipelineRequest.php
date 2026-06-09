<?php

namespace App\Modules\Approvals\Http\Requests;

use App\Modules\Approvals\Enums\ApproverType;
use App\Modules\Approvals\Models\ApprovalPipeline;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApprovalPipelineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $pipeline = $this->route('pipeline');

        if ($pipeline) {
            return $this->user()->can('update', $pipeline);
        }

        return $this->user()->can('create', ApprovalPipeline::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:2500'],
            'stages' => ['required', 'array', 'min:1'],
            'stages.*.name' => ['required', 'string', 'max:255'],
            'stages.*.icon' => ['nullable', 'string', 'max:50'],
            'stages.*.description' => ['nullable', 'string', 'max:2500'],
            'stages.*.approver_type' => ['required', Rule::enum(ApproverType::class)],
            'stages.*.approver_id' => ['nullable', 'required_if:stages.*.approver_type,user', 'uuid', 'exists:users,id'],
        ];
    }
}
