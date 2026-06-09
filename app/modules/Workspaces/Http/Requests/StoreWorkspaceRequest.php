<?php

namespace App\Modules\Workspaces\Http\Requests;

use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'db_mode' => ['sometimes', Rule::enum(WorkspaceDbMode::class)],
        ];
    }
}
