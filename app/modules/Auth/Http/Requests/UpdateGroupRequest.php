<?php

namespace App\Modules\Auth\Http\Requests;

use App\Models\User;
use App\Modules\Auth\Support\PermissionRegistry;
use App\Rules\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGroupRequest extends FormRequest
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
            'user_ids.*' => ['string', new ScopedExists(User::class)],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [Rule::in(PermissionRegistry::all())],
        ];
    }
}
