<?php

namespace Tests\Support;

use App\Modules\Workflows\Contracts\WaitResolver;
use App\Modules\Workflows\Enums\WaitStatus;
use App\Modules\Workflows\Models\WorkflowRun;
use RuntimeException;

/**
 * TEST-ONLY WaitResolver: answers a scripted status for one kind. It stands in for the resolver a
 * feature module will register from its own provider, and proves the waiting-run sweep is driven
 * ENTIRELY through the registry — the engine itself knows no kinds.
 */
class FakeWaitResolver implements WaitResolver
{
    public int $calls = 0;

    public function __construct(
        private WaitStatus $status = WaitStatus::PENDING,
        private string $kind = FakeSuspendableStep::KIND,
        private bool $throws = false,
    ) {}

    public function kind(): string
    {
        return $this->kind;
    }

    public function status(WorkflowRun $run, array $waitingOn): WaitStatus
    {
        $this->calls++;

        if ($this->throws) {
            throw new RuntimeException('resolver exploded');
        }

        return $this->status;
    }
}
