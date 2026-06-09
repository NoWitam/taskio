<?php

namespace App\Modules\Auth\Http\Requests;

use App\Modules\Auth\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageGroups', $this->route('workspace')) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'user_ids' => ['sometimes', 'array'],
            'user_ids.*' => ['string', 'exists:users,id'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [Rule::in(PermissionRegistry::all())],
        ];
    }
}
