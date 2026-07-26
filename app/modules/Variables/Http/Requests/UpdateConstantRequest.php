<?php

namespace App\Modules\Variables\Http\Requests;

use App\Modules\Variables\Models\Constant;

/**
 * Update shares the Store validation rules (identity + type/value checks); only the authorization
 * target differs (an existing constant resolved from the route) and the uniqueness check excludes the
 * constant itself.
 */
class UpdateConstantRequest extends StoreConstantRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('constant'));
    }

    protected function currentConstantId(): ?string
    {
        $constant = $this->route('constant');

        return $constant instanceof Constant ? $constant->getKey() : null;
    }
}
