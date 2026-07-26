<?php

namespace App\Modules\Disk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Overwrite a file's content (the preview editor's "Zapisz"). Multipart POST — PHP only parses
 * uploads on POST, which is why this is not a PUT.
 *
 * The mime is deliberately NOT pinned to the original: the row's mime_type/type are recomputed
 * server-side from the actual bytes (a text save arrives as the file's text mime, an image save as
 * png/jpeg/webp), and inline-serving safety is enforced at read time (B0 allowlist + nosniff) —
 * replacing is exactly as safe as a fresh upload. Same 100MB cap as StoreFileRequest.
 *
 * Disk-native-ness (a resource-owned file may not be overwritten) is a domain rule enforced in
 * FileService::replaceContent, like moving/trashing.
 */
class ReplaceFileContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('file')) ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:102400'],
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
