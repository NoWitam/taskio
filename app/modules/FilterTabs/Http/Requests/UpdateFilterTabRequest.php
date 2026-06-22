<?php

namespace App\Modules\FilterTabs\Http\Requests;

use App\Enums\IconEnum;
use App\Models\Scopes\WorkspaceScope;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFilterTabRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('filter_tab'));
    }

    public function rules(): array
    {
        $tab = $this->route('filter_tab');
        $workspaceId = app(TenantContext::class)->id();

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:60',
                Rule::unique('filter_tabs', 'name')
                    ->ignore($tab->id)
                    ->where('user_id', $this->user()->id)
                    ->where(WorkspaceScope::COLUMN, $workspaceId)
                    ->where('context', $tab->context),
            ],
            'icon' => ['sometimes', 'nullable', Rule::enum(IconEnum::class)],
            'filters' => ['sometimes', 'required', 'array', new FiltersPayloadRule],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'filter_tabs.errors.name_taken',
        ];
    }
}
