<?php

namespace App\Modules\Approvals\Jobs;

use App\Modules\Approvals\Agents\ApprovalEvaluationAgent;
use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Approvals\Models\ApprovalProcess;
use App\Modules\Approvals\Services\ApprovalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAiApprovalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(
        public ApprovalProcess $process,
    ) {}

    public function handle(ApprovalService $approvalService): void
    {
        if (!$this->process->isPending()) {
            return;
        }

        $this->process->loadMissing(['stage', 'pipeline', 'approvable']);

        $entity = $this->process->approvable;
        $stage = $this->process->stage;

        if (!$entity || !$stage) {
            Log::warning("AI Approval: missing entity or stage for process {$this->process->id}");
            return;
        }

        try {
            $agent = new ApprovalEvaluationAgent($entity, $stage, $this->process);

            $response = $agent->prompt(
                prompt: 'Oceń element do zatwierdzenia.',
                provider: 'openai',
                model: 'gpt-4o',
            );

            $decision = ApprovalProcessStatus::from($response['decision']);
            $note = $response['note'] ?? null;

            $approvalService->decide($this->process, $decision, $note);
        } catch (\Throwable $e) {
            Log::error("AI Approval failed for process {$this->process->id}: {$e->getMessage()}");
            throw $e;
        }
    }
}
