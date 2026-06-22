<?php

namespace App\Modules\FilterTabs\Http\Requests;

use App\Enums\IconEnum;
use App\Models\Scopes\WorkspaceScope;
use App\Modules\FilterTabs\Models\FilterTab;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFilterTabRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FilterTab::class);
    }

    public function rules(): array
    {
        $workspaceId = app(TenantContext::class)->id();

        return [
            'context' => ['required', 'string', 'max:64'],
            'name' => [
                'required',
                'string',
                'max:60',
                Rule::unique('filter_tabs', 'name')
                    ->where('user_id', $this->user()->id)
                    ->where(WorkspaceScope::COLUMN, $workspaceId)
                    ->where('context', $this->string('context')->toString()),
            ],
            'icon' => ['nullable', Rule::enum(IconEnum::class)],
            'filters' => ['required', 'array', new FiltersPayloadRule],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'filter_tabs.errors.name_taken',
        ];
    }
}
