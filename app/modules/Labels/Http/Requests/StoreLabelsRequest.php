<?php

namespace App\Modules\Labels\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLabelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Label name is required.',
            'name.max' => 'Label name cannot exceed 255 characters.',
            'color.regex' => 'Color must be in valid HEX format (e.g., #FF5733).',
            'description.max' => 'Description cannot exceed 1000 characters.',
        ];
    }
}
