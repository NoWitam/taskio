<?php

namespace App\Modules\Forms\Http\Requests;

use App\Enums\IconEnum;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Rules\ValidFormContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check if creating new form or updating existing
        $form = $this->route('form');
        
        if ($form) {
            return $this->user()->can('update', $form);
        }
        
        return $this->user()->can('create', Form::class);
    }

    public function rules(): array
    {
        $form = $this->route('form');
        $requireMinimumFields = $form instanceof Form && $form->isEnabled() && $this->has('content');

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', Rule::enum(IconEnum::class)],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_anonymous' => ['boolean'],
            'content' => ['nullable', 'array', new ValidFormContent(requireMinimumFields: $requireMinimumFields)]
        ];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Form name is required.',
            'name.max' => 'Form name cannot exceed 255 characters.',
            'description.max' => 'Form description cannot exceed 1.000 characters.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'content' => 'form content',
        ];
    }
}
