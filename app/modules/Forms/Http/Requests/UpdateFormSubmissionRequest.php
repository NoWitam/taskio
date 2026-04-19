<?php

namespace App\Modules\Forms\Http\Requests;

use App\Modules\Forms\Models\FormSubmission;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFormSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $submission = $this->route('submission');
        
        if ($submission instanceof FormSubmission) {
            return $this->user()->can('update', $submission);
        }
        
        return false;
    }

    public function rules(): array
    {
        return [
            'data' => ['required', 'array'],
        ];
    }
}
