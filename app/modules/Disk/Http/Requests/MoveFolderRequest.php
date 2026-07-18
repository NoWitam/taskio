<?php

namespace App\Modules\Disk\Http\Requests;

use App\Modules\Disk\Models\Folder;
use App\Rules\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

class MoveFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('move', $this->route('folder')) ?? false;
    }

    public function rules(): array
    {
        return [
            // null = move to the workspace root. The cycle/depth/name guards live in the
            // service, where the whole subtree is visible. `bail` so a non-uuid never reaches
            // ScopedExists (its uuid-column query would 500).
            'target_folder_id' => ['nullable', 'bail', 'uuid', new ScopedExists(Folder::class)],
        ];
    }
}
