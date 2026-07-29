<?php

namespace Tests\Support;

use App\Modules\Variables\Enums\VariableType;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Exceptions\StepSuspended;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Steps\SuspendableWorkflowStep;
use App\Modules\Workflows\Support\RealQueueConnection;
use RuntimeException;

/**
 * TEST-ONLY suspendable step. It exists so the GENERIC suspend/resume engine can be proven WITHOUT
 * any feature riding on it — the whole point of shipping the engine as its own gated batch. It is
 * never registered in WorkflowStepFactory: {@see FakeStepFactory} routes ONE type to it for the
 * duration of a test (see TYPE below), so production keeps zero suspendable steps.
 *
 * Behaviour is scripted per test:
 *   - run() throws StepSuspended (recording the queue connection it would dispatch its external work
 *     onto), unless $suspendOnRun is false — then it behaves like a plain step;
 *   - resume() returns a normal output array, throws when $failOnResume is set, or SUSPENDS AGAIN on
 *     a second leg when $suspendOnResumeKey is set (explicitly allowed by the SuspendableWorkflowStep
 *     contract — the multi-leg wait).
 * Every call is counted, so a test can prove a double resume did NOT execute the step twice.
 */
class FakeSuspendableStep implements SuspendableWorkflowStep
{
    /**
     * The type string a test puts in the workflow definition for this fake.
     *
     * It must be a REAL WorkflowStepType value because `workflow_run_steps.type` is an enum-cast
     * column — an invented string could not be recorded. So the fake BORROWS an existing type and
     * {@see FakeStepFactory} routes that one type to this class for the duration of a test; the
     * ordinary steps in the same workflow use the other type and still hit their real
     * implementations. Nothing in production maps a type to a suspendable step yet, which is exactly
     * the point of this batch.
     */
    public const TYPE = 'create_form_report';

    /** The wait KIND this fake parks on (the WaitResolver registry key). */
    public const KIND = 'fake_wait';

    public int $runCalls = 0;

    public int $resumeCalls = 0;

    /** The queue connection visible through the escape hatch when run() was called. */
    public ?string $seenRealQueueConnection = null;

    /** The resolved config each call received (proves the resumed pass re-resolves references). */
    public ?array $lastRunConfig = null;

    public ?array $lastResumeConfig = null;

    public ?array $lastResumeContext = null;

    public ?array $lastResumeWait = null;

    /** Every correlation key this step parked on, in order — the "legs" of a multi-leg wait. */
    public array $legs = [];

    /**
     * When set, the NEXT resume() parks the run again on this correlation key (a second leg) and then
     * clears itself, so the resume after that completes normally.
     */
    public ?string $suspendOnResumeKey = null;

    /** Called at the very start of run(), before anything else — the seam for in-process nesting. */
    public ?\Closure $beforeRun = null;

    public function __construct(
        public bool $suspendOnRun = true,
        public bool $failOnResume = false,
        public string $correlationKey = 'fake_wait:abc-123',
    ) {}

    /** Never consulted by the runner — it dispatches on the definition's raw type string. */
    public function type(): WorkflowStepType
    {
        return WorkflowStepType::CREATE_FORM_REPORT;
    }

    public static function outputDescriptors(): array
    {
        return [
            ['name' => 'settled', 'type' => VariableType::TEXT],
        ];
    }

    public function run(array $config, WorkflowRun $run, array $context): array
    {
        $this->runCalls++;
        $this->lastRunConfig = $config;

        if ($this->beforeRun !== null) {
            ($this->beforeRun)();
        }

        // A real suspending step reads this to put its external work on the REAL queue instead of
        // the run job's forced `sync` driver.
        $this->seenRealQueueConnection = app(RealQueueConnection::class)->current();

        if (!$this->suspendOnRun) {
            return ['settled' => 'immediate'];
        }

        $this->legs[] = $this->correlationKey;

        throw new StepSuspended(self::KIND, $this->correlationKey, ['echo' => $config['echo'] ?? null]);
    }

    public function resume(array $config, WorkflowRun $run, array $context, array $wait): array
    {
        $this->resumeCalls++;
        $this->seenRealQueueConnection = app(RealQueueConnection::class)->current();
        $this->lastResumeConfig = $config;
        $this->lastResumeContext = $context;
        $this->lastResumeWait = $wait;

        if ($this->failOnResume) {
            throw new RuntimeException('Settled work was unusable.');
        }

        // A multi-leg wait: the settled work spawned MORE external work, so the step parks the run
        // again on a fresh correlation key. Cleared so the next resume finishes.
        if ($this->suspendOnResumeKey !== null) {
            $nextLeg = $this->suspendOnResumeKey;
            $this->suspendOnResumeKey = null;
            $this->legs[] = $nextLeg;

            throw new StepSuspended(self::KIND, $nextLeg, ['echo' => $config['echo'] ?? null]);
        }

        return [
            'settled' => 'yes',
            'echo' => $wait['payload']['echo'] ?? null,
        ];
    }
}
