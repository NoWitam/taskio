<?php

namespace App\Modules\FilterTabs\Http\Requests;

use App\Modules\FilterTabs\Models\FilterTab;
use Illuminate\Foundation\Http\FormRequest;

class ReorderFilterTabsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FilterTab::class);
    }

    public function rules(): array
    {
        return [
            'context' => ['required', 'string'],
            'ids' => ['required', 'array'],
            'ids.*' => ['uuid'],
        ];
    }
}
