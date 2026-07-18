<?php

namespace App\Modules\Forms\Http\Requests;

use App\Modules\Disk\Models\File;
use App\Modules\Forms\Enums\FormElementType;
use App\Modules\Forms\Models\Form;
use App\Rules\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class StoreFormSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'form_id' => ['required', 'uuid', new ScopedExists(Form::class)],
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
                'submittable_type' => (new Form)->getMorphClass(),
                'submittable_id' => $this->input('form_id'),
            ]);
        }
    }

    /**
     * Validate every file answer against the disk: each must be a live, workspace-scoped File,
     * and an un-attached temp may only be referenced by whoever uploaded it (otherwise one user
     * could bind another's in-flight upload to their own submission). File fields are discovered
     * from the form's own content, so a non-file field can never smuggle a file uuid through.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $formId = $this->input('form_id');
            $data = $this->input('data');

            if (!is_string($formId) || !is_array($data)) {
                return; // base rules already failed
            }

            $form = Form::query()->find($formId);

            if ($form === null) {
                return; // ScopedExists already reported the missing/foreign form
            }

            foreach (FormElementType::collectFileAnswers($form->content ?? [], $data) as $answer) {
                $this->validateFileAnswer($validator, $answer['field'], $answer['value']);
            }
        });
    }

    private function validateFileAnswer(Validator $validator, string $field, mixed $value): void
    {
        // An unanswered file field is simply absent; required-ness is a content-layer concern.
        if ($value === null || $value === '') {
            return;
        }

        $key = 'data.' . $field;

        if (!is_string($value) || !Str::isUuid($value)) {
            $validator->errors()->add($key, __('forms.validation.file_invalid'));

            return;
        }

        // File::query() carries the workspace + soft-delete global scopes, so a foreign-tenant
        // or (soft/disk) trashed id resolves to null here.
        $file = File::query()->whereKey($value)->first();

        if ($file === null || $file->disk_trashed_at !== null) {
            $validator->errors()->add($key, __('forms.validation.file_invalid'));

            return;
        }

        $isUnownedTemp = $file->fileable_type === null;
        $belongsToActor = (string) $file->uploader_id === (string) $this->user()?->getKey();

        if ($isUnownedTemp && !$belongsToActor) {
            $validator->errors()->add($key, __('forms.validation.file_not_owned'));
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
