<?php

namespace App\Modules\FilterTabs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DestroyFilterTabRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('filter_tab'));
    }

    public function rules(): array
    {
        return [];
    }
}
