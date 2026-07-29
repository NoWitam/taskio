<?php

namespace Tests\Support;

use App\Modules\Variables\Enums\AiPersona;
use App\Modules\Workflows\Services\WorkflowAiTextService;

/**
 * Deterministic test double for WorkflowAiTextService (SB2), on the pattern of
 * ScriptedBotExecutionAgent. It overrides generate() to RECORD each call (the resolved prompt +
 * the persona) and return a scripted string — so a VariableResolver test can assert that
 * an ai-text directive's prompt reached the AI FULLY RESOLVED (nested variables substituted) and
 * with the right persona, WITHOUT any real provider. It deliberately does NOT apply the real
 * service's per-run budget/truncation (those are covered against the REAL service + a faked agent).
 *
 * It extends WorkflowAiTextService but fully overrides generate() and defines its own constructor, so
 * it never touches the real decorator's injected shared generator. The overridden generate() matches
 * the AiTextGenerator contract's `?string $personaId` and maps it to an AiPersona (fromNullable) for
 * recording — exactly as the real generator does — so persona assertions read the resolved persona
 * value, not the raw id.
 */
class ScriptedWorkflowAiTextService extends WorkflowAiTextService
{
    /** @var array<int, array{prompt: string, persona: AiPersona}> */
    public array $recorded = [];

    /** @param array<int, string>|null $responses ordered canned outputs; null → always $default */
    public function __construct(
        private ?array $responses = null,
        private string $default = '',
    ) {}

    public function generate(string $prompt, ?string $personaId): string
    {
        $this->recorded[] = ['prompt' => $prompt, 'persona' => AiPersona::fromNullable($personaId)];

        if ($this->responses !== null) {
            return array_shift($this->responses) ?? $this->default;
        }

        return $this->default;
    }
}
