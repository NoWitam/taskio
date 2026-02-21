<?php

namespace App\Modules\Users\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:255'],
            'ids' => ['nullable', 'array'],
            'ids.*' => ['uuid'],
        ];
    }
}
