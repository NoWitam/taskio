<?php

namespace App\Modules\Variables\Services;

use App\Modules\Variables\Contracts\FunctionReferenceLookup;
use App\Modules\Variables\DTOs\CustomFunctionDTO;
use App\Modules\Variables\Models\CustomFunction;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Business logic + persistence for CUSTOM FUNCTIONS. Single-row writes (no scheduling, no multi-step
 * orchestration), so there is no transaction: each mutation is one atomic save. Queries run through the
 * model so WorkspaceScope / the tenant connection isolate the active workspace in both db_modes — the
 * SAME isolation the ConstantService relies on.
 */
class CustomFunctionService
{
    /**
     * The cross-module WORKFLOW reference lookup for the delete guard — bound by the Workflows provider
     * (the side that owns the Workflow model). Nullable so the Variables module keeps its one-way boundary
     * and still functions if used without Workflows (the workflow guard then no-ops).
     */
    public function __construct(
        private ?FunctionReferenceLookup $workflowReferences = null,
    ) {}

    public function index(Request $request): CursorPaginator
    {
        return CustomFunction::query()
            ->with('creator')
            ->search(['name'], $request->get('search'))
            ->orderBy('name')
            ->cursorPaginate(20);
    }

    public function create(CustomFunctionDTO $dto): CustomFunction
    {
        $function = new CustomFunction($this->attributes($dto));
        $function->save();

        return $function;
    }

    public function update(CustomFunction $function, CustomFunctionDTO $dto): CustomFunction
    {
        $function->fill($this->attributes($dto));
        $function->save();

        return $function->refresh();
    }

    /**
     * Delete a function, BLOCKING with a 422 while it is still referenced — by ANOTHER function's body (the
     * 3a graph guard) OR by any WORKFLOW's step configs / conditions (Phase 3b, via FunctionReferenceLookup).
     * Consistent with the fail-closed delete-while-referenced doctrine: a live reference can never be left
     * dangling by a delete.
     */
    public function delete(CustomFunction $function): void
    {
        if ($this->isReferencedByAnotherFunction($function)) {
            throw ValidationException::withMessages([
                'function' => ['This function is used by another function and cannot be deleted.'],
            ]);
        }

        if ($this->isReferencedByWorkflow($function)) {
            throw ValidationException::withMessages([
                'function' => ['This function is used by a workflow and cannot be deleted.'],
            ]);
        }

        $function->delete();
    }

    /**
     * Whether any WORKFLOW references $function (a `fn:<uuid>` op in its step configs / conditions).
     * Delegated to the Workflows module through FunctionReferenceLookup so the Variables module keeps its
     * one-way boundary; no binding (Variables without Workflows) leaves the function-vs-function guard as
     * the sole check.
     */
    private function isReferencedByWorkflow(CustomFunction $function): bool
    {
        return $this->workflowReferences?->isReferencedByWorkflow((string) $function->getKey()) ?? false;
    }

    /**
     * EVERY function in the active workspace — the reference graph the write-validator reads (for nested
     * resolution + cycle detection). Includes the pending row on update (keyed by its uuid).
     *
     * @return Collection<int, CustomFunction>
     */
    public function allForValidation(): Collection
    {
        return CustomFunction::query()->get();
    }

    /**
     * Whether ANOTHER function's body references $function (the delete guard). Scans the workspace's
     * other functions through FunctionDefinitionValidator::referencedFunctionIds — the SAME precise
     * edge extraction the cycle graph uses — so a substring false-match (a uuid that is a prefix of
     * another) can never block a delete.
     */
    public function isReferencedByAnotherFunction(CustomFunction $function): bool
    {
        $id = (string) $function->getKey();

        return CustomFunction::query()
            ->whereKeyNot($id)
            ->get()
            ->contains(fn (CustomFunction $other): bool => in_array($id, FunctionDefinitionValidator::referencedFunctionIds($other->body), true));
    }

    /**
     * Map the DTO onto persisted columns. args + body ride the model's json cast unchanged.
     *
     * @return array<string, mixed>
     */
    private function attributes(CustomFunctionDTO $dto): array
    {
        return [
            'name' => $dto->name,
            'description' => $dto->description,
            'input_type' => $dto->inputType,
            'args' => $dto->args,
            'return_type' => $dto->returnType,
            'body' => $dto->body,
        ];
    }
}
