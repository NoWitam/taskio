<?php

namespace Tests\Support;

use App\Modules\Workflows\Enums\WorkflowAiPersona;
use App\Modules\Workflows\Services\WorkflowAiTextService;

/**
 * Deterministic test double for WorkflowAiTextService (SB2), on the pattern of
 * ScriptedBotExecutionAgent. It overrides generate() to RECORD each call (the resolved prompt +
 * the persona) and return a scripted string — so a WorkflowVariableResolver test can assert that
 * an ai-text directive's prompt reached the AI FULLY RESOLVED (nested variables substituted) and
 * with the right persona, WITHOUT any real provider. It deliberately does NOT apply the real
 * service's per-run budget/truncation (those are covered against the REAL service + a faked agent).
 */
class ScriptedWorkflowAiTextService extends WorkflowAiTextService
{
    /** @var array<int, array{prompt: string, persona: WorkflowAiPersona}> */
    public array $recorded = [];

    /** @param array<int, string>|null $responses ordered canned outputs; null → always $default */
    public function __construct(
        private ?array $responses = null,
        private string $default = '',
    ) {}

    public function generate(string $prompt, WorkflowAiPersona $persona): string
    {
        $this->recorded[] = ['prompt' => $prompt, 'persona' => $persona];

        if ($this->responses !== null) {
            return array_shift($this->responses) ?? $this->default;
        }

        return $this->default;
    }
}
