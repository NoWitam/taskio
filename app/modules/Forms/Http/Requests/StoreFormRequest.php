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
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', Rule::enum(IconEnum::class)],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_anonymous' => ['boolean'],
        ];

        // Content can only be updated if form is not enabled yet
        if (!$form || ($form instanceof Form && $form->canBeEdited())) {
            $rules['content'] = ['nullable', 'array', new ValidFormContent()];
        }

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

    /**
     * Handle a passed validation attempt.
     */
    protected function passedValidation(): void
    {
        $form = $this->route('form');
        
        // If trying to update an enabled form and content is provided in request,
        // add a custom validation error
        if ($form instanceof Form && $form->isEnabled() && $this->has('content')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'content' => ['Nie można edytować struktury formularza po jego włączeniu.'],
            ]);
        }
    }
}
