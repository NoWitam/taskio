<?php

namespace App\Modules\FilterTabs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexFilterTabsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'context' => ['required', 'string'],
        ];
    }
}
