<?php

namespace App\Modules\Disk\Http\Requests;

use App\Modules\Disk\Models\Folder;
use App\Rules\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Restore a trashed file, optionally into a different folder.
 *
 * Whether a target is REQUIRED depends on the file (its original folder may be gone, or it may
 * have been an attachment with no folder of its own), so that rule lives in the service — the
 * dialog asks the matching preview endpoint first.
 */
class RestoreFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-model binding cannot resolve a soft-deleted row, so the id is resolved (and
        // authorized) in the controller against withTrashed().
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'target_folder_id' => ['sometimes', 'nullable', 'bail', 'uuid', new ScopedExists(Folder::class)],
        ];
    }
}
