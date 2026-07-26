<?php

namespace App\Modules\Variables\Http\Requests;

use App\Modules\Variables\Models\CustomFunction;
use App\Modules\Variables\Services\CustomFunctionService;
use App\Modules\Variables\Services\FunctionDefinitionValidator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a CUSTOM FUNCTION on create. A function is a user-authored, workspace-scoped variable
 * transform: an identity (`name`, optional `description`) plus a SIGNATURE + BODY validated by the
 * shared FunctionDefinitionValidator (the ONE place a function definition is checked, so the accepted
 * shape can never drift from what the engine understands):
 *   - input_type / return_type ∈ VariableType.
 *   - args: typed named args ({name, description?, type}) — unique, non-reserved, safe identifiers.
 *   - body: a pipeline over {input + args} that terminates in the return type, may reference OTHER
 *     functions (nesting), and must keep the reference graph ACYCLIC.
 *
 * The workspace's functions are loaded THROUGH the model (WorkspaceScope in shared mode, the tenant
 * connection in own mode), so nested-reference resolution + cycle detection are correct in BOTH db_modes
 * without a workspace_id branch.
 */
class StoreCustomFunctionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CustomFunction::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            // The types + args + body are deep-validated in withValidator() by the shared definition
            // validator; these base rules only pin the coarse shape so the deep pass never chokes.
            'input_type' => ['required', 'string'],
            'return_type' => ['required', 'string'],
            'args' => ['present', 'array'],
            'body' => ['present', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            app(FunctionDefinitionValidator::class)->validate(
                $validator,
                $this->input('input_type'),
                $this->input('args'),
                $this->input('return_type'),
                $this->input('body'),
                app(CustomFunctionService::class)->allForValidation(),
                $this->currentFunctionId(),
            );
        });
    }

    /** The id of the function being updated (so the cycle graph substitutes its new body); null on create. */
    protected function currentFunctionId(): ?string
    {
        return null;
    }
}
