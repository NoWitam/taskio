<?php

namespace Tests\Support;

use App\Modules\Workflows\Services\WorkflowStepFactory;
use App\Modules\Workflows\Steps\WorkflowStep;

/**
 * TEST-ONLY step factory that resolves ONE sentinel type string to a given fake step and delegates
 * every other type to the real factory. This is the seam that lets the engine tests exercise
 * suspend/resume without adding a WorkflowStepType case or a production step class — the engine
 * batch must name no feature.
 *
 * It returns the SAME instance every time on purpose, so a test can read the fake's call counters
 * after a run that spanned several jobs.
 */
class FakeStepFactory extends WorkflowStepFactory
{
    public function __construct(
        private WorkflowStep $fake,
        private string $fakeType = FakeSuspendableStep::TYPE,
    ) {}

    public function makeFromValue(string $type): WorkflowStep
    {
        return $type === $this->fakeType ? $this->fake : parent::makeFromValue($type);
    }
}
