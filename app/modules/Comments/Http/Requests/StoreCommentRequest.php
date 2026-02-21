<?php

namespace App\Modules\Comments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:5000']
        ];
    }

    public function messages(): array
    {
        return [
            'content.required' => 'Comment content is required',
            'content.string' => 'Comment content must be a string',
            'content.max' => 'Comment content cannot exceed 5000 characters'
        ];
    }
}
