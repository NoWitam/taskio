<?php

namespace App\Modules\Tasks\Http\Resources;

use App\Http\Resources\CreatorResource;
use App\Models\User;
use App\Modules\Approvals\Http\Resources\ApprovalPipelineResource;
use App\Modules\Approvals\Http\Resources\ApprovalProcessResource;
use App\Modules\Disk\Http\Resources\FileResource;
use App\Modules\Forms\Http\Resources\FormResource;
use App\Modules\Forms\Http\Resources\FormSubmissionResource;
use App\Modules\Labels\Http\Resources\LabelResource;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Users\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'deadline' => $this->deadline?->format('Y-m-d'),
            'deadline_overdue' => $this->deadline?->diffInDays(now(), absolute: false),
            'is_overdue' => $this->isDeadlineOverdue(),
            'is_at_risk' => $this->isDeadlineAtRisk(),
            'attachments' => FileResource::collection($this->files),
            'creator' => CreatorResource::make($this->whenLoaded('creator')),
            // Back-compat: user-only assignee (null when assigned to a bot).
            'assigned' => UserResource::make($this->assigned),
            // New polymorphic assignee (User|Bot) — see TaskAssigneeResource.
            'assignee' => TaskAssigneeResource::present($this->assignee),
            'labels' => LabelResource::collection($this->labels),
            'form_id' => $this->form_id,
            'form' => FormResource::make($this->whenLoaded('form')),
            'form_submission' => FormSubmissionResource::make($this->whenLoaded('formSubmission')),
            'approval_pipeline_id' => $this->approval_pipeline_id,
            'approval_pipeline' => $this->whenLoaded('approvalPipeline', fn () => ApprovalPipelineResource::make($this->approvalPipeline)),
            'is_in_approval' => $this->isInApproval(),
            // B4: interactive bot run state (additive).
            'bot_waiting' => $this->isBotWaiting(),
            'bot_runs_used' => (int) $this->bot_runs_used,
            'bot_runs_cap' => (int) config('ai.max_runs_per_task', 5),
            'pending_approval_process' => $this->whenLoaded('pendingApprovalProcess', fn () => ApprovalProcessResource::make($this->pendingApprovalProcess)),
            'approval_run_id' => $this->approval_pipeline_id ? $this->latestApprovalRunId() : null,
            'available_status_transitions' => $this->availableStatusTransitions($user),
            'can_update' => $user?->can('update', $this->resource) ?? false,
            'can_delete' => $user?->can('delete', $this->resource) ?? false,
            'can_restore' => $user?->can('restore', $this->resource) ?? false,
            'can_force_delete' => $user?->can('forceDelete', $this->resource) ?? false,
        ];
    }

    /**
     * Status transitions the given user may perform on this task right now.
     *
     * Authority lives in TaskStatus::canSetOn (single source of truth). TRASH is
     * intentionally excluded — it belongs to the delete endpoint, not status change.
     *
     * @return array<int, string>
     */
    private function availableStatusTransitions(?User $user): array
    {
        $candidates = [
            TaskStatus::TO_DO,
            TaskStatus::IN_PROGRESS,
            TaskStatus::IN_TEST,
            TaskStatus::DONE,
            TaskStatus::ARCHIVE,
        ];

        return collect($candidates)
            ->reject(fn (TaskStatus $candidate) => $candidate === $this->status)
            ->filter(fn (TaskStatus $candidate) => TaskStatus::canSetOn($this->resource, $candidate, $user))
            ->map(fn (TaskStatus $candidate) => $candidate->value)
            ->values()
            ->all();
    }
}
