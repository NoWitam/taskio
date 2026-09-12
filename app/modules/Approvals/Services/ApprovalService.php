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
use App\Modules\Changelog\Interfaces\HasChangelog;
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

        // ONE LIVE PROCESS PER SUBJECT (B6 re-review, K1). Two pending processes for one entity are two
        // approvers deciding about the same thing without knowing of each other — approve one and the
        // subject acts on it while `isInApproval()` still says a review is live; reject the other and
        // the refusal lands on something already acted upon. Nothing legitimate starts a second review
        // while one is open (a resubmission follows a DECIDED process), so the second start is refused,
        // generically, for every approvable alike. Checked here rather than by a schema constraint: a
        // razor-thin window remains between this read and the insert below when two requests race on
        // the same millisecond — the partial unique index that would close it (`(approvable_type,
        // approvable_id) WHERE status = 'pending'`) touches this shared module's schema for every
        // client and is the owner's call, recorded as such.
        $alreadyPending = ApprovalProcess::query()
            ->where('approvable_type', $entity->getMorphClass())
            ->where('approvable_id', $entity->getKey())
            ->where('status', ApprovalProcessStatus::Pending)
            ->exists();

        if ($alreadyPending) {
            throw ValidationException::withMessages([
                'approval' => [__('approvals.validation.process_already_pending')],
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

            $this->recordChangelog($entity, ChangelogEvent::APPROVAL_STARTED, [
                'pipeline' => $pipeline->name,
                'stage' => $firstStage->name,
            ]);

            if ($firstStage->isAutomatedApprover()) {
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

        // ─────────────────────────────────────────────────────────────────────────────────────────
        // THE SUBJECT MAY BE GONE, AND UNTIL R4 B6 THAT WAS A 500 SEVERAL FRAMES DOWN
        // ─────────────────────────────────────────────────────────────────────────────────────────
        // Every approvable in this product is soft-deletable, and deleting one is a legitimate act that
        // is deliberately NOT gated on a live review — withdrawing something you no longer want is the
        // author's right, and taking it away would leave the only exit through the approval itself. So
        // an approver can be looking at a card for a row that has since been trashed.
        //
        // `$process->approvable` then resolves to null, and every path below is typed `Model&Approvable`.
        // The result was a TypeError — a 500 on the approver's screen, blaming them for somebody else's
        // deletion, with no sentence anywhere saying what had happened.
        //
        // IT IS CHECKED GENERICALLY AND NAMES NO CONCRETE APPROVABLE. A `instanceof Publication` here
        // would put one module's knowledge inside the shared one; `WorkflowsPublishingBoundaryTest`
        // refuses that module-wide, and the guard does not need it — "the thing being decided about no
        // longer exists" is true of a task in exactly the same way.
        //
        // TODO (named debt, not solved here): the process itself is left PENDING forever. It stays in
        // its approver's queue, rendering with a null entity, and nothing cancels it — which is equally
        // true of a deleted Task today. Cancelling processes when their subject is deleted is a change
        // to the Approvals module's contract with every approvable it has, and belongs to whoever owns
        // that contract rather than to a publishing batch.
        if ($process->approvable === null) {
            throw ValidationException::withMessages([
                'approval' => [__('approvals.validation.approvable_missing')],
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

        $this->recordChangelog($entity, ChangelogEvent::APPROVAL_STAGE_APPROVED, [
            'stage' => $process->stage->name,
            'next_stage' => $nextStage->name,
        ]);

        if ($nextStage->isAutomatedApprover()) {
            ProcessAiApprovalJob::dispatch($newProcess);
        }

        return $newProcess;
    }

    private function complete(ApprovalProcess $process, Model&Approvable $entity): ApprovalProcess
    {
        $entity->onApprovalCompleted($process);

        $this->recordChangelog($entity, ChangelogEvent::APPROVAL_COMPLETED, [
            'pipeline' => $process->pipeline->name,
        ]);

        return $process;
    }

    private function handleRejected(ApprovalProcess $process, Model&Approvable $entity): ApprovalProcess
    {
        $entity->onApprovalRejected($process);

        $this->recordChangelog($entity, ChangelogEvent::APPROVAL_REJECTED, [
            'stage' => $process->stage->name,
            'note' => $process->note,
        ]);

        return $process;
    }

    /**
     * Write an approval milestone to the entity's changelog — WHEN THE ENTITY HAS ONE.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * WHY THIS GUARD EXISTS (R4 B6)
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * {@see \App\Modules\Approvals\Interfaces\Approvable} asks for eight methods and says nothing about
     * a changelog. For four years that was true of the code as well as of the interface — because there
     * was exactly ONE implementation, `Task`, which happens to implement `HasChangelog` too, and every
     * call site here passed it straight into
     * {@see \App\Modules\Changelog\Managers\ChangelogManager::handleCustomEvent()}, whose parameter is
     * typed `Model&HasChangelog`.
     *
     * So the real contract was "Approvable AND HasChangelog", enforced by a TypeError several frames
     * deep, at the moment a review starts. The second implementation of `Approvable` in the product (a
     * publication — deliberately not named by namespace: this engine stays client-blind, and
     * `WorkflowsPublishingBoundaryTest` byte-scans this module for its clients' namespaces) found it
     * immediately.
     *
     * IT IS THE CHANGELOG THAT IS OPTIONAL HERE, not the approval. A changelog is an audit surface a
     * module opts into; requiring one in order to be reviewable would mean every future approvable had
     * to grow a changelog implementation it may have no screen for — and would mean the interface was
     * quietly lying about what it asks for. An approvable WITH a changelog keeps every entry it had,
     * byte for byte; one without simply records none.
     *
     * @param  array<string, mixed>  $details
     */
    private function recordChangelog(Model&Approvable $entity, ChangelogEvent $event, array $details): void
    {
        if (!$entity instanceof HasChangelog) {
            return;
        }

        $this->changelogManager->handleCustomEvent($entity, $event, $details);
    }

    public function getQueueForUser(string $userId)
    {
        $paginator = ApprovalProcess::query()
            ->with(['pipeline', 'stage', 'approvable', 'approver', 'approverBot'])
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
