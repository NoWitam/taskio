<?php

namespace App\Modules\FilterTabs\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class FiltersPayloadRule implements ValidationRule
{
    private const MAX_BYTES = 16384;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $encoded = json_encode($value);

        if ($encoded === false || strlen($encoded) > self::MAX_BYTES) {
            $fail('filter_tabs.errors.filters_too_large');
        }
    }
}
