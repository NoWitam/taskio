<?php

namespace App\Modules\Disk\Http\Requests;

use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Rules\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Copy an existing file into the disk under a new name and (optional) folder. Authorization of
 * the SOURCE happens in the controller (`view` on the route-bound {file}); here we only gate the
 * ability to create a new disk file and validate the destination.
 */
class CopyFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', File::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // null/absent = the workspace root. `bail` so a non-uuid never reaches ScopedExists.
            'folder_id' => ['nullable', 'bail', 'uuid', new ScopedExists(Folder::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'A name is required for the copy.',
            'name.max' => 'Name cannot exceed 255 characters.',
        ];
    }
}
