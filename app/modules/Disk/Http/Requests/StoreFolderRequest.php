<?php

namespace App\Modules\Disk\Http\Requests;

use App\Modules\Disk\Models\Folder;
use App\Rules\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

class StoreFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Folder::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // ScopedExists, never a bare `exists`: a folder id from another workspace must be
            // rejected at write time, not silently reparented. `bail` so a non-uuid (e.g. a
            // synthetic `sys:` resource-folder id) fails the uuid rule and never reaches
            // ScopedExists, whose DB query would otherwise throw on a uuid column -> 500.
            'parent_id' => ['nullable', 'bail', 'uuid', new ScopedExists(Folder::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Folder name is required.',
            'name.max' => 'Folder name cannot exceed 255 characters.',
        ];
    }
}
