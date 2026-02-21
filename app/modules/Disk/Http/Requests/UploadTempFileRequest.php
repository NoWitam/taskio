<?php

namespace App\Modules\Disk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadTempFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:102400'], // max 100MB
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'File is required.',
            'file.file' => 'Uploaded file is not valid.',
            'file.max' => 'File size cannot exceed 100MB.',
        ];
    }
}
