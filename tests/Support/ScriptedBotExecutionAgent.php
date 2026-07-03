<?php

namespace Tests\Support;

use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Services\BotActionService;
use App\Modules\Bot\Services\BotTaskInteractionService;
use App\Modules\Bot\Services\BotTaskToolFactory;
use App\Modules\Tasks\Models\Task;
use Laravel\Ai\Tools\Request as ToolRequest;

/**
 * Deterministic test double for BotTaskExecutionAgent.
 *
 * WHY THIS EXISTS (STEP 0 finding): Laravel AI's FakeTextGateway accepts an agent's
 * tools but NEVER invokes them — a faked prompt just returns a canned response, so the
 * built-in fake cannot script the multi-step "tool A -> tool B -> finish" loop this
 * batch needs. Its fake closure also only receives (prompt, attachments, provider,
 * model), not the agent, so tools are unreachable from there.
 *
 * This double is bound in the container in place of BotTaskExecutionAgent (see
 * FakesBotExecutionAgent::scriptBotRun). It is NOT a subclass — the real agent's
 * prompt() has a strict AgentResponse return type; a standalone double keeps the seam
 * simple. It exposes the SAME constructor shape the job resolves and, on prompt(),
 * invokes the REAL tools against the REAL interaction service in a SCRIPTED ORDER.
 *
 * A script is a list of [toolName, arguments] pairs, e.g.
 *   [['post_comment', ['text' => 'hi']], ['fill_form', ['answers' => [...]]], ['finish', []]]
 * A step whose tool is not exposed for the task (e.g. fill_form with no form) is skipped.
 * The run stops once a terminal tool (ask_and_wait / finish) fires.
 */
class ScriptedBotExecutionAgent
{
    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    public static array $script = [];

    public function __construct(
        private Bot $bot,
        private Task $task,
        private string $context,
        private BotTaskInteractionService $interaction,
    ) {}

    /** Set the tool-call script for the next run(s). */
    public static function script(array $script): void
    {
        self::$script = $script;
    }

    public function prompt(
        string $prompt,
        array $attachments = [],
        mixed $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): mixed {
        $tools = $this->toolsByName();

        foreach (self::$script as [$name, $arguments]) {
            $tool = $tools[$name] ?? null;

            if ($tool === null) {
                continue; // tool not exposed for this task (e.g. fill_form without a form)
            }

            $tool->handle(new ToolRequest($arguments));

            if ($this->interaction->isDone()) {
                break; // ask_and_wait / finish ended the run
            }
        }

        return null;
    }

    /** @return array<string, \Laravel\Ai\Contracts\Tool> */
    private function toolsByName(): array
    {
        // Same factory the real agent uses — identical core + granted registry tools.
        return app(BotTaskToolFactory::class)->forRunKeyed(
            $this->bot,
            $this->task,
            $this->interaction,
            app(BotActionService::class),
        );
    }
}
