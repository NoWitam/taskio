<?php

namespace App\Modules\Workflows\Http\Requests;

/**
 * Update shares the Store validation rules (including the per-trigger-type config and
 * cross-type rejection); only the authorization target differs (an existing workflow
 * resolved from the route).
 */
class UpdateWorkflowRequest extends StoreWorkflowRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('workflow'));
    }
}
