<?php

namespace App\Modules\Forms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFormSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'data' => ['required', 'array'],
        ];
    }
}
