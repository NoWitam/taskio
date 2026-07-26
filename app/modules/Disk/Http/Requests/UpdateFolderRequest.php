<?php

namespace App\Modules\Disk\Http\Requests;

use App\Modules\Disk\Models\Folder;
use App\Modules\Labels\Models\Label;
use App\Rules\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Folder metadata edits: name, description, icon and the governance labels ({id, mode}). Every
 * field is optional (`sometimes`) — an absent key leaves the value untouched, an explicit null
 * clears it (dropping a description or icon). Re-parenting stays on the dedicated move endpoint,
 * so an edit payload can never silently relocate a whole subtree.
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // A FE icon-NAME hint (rendered by the client's Icon set), not a server enum — bounded
            // as a short string; an unknown name simply renders as no icon.
            'icon' => ['sometimes', 'nullable', 'string', 'max:64'],
            'labels' => ['sometimes', 'array'],
            'labels.*.id' => ['required', 'uuid', new ScopedExists(Label::class)],
            'labels.*.mode' => ['required', Rule::in(Folder::LABEL_MODES)],
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
