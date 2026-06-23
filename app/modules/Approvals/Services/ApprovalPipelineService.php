<?php

namespace App\Modules\Approvals\Services;

use App\Modules\Approvals\DTOs\ApprovalPipelineDTO;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Approvals\Models\ApprovalProcess;
use App\Modules\Approvals\Models\ApprovalStage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalPipelineService
{
    public function index(Request $request)
    {
        return ApprovalPipeline::query()
            ->with('stages.approver')
            // Existence flag consumed by ApprovalPipeline::hasActiveProcesses()
            // (can_be_edited / can_be_deleted) — avoids two exists() per row.
            ->withExists('pendingProcesses')
            ->search('name', $request->get('search'))
            ->orderBy('created_at', 'desc')
            ->cursorPaginate(8);
    }

    public function create(ApprovalPipelineDTO $dto): ApprovalPipeline
    {
        return DB::transaction(function () use ($dto) {
            $pipeline = ApprovalPipeline::create([
                'name' => $dto->name,
                'icon' => $dto->icon,
                'description' => $dto->description,
            ]);

            $this->syncStages($pipeline, $dto->stages);

            return $pipeline->load('stages.approver');
        });
    }

    public function update(ApprovalPipeline $pipeline, ApprovalPipelineDTO $dto): ApprovalPipeline
    {
        if (!$pipeline->canBeEdited()) {
            throw ValidationException::withMessages([
                'pipeline' => [__('approvals.validation.pipeline_has_active_processes')],
            ]);
        }

        return DB::transaction(function () use ($pipeline, $dto) {
            $pipeline->update([
                'name' => $dto->name,
                'icon' => $dto->icon,
                'description' => $dto->description,
            ]);

            $this->syncStages($pipeline, $dto->stages);

            return $pipeline->load('stages.approver');
        });
    }

    public function delete(ApprovalPipeline $pipeline): void
    {
        if (!$pipeline->canBeDeleted()) {
            throw ValidationException::withMessages([
                'pipeline' => [__('approvals.validation.pipeline_has_active_processes')],
            ]);
        }

        $pipeline->delete();
    }

    private function syncStages(ApprovalPipeline $pipeline, array $stages): void
    {
        $existingStageIds = $pipeline->stages()->pluck('id')->all();

        // Nullify FK references from historical approval processes before deleting stages
        if ($existingStageIds) {
            ApprovalProcess::query()
                ->whereIn('approval_stage_id', $existingStageIds)
                ->update(['approval_stage_id' => null]);
        }

        $pipeline->stages()->delete();

        foreach ($stages as $index => $stageData) {
            ApprovalStage::create([
                'approval_pipeline_id' => $pipeline->id,
                'name' => $stageData['name'],
                'icon' => $stageData['icon'] ?? null,
                'description' => $stageData['description'] ?? null,
                'approver_type' => $stageData['approver_type'],
                'approver_id' => $stageData['approver_type'] === 'ai' ? null : ($stageData['approver_id'] ?? null),
                'order' => $index,
            ]);
        }
    }
}
