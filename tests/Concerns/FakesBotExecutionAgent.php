<?php

namespace Tests\Concerns;

use App\Modules\Approvals\Agents\ApprovalEvaluationAgent;
use App\Modules\Bot\Agents\BotTaskExecutionAgent;
use Closure;
use Tests\Support\ScriptedBotExecutionAgent;

/**
 * Reusable AI test seams.
 *
 * STEP 0 finding: Laravel AI's Agent::fake() cannot script a MULTI-STEP tool-call run —
 * its FakeTextGateway accepts the agent's tools but never invokes them, and the fake
 * closure only receives (prompt, attachments, provider, model), not the agent. So the
 * interactive bot run (post_comment -> fill_form -> finish) is driven here by SWAPPING
 * the execution agent in the container for ScriptedBotExecutionAgent, which invokes the
 * REAL tools against the REAL interaction service in a scripted order (no provider).
 *
 * The APPROVAL evaluation agent has structured output (no tool loop), so it still uses
 * the built-in Agent::fake() seam.
 */
trait FakesBotExecutionAgent
{
    /**
     * Script a MULTI-STEP interactive run: the bot execution agent is swapped in the
     * container for a double that invokes the REAL tools in the given order against the
     * REAL interaction service (no provider). This is the seam the built-in fake cannot
     * provide — see ScriptedBotExecutionAgent for the why.
     *
     * @param  array<int, array{0: string, 1?: array<string, mixed>}>  $script
     *                                                                          list of [toolName, arguments], e.g.
     *                                                                          [['post_comment', ['text' => 'hi']], ['finish', []]]
     */
    protected function scriptBotRun(array $script): void
    {
        ScriptedBotExecutionAgent::script(array_map(
            fn ($step) => [$step[0], $step[1] ?? []],
            $script,
        ));

        // The reported usage is STATIC on the double, so a metering test could otherwise leak its token
        // counts into every later scripted run in the same process. Scripting a run resets it; a test
        // that wants tokens asks for them AFTER scripting (scriptBotRunReporting).
        ScriptedBotExecutionAgent::$usage = null;

        $this->app->bind(BotTaskExecutionAgent::class, ScriptedBotExecutionAgent::class);
    }

    /**
     * Script a run that also REPORTS provider token usage on the response it returns — the seam the cost
     * meter reads to bill the run. Same script format as {@see scriptBotRun}.
     *
     * @param  array<int, array{0: string, 1?: array<string, mixed>}>  $script
     */
    protected function scriptBotRunReporting(array $script, int $promptTokens, int $completionTokens): void
    {
        $this->scriptBotRun($script);

        ScriptedBotExecutionAgent::reportUsage($promptTokens, $completionTokens);
    }

    /**
     * Make the bot execution agent throw on prompt() (failure path). Binds a standalone
     * throwing double (not a subclass — the real prompt() has a strict return type).
     */
    protected function failBotRun(string $message = 'AI provider error'): void
    {
        $this->app->bind(BotTaskExecutionAgent::class, fn () => new class($message)
        {
            public function __construct(private string $message) {}

            public function prompt(...$args): mixed
            {
                throw new \RuntimeException($this->message);
            }
        });
    }

    /**
     * Fake the approval evaluation agent (used by both generic AI and named-bot
     * approver stages — they share ApprovalEvaluationAgent). The same seam lets an
     * approval flow be driven deterministically without a real provider.
     *
     * @param  array{decision: string, note?: string}  $response
     */
    protected function fakeApprovalEvaluation(array $response): void
    {
        ApprovalEvaluationAgent::fake([$response]);
    }

    /**
     * Capture the instructions passed to the (faked) approval agent, so a test can
     * assert the bot persona was injected. Returns the recorded prompt instructions.
     */
    protected function fakeApprovalEvaluationUsing(Closure $callback): void
    {
        ApprovalEvaluationAgent::fake($callback);
    }
}
