<?php

namespace App\Modules\Workspaces\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Set the workspace's monthly AI $ budget (R2 sub-stage 4). Authorizes via the OWNER-only
 * `manageAiBudget` Policy ability; the summary READ is a separate member-level endpoint.
 *
 * Body `monthly_cost_cap`: numeric >= 0, OR null. Semantics mirror the column — null CLEARS the override
 * (inherit the env default), a positive value is the workspace cap, and 0 is explicit UNLIMITED for this
 * workspace. `present` (not `required`) so a null is accepted to clear it.
 */
class UpdateAiBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageAiBudget', $this->route('workspace')) ?? false;
    }

    public function rules(): array
    {
        return [
            'monthly_cost_cap' => ['present', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'monthly_cost_cap.numeric' => __('workspaces.ai_budget.cap_invalid'),
            'monthly_cost_cap.min' => __('workspaces.ai_budget.cap_invalid'),
            'monthly_cost_cap.max' => __('workspaces.ai_budget.cap_invalid'),
        ];
    }
}
