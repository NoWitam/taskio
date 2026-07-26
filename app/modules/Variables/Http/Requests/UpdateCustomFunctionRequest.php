<?php

namespace App\Modules\Variables\Http\Requests;

use App\Modules\Variables\Models\CustomFunction;

/**
 * Update shares the Store validation rules (identity + signature + body + acyclicity); only the
 * authorization target differs (an existing function resolved from the route) and the cycle graph
 * substitutes THIS function's node with the new body (currentFunctionId).
 */
class UpdateCustomFunctionRequest extends StoreCustomFunctionRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('function'));
    }

    protected function currentFunctionId(): ?string
    {
        $function = $this->route('function');

        return $function instanceof CustomFunction ? $function->getKey() : null;
    }
}
