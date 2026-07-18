<?php

namespace App\Modules\Disk\Http\Requests;

use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Rules\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload straight onto the disk. Multipart, so it also serves programmatic producers that
 * post a Blob (the image editor saves its result this way).
 *
 * See UploadTempFileRequest for the php.ini caveat behind the 100MB cap.
 */
class StoreFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', File::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:102400'],
            // null/absent = the workspace root. `bail` so a non-uuid never reaches ScopedExists.
            'folder_id' => ['nullable', 'bail', 'uuid', new ScopedExists(Folder::class)],
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
