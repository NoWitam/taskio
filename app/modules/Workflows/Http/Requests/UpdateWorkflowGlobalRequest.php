<?php

namespace App\Modules\Workflows\Http\Requests;

use App\Modules\Workflows\Models\WorkflowGlobal;

/**
 * Update shares the Store validation rules (identity + type/value checks); only the authorization
 * target differs (an existing global resolved from the route) and the uniqueness check excludes the
 * global itself.
 */
class UpdateWorkflowGlobalRequest extends StoreWorkflowGlobalRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('workflow_global'));
    }

    protected function currentGlobalId(): ?string
    {
        $global = $this->route('workflow_global');

        return $global instanceof WorkflowGlobal ? $global->getKey() : null;
    }
}
