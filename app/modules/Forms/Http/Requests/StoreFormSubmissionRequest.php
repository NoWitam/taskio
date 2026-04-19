<?php

namespace App\Modules\Forms\Http\Requests;

use App\Modules\Forms\Models\Form;
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
            'submittable_type' => ['nullable', 'string'],
            'submittable_id' => ['nullable', 'uuid'],
            'data' => ['required', 'array'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // For manual submissions (when submittable is not provided),
        // automatically set it to point to the Form itself
        if (!$this->has('submittable_type') || !$this->has('submittable_id')) {
            $this->merge([
                'submittable_type' => (new Form())->getMorphClass(),
                'submittable_id' => $this->input('form_id'),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'form_id.required' => 'Form ID is required.',
            'form_id.exists' => 'The selected form does not exist.',
            'data.required' => 'Form data is required.',
        ];
    }
}
