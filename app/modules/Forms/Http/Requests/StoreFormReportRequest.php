<?php

namespace App\Modules\Forms\Http\Requests;

use App\Modules\Forms\Models\Form;
use App\Rules\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFormReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $form = Form::findOrFail($this->input('form_id'));

        return $this->user()->can('createReport', $form) ?? false;
    }

    public function rules(): array
    {
        return [
            'form_id' => ['required', 'uuid', new ScopedExists(Form::class)],
            'name' => ['required', 'string', 'max:255'],
            'guidelines' => ['nullable', 'string', 'max:5000'],
            'sources' => ['nullable', 'array'],
            'sources.*' => ['string', Rule::in(['task', 'form'])],
            'submissions_from' => ['nullable', 'date', 'before_or_equal:submissions_to'],
            'submissions_to' => ['nullable', 'date', 'after_or_equal:submissions_from'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $form = Form::find($this->input('form_id'));

        if (!$form || !$form->isEnabled()) {
            return;
        }

        // Set default submissions_from to form's created_at if not provided
        if (!$this->has('submissions_from')) {
            $this->merge([
                'submissions_from' => $form->enabled_at->format('Y-m-d'),
            ]);
        }

        // Set default submissions_to to now if not provided
        if (!$this->has('submissions_to')) {
            $this->merge([
                'submissions_to' => now()->format('Y-m-d'),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'form_id.required' => 'ID formularza jest wymagane.',
            'form_id.exists' => 'Wybrany formularz nie istnieje.',
            'name.required' => 'Nazwa raportu jest wymagana.',
            'name.max' => 'Nazwa raportu nie może być dłuższa niż 255 znaków.',
            'guidelines.max' => 'Wytyczne nie mogą być dłuższe niż 5000 znaków.',
            'sources.*.in' => 'Nieprawidłowe źródło danych.',
            'submissions_from.date' => 'Data początkowa musi być prawidłową datą.',
            'submissions_from.before_or_equal' => 'Data początkowa musi być wcześniejsza lub równa dacie końcowej.',
            'submissions_to.date' => 'Data końcowa musi być prawidłową datą.',
            'submissions_to.after_or_equal' => 'Data końcowa musi być późniejsza lub równa dacie początkowej.',
        ];
    }
}
