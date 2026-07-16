<?php

namespace App\Modules\Approvals\Http\Requests;

use App\Models\User;
use App\Modules\Approvals\Enums\ApproverType;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Bot\Models\Bot;
use App\Rules\ScopedExists;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApprovalPipelineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $pipeline = $this->route('pipeline');

        if ($pipeline) {
            return $this->user()->can('update', $pipeline);
        }

        return $this->user()->can('create', ApprovalPipeline::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:2500'],
            'stages' => ['required', 'array', 'min:1'],
            'stages.*.name' => ['required', 'string', 'max:255'],
            'stages.*.icon' => ['nullable', 'string', 'max:50'],
            'stages.*.description' => ['nullable', 'string', 'max:2500'],
            'stages.*.approver_type' => ['required', Rule::enum(ApproverType::class)],
            // approver_id is required for a user OR a named bot approver, null for ai.
            // It is validated against the matching model (User or Bot) through the
            // workspace-scoped existence rule, so a cross-workspace reference is rejected.
            'stages.*.approver_id' => [
                'nullable',
                'required_if:stages.*.approver_type,user,bot',
                'uuid',
                $this->scopedApproverRule(),
            ],
        ];
    }

    /**
     * Validate approver_id against the model named by the SAME stage's approver_type
     * (User for `user`, Bot for `bot`). Skipped for ai (no approver_id) and when the
     * value/type is absent — the enum / required_if rules handle those.
     */
    private function scopedApproverRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            // $attribute is e.g. "stages.2.approver_id" — resolve this stage's type.
            $index = explode('.', $attribute)[1] ?? null;
            $type = $index !== null
                ? ($this->input("stages.{$index}.approver_type"))
                : null;

            if ($value === null || $value === '') {
                return;
            }

            $modelClass = match ($type) {
                'user' => User::class,
                'bot' => Bot::class,
                default => null, // ai or invalid type — nothing to scope-check here.
            };

            if ($modelClass === null) {
                return;
            }

            (new ScopedExists($modelClass))->validate($attribute, $value, $fail);
        };
    }
}
