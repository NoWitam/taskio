<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\DTOs\WorkflowDTO;
use App\Modules\Workflows\Enums\WorkflowStatus;
use App\Modules\Workflows\Models\Workflow;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkflowService
{
    public function __construct(
        private WorkflowScheduleService $schedule,
    ) {}

    public function index(Request $request): CursorPaginator
    {
        return Workflow::query()
            ->search(['name', 'description'], $request->get('search'))
            ->when(
                filled($request->get('status')),
                fn ($query) => $query->where('status', $request->get('status'))
            )
            // trashed=1 lists ONLY soft-deleted workflows (the Deleted tab); the default excludes them
            // via the SoftDeletes global scope. onlyTrashed() drops only that scope, so WorkspaceScope
            // still isolates the active workspace — a foreign workspace's trashed rows never leak.
            ->when(
                $request->boolean('trashed'),
                fn ($query) => $query->onlyTrashed()
            )
            ->orderBy('created_at', 'desc')
            ->cursorPaginate(8);
    }

    public function create(WorkflowDTO $dto): Workflow
    {
        return DB::transaction(function () use ($dto) {
            // A workflow is always created INACTIVE; status is toggled only via changeStatus().
            // Since it is inactive, arm() leaves next_due_at null — a schedule workflow is only
            // armed once it is activated.
            $workflow = new Workflow($this->attributes($dto) + ['status' => WorkflowStatus::INACTIVE]);
            $this->schedule->arm($workflow);
            $workflow->save();

            return $workflow;
        });
    }

    public function update(Workflow $workflow, WorkflowDTO $dto): Workflow
    {
        return DB::transaction(function () use ($workflow, $dto) {
            // update() never touches status — the status endpoint owns that transition.
            $workflow->fill($this->attributes($dto));

            // A cadence change on an ACTIVE schedule workflow must re-arm next_due_at from the
            // new cadence; arm() no-ops (nulls) for every other case.
            $this->schedule->arm($workflow);
            $workflow->save();

            return $workflow->refresh();
        });
    }

    /** Toggle a workflow's status (active|inactive). The only path that mutates status. */
    public function changeStatus(Workflow $workflow, WorkflowStatus $status): Workflow
    {
        return DB::transaction(function () use ($workflow, $status) {
            // Activating a schedule workflow arms next_due_at from its cadence; deactivating
            // nulls it so the due-sweep's WHERE never sees an inactive workflow. arm() reads
            // the CURRENT status, so set it first.
            $workflow->status = $status;
            $this->schedule->arm($workflow);
            $workflow->save();

            return $workflow->refresh();
        });
    }

    public function delete(Workflow $workflow): void
    {
        $workflow->delete();
    }

    public function restore(Workflow $workflow): Workflow
    {
        return DB::transaction(function () use ($workflow) {
            // Re-arm BEFORE restoring so a single write persists both deleted_at=null and a fresh
            // next_due_at. While soft-deleted the row is invisible to the sweep (SoftDeletes scope), so
            // an ACTIVE schedule workflow's next_due_at goes stale in the past; without re-arming, the
            // first sweep after restore would fire it immediately (and backlog-fire further past slots).
            // arm() recomputes the next fire for an ACTIVE schedule workflow from now() and nulls it for
            // every other case (mirrors the create/update/status write paths).
            $this->schedule->arm($workflow);
            $workflow->restore();

            return $workflow;
        });
    }

    /**
     * Map the DTO onto persisted columns (status is set by the caller, never here).
     * next_due_at is NOT set here — WorkflowScheduleService::arm() owns it, and the caller
     * arms the model (which reads trigger_type/status) after filling these attributes.
     */
    private function attributes(WorkflowDTO $dto): array
    {
        return [
            'name' => $dto->name,
            'description' => $dto->description,
            'icon' => $dto->icon,
            'trigger_type' => $dto->triggerType->value,
            'trigger_config' => $dto->triggerConfig,
            'conditions' => $dto->conditions,
            'steps' => $dto->steps,
        ];
    }
}
