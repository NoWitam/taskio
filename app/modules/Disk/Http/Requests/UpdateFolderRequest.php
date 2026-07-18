<?php

namespace App\Modules\Disk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Renaming only. Re-parenting goes through the dedicated move endpoint, so an accidental
 * `parent_id` in an edit payload can never silently relocate a whole subtree.
 */
class UpdateFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('folder')) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
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
