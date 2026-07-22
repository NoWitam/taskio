<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\DTOs\WorkflowGlobalDTO;
use App\Modules\Workflows\Models\WorkflowGlobal;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

/**
 * Business logic + persistence for workflow GLOBALS. Single-row writes (no scheduling, no
 * multi-step orchestration), so — unlike WorkflowService — there is no transaction: each mutation
 * is one atomic save. Queries run through the model so WorkspaceScope / the tenant connection
 * isolate the active workspace in both db_modes.
 */
class WorkflowGlobalService
{
    public function index(Request $request): CursorPaginator
    {
        return WorkflowGlobal::query()
            ->with('creator')
            ->search(['name', 'key'], $request->get('search'))
            ->orderBy('name')
            ->cursorPaginate(20);
    }

    public function create(WorkflowGlobalDTO $dto): WorkflowGlobal
    {
        $global = new WorkflowGlobal($this->attributes($dto));
        $global->save();

        return $global;
    }

    public function update(WorkflowGlobal $global, WorkflowGlobalDTO $dto): WorkflowGlobal
    {
        $global->fill($this->attributes($dto));
        $global->save();

        return $global->refresh();
    }

    public function delete(WorkflowGlobal $global): void
    {
        $global->delete();
    }

    /**
     * Map the DTO onto persisted columns. The value rides the model's json cast unchanged (a
     * scalar, list, object, or null).
     *
     * @return array<string, mixed>
     */
    private function attributes(WorkflowGlobalDTO $dto): array
    {
        return [
            'name' => $dto->name,
            'key' => $dto->key,
            'descriptor' => $dto->descriptor,
            'value' => $dto->value,
        ];
    }
}
