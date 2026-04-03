<?php

namespace App\Modules\Forms\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;

#[MaxSteps(15)]
class FormReportAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Create a new agent instance.
     */
    public function __construct(
        private Tool $tool
    ) {}

    /**
     * Get the agent's instructions.
     */
    public function instructions(): string
    {
        return '';
    }

    /**
     * Get the agent's tools.
     *
     * @return iterable<Tool>
     */
    public function tools(): iterable
    {
        return [$this->tool];
    }
}
