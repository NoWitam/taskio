<?php

namespace App\Modules\Forms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFormSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'form_id' => ['required', 'uuid', 'exists:forms,id'],
            'submittable_type' => ['required', 'string'],
            'submittable_id' => ['required', 'uuid'],
            'data' => ['required', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'form_id.required' => 'Form ID is required.',
            'form_id.exists' => 'The selected form does not exist.',
            'submittable_type.required' => 'Submittable type is required.',
            'submittable_id.required' => 'Submittable ID is required.',
            'data.required' => 'Form data is required.',
        ];
    }
}
