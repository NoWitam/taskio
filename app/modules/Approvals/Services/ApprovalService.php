<?php

namespace App\Modules\Approvals\Services;

use App\Models\User;
use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Approvals\Enums\ApproverType;
use App\Modules\Approvals\Interfaces\Approvable;
use App\Modules\Approvals\Jobs\ProcessAiApprovalJob;
use App\Modules\Approvals\Models\ApprovalProcess;
use App\Modules\Approvals\Models\ApprovalStage;
use App\Modules\Changelog\Enums\ChangelogEvent;
use App\Modules\Changelog\Managers\ChangelogManager;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApprovalService
{
    public function __construct(
        private ChangelogManager $changelogManager,
    ) {}

    public function startProcess(Model&Approvable $entity, ?User $user = null): ApprovalProcess
    {
        $pipeline = $entity->approvalPipeline;

        if (!$pipeline) {
            throw ValidationException::withMessages([
                'approval' => [__('approvals.validation.no_pipeline_assigned')],
            ]);
        }

        $firstStage = $pipeline->stages()->orderBy('order')->first();

        if (!$firstStage) {
            throw ValidationException::withMessages([
                'approval' => [__('approvals.validation.pipeline_has_no_stages')],
            ]);
        }

        return DB::transaction(function () use ($entity, $pipeline, $firstStage, $user) {
            $runId = Str::uuid7()->toString();

            $process = ApprovalProcess::create([
                'run_id' => $runId,
                'approval_pipeline_id' => $pipeline->id,
                'approval_stage_id' => $firstStage->id,
                'approvable_type' => $entity->getMorphClass(),
                'approvable_id' => $entity->getKey(),
                'approver_type' => $firstStage->approver_type,
                'approver_id' => $firstStage->approver_id,
                'status' => ApprovalProcessStatus::Pending,
                'context' => $entity->getApprovalContext(),
                'creator_id' => $user?->id ?? auth()->id(),
            ]);

            $this->changelogManager->handleCustomEvent(
                $entity,
                ChangelogEvent::APPROVAL_STARTED,
                [
                    'pipeline' => $pipeline->name,
                    'stage' => $firstStage->name,
                ]
            );

            if ($firstStage->isAiApprover()) {
                ProcessAiApprovalJob::dispatch($process);
            }

            return $process;
        });
    }

    public function decide(ApprovalProcess $process, ApprovalProcessStatus $decision, ?string $note = null): ApprovalProcess
    {
        if (!$process->isPending()) {
            throw ValidationException::withMessages([
                'approval' => [__('approvals.validation.already_decided')],
            ]);
        }

        if ($decision === ApprovalProcessStatus::Rejected && empty($note)) {
            throw ValidationException::withMessages([
                'note' => [__('approvals.validation.note_required_on_rejection')],
            ]);
        }

        if ($decision === ApprovalProcessStatus::Pending) {
            throw ValidationException::withMessages([
                'decision' => [__('approvals.validation.invalid_decision')],
            ]);
        }

        return DB::transaction(function () use ($process, $decision, $note) {
            $process->update([
                'status' => $decision,
                'note' => $note,
                'decided_at' => now(),
            ]);

            $entity = $process->approvable;

            if ($decision === ApprovalProcessStatus::Approved) {
                $this->handleApproved($process, $entity);
            } else {
                $this->handleRejected($process, $entity);
            }

            return $process;
        });
    }

    private function handleApproved(ApprovalProcess $process, Model&Approvable $entity): ApprovalProcess
    {
        $pipeline = $process->pipeline;
        $currentStage = $process->stage;

        $nextStage = $pipeline->stages()
            ->where('order', '>', $currentStage->order)
            ->orderBy('order')
            ->first();

        if ($nextStage) {
            return $this->advance($process, $entity, $nextStage);
        }

        return $this->complete($process, $entity);
    }

    private function advance(ApprovalProcess $process, Model&Approvable $entity, ApprovalStage $nextStage): ApprovalProcess
    {
        $newProcess = ApprovalProcess::create([
            'run_id' => $process->run_id,
            'approval_pipeline_id' => $process->approval_pipeline_id,
            'approval_stage_id' => $nextStage->id,
            'approvable_type' => $entity->getMorphClass(),
            'approvable_id' => $entity->getKey(),
            'approver_type' => $nextStage->approver_type,
            'approver_id' => $nextStage->approver_id,
            'status' => ApprovalProcessStatus::Pending,
            'context' => $process->context,
            'creator_id' => $process->creator_id,
        ]);

        $this->changelogManager->handleCustomEvent(
            $entity,
            ChangelogEvent::APPROVAL_STAGE_APPROVED,
            [
                'stage' => $process->stage->name,
                'next_stage' => $nextStage->name,
            ]
        );

        if ($nextStage->isAiApprover()) {
            ProcessAiApprovalJob::dispatch($newProcess);
        }

        return $newProcess;
    }

    private function complete(ApprovalProcess $process, Model&Approvable $entity): ApprovalProcess
    {
        $entity->onApprovalCompleted($process);

        $this->changelogManager->handleCustomEvent(
            $entity,
            ChangelogEvent::APPROVAL_COMPLETED,
            [
                'pipeline' => $process->pipeline->name,
            ]
        );

        return $process;
    }

    private function handleRejected(ApprovalProcess $process, Model&Approvable $entity): ApprovalProcess
    {
        $entity->onApprovalRejected($process);

        $this->changelogManager->handleCustomEvent(
            $entity,
            ChangelogEvent::APPROVAL_REJECTED,
            [
                'stage' => $process->stage->name,
                'note' => $process->note,
            ]
        );

        return $process;
    }

    public function getQueueForUser(string $userId)
    {
        $paginator = ApprovalProcess::query()
            ->with(['pipeline', 'stage', 'approvable'])
            ->where('approver_type', ApproverType::User)
            ->where('approver_id', $userId)
            ->where('status', ApprovalProcessStatus::Pending)
            ->orderBy('created_at', 'desc')
            ->cursorPaginate(8);

        $this->eagerLoadApprovableQueueRelations($paginator->getCollection());

        return $paginator;
    }

    /**
     * Batch-load each approvable's queue relations grouped by concrete type, so
     * ApprovalQueueItemResource → toApprovalQueueItem() reads them from memory
     * instead of lazy-loading per row (N+1). Stays decoupled from concrete
     * approvables: the relation list comes from the Approvable interface.
     */
    private function eagerLoadApprovableQueueRelations(Collection $processes): void
    {
        $processes
            ->map(fn (ApprovalProcess $process) => $process->approvable)
            ->filter(fn ($approvable) => $approvable instanceof Approvable)
            ->groupBy(fn (Approvable $approvable) => $approvable::class)
            ->each(function (Collection $group): void {
                /** @var Approvable $sample */
                $sample = $group->first();
                $relations = $sample->approvalQueueRelations();

                if ($relations !== []) {
                    EloquentCollection::make($group->all())->load($relations);
                }
            });
    }

    public function getQueueCountForUser(string $userId): int
    {
        return ApprovalProcess::query()
            ->where('approver_type', ApproverType::User)
            ->where('approver_id', $userId)
            ->where('status', ApprovalProcessStatus::Pending)
            ->count();
    }
}
