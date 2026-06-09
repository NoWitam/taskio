<?php

namespace App\Modules\Approvals\Http\Requests;

use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Approvals\Models\ApprovalProcess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MakeDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $process = $this->route('process');

        return $this->user()->can('decide', $process);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in([
                ApprovalProcessStatus::Approved->value,
                ApprovalProcessStatus::Rejected->value,
            ])],
            'note' => ['nullable', 'required_if:decision,rejected', 'string', 'max:2500'],
        ];
    }
}
