<?php

namespace App\Modules\Generator\Http\Requests;

use App\Modules\Disk\Models\File;
use App\Modules\Generator\Models\GenerationSession;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates promoting a session's PRODUCED image onto the user's Disk (R2 sub-stage 2c "Zapisz na Dysk").
 * Authorization is DOUBLE: the caller must be able to `update` the bound session (its creator — the same gate
 * the generate action uses) AND `create` a Disk file (Fork 3's deliberate Generator → Disk edge). An optional
 * `name` + `folder_id`; the folder's existence/ownership is resolved through the tenant-scoped model inside
 * FileService::storeDiskContent (a foreign id 404s at findOrFail, never a cross-tenant write).
 */
class SaveGeneratedImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $session = $this->route('session');

        return $session instanceof GenerationSession
            && ($this->user()?->can('update', $session) ?? false)
            && ($this->user()?->can('create', File::class) ?? false);
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'folder_id' => ['nullable', 'string', 'uuid'],
        ];
    }
}
