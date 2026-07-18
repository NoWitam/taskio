<?php

namespace App\Modules\Disk\Http\Requests;

use App\Modules\Disk\Models\Folder;
use App\Modules\Labels\Models\Label;
use App\Rules\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Metadata edits. Every field is optional (`sometimes`): an absent key leaves the value
 * untouched, an explicit null clears it — which is how a file is moved to the root or has its
 * description removed.
 */
class UpdateFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('file')) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // Moving is a separate permission (a resource-owned file cannot leave its virtual
            // folder), enforced in the service where the file itself is known. `bail` so a
            // non-uuid (e.g. a synthetic `sys:` id) never reaches ScopedExists' uuid query.
            'folder_id' => ['sometimes', 'nullable', 'bail', 'uuid', new ScopedExists(Folder::class)],
            'labels' => ['sometimes', 'array'],
            'labels.*' => ['uuid', new ScopedExists(Label::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'File name cannot be empty.',
            'name.max' => 'File name cannot exceed 255 characters.',
        ];
    }
}
