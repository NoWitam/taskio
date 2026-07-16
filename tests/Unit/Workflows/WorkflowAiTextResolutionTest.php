<?php

namespace Tests\Unit\Workflows;

use App\Modules\Workflows\Agents\WorkflowAiTextAgent;
use App\Modules\Workflows\Enums\WorkflowAiPersona;
use App\Modules\Workflows\Services\WorkflowAiTextService;
use App\Modules\Workflows\Services\WorkflowOperationExecutor;
use App\Modules\Workflows\Services\WorkflowVariableResolver;
use Tests\Support\ScriptedWorkflowAiTextService;
use Tests\TestCase;

/**
 * SB2 — the `@[ai-text]` directive: the resolver EXECUTES it, generating field text at run time.
 *
 * TWO seams are exercised:
 *   1. RESOLVER + a scripted WorkflowAiTextService double (no provider): proves the directive is
 *      extracted (even with a NESTED variable in the prompt), the prompt is FULLY resolved before
 *      the AI sees it, the persona is passed, the AI output is inserted verbatim (never
 *      re-interpreted as a reference), and nested ai-text is depth-capped.
 *   2. The REAL WorkflowAiTextService + a FAKED WorkflowAiTextAgent (Promptable::fake, the same
 *      built-in seam ScheduleAssistAgent uses): proves the fail-closed contract, the per-run call
 *      budget, blank-prompt short-circuit, and truncation.
 */
class WorkflowAiTextResolutionTest extends TestCase
{
    /** The run context the resolver reads. */
    private function context(): array
    {
        return [
            'trigger' => ['task_id' => 'trigger-task-uuid', 'fields' => ['name' => 'Alice']],
            'steps' => [],
        ];
    }

    /** A resolver wired to a scripted ai-text double (records prompt + persona, returns canned text). */
    private function resolverWith(ScriptedWorkflowAiTextService $ai): WorkflowVariableResolver
    {
        return new WorkflowVariableResolver(new WorkflowOperationExecutor, $ai);
    }

    /** Encode an `@[ai-text]("…")` directive exactly as the editor does (JSON then `"`→`\"`). */
    private function aiText(string $prompt, ?string $personaId = null): string
    {
        $payload = json_encode([
            'v' => 1,
            'data' => ['id' => 'ai_1', 'personaId' => $personaId, 'prompt' => $prompt, 'labels' => []],
        ]);

        return '@[ai-text]("' . str_replace('"', '\\"', $payload) . '")';
    }

    /** Encode an identity `@[variable]("…")` directive for $path (the SB1 shape). */
    private function variable(string $path): string
    {
        $payload = json_encode([
            'v' => 1,
            'data' => ['id' => $path, 'name' => $path, 'type' => 'text', 'locked' => false, 'pipeline' => [], 'resultType' => 'text'],
        ]);

        return '@[variable]("' . str_replace('"', '\\"', $payload) . '")';
    }

    // ---- Resolver + scripted service ------------------------------------------

    public function test_standalone_ai_text_directive_is_replaced_by_the_generated_text(): void
    {
        $ai = new ScriptedWorkflowAiTextService(['A generated title']);
        $resolver = $this->resolverWith($ai);

        $result = $resolver->resolve($this->aiText('Write a task title'), $this->context());

        $this->assertSame('A generated title', $result);
        $this->assertCount(1, $ai->recorded);
        $this->assertSame('Write a task title', $ai->recorded[0]['prompt']);
    }

    public function test_embedded_ai_text_directive_is_spliced_into_surrounding_text(): void
    {
        $ai = new ScriptedWorkflowAiTextService(['SUMMARY']);
        $resolver = $this->resolverWith($ai);

        $result = $resolver->resolve('Intro: ' . $this->aiText('Summarize') . ' — end', $this->context());

        $this->assertSame('Intro: SUMMARY — end', $result);
    }

    public function test_prompt_variable_is_resolved_before_reaching_the_ai(): void
    {
        // The prompt embeds a variable directive; it must arrive at the AI already substituted.
        $ai = new ScriptedWorkflowAiTextService(['ok']);
        $resolver = $this->resolverWith($ai);

        $resolver->resolve($this->aiText('Greet ' . $this->variable('trigger.fields.name')), $this->context());

        $this->assertSame('Greet Alice', $ai->recorded[0]['prompt']);
    }

    public function test_prompt_flat_token_is_resolved_before_reaching_the_ai(): void
    {
        $ai = new ScriptedWorkflowAiTextService(['ok']);
        $resolver = $this->resolverWith($ai);

        $resolver->resolve($this->aiText('For task {{trigger.task_id}}'), $this->context());

        $this->assertSame('For task trigger-task-uuid', $ai->recorded[0]['prompt']);
    }

    public function test_persona_id_is_passed_to_the_service(): void
    {
        $ai = new ScriptedWorkflowAiTextService(['ok']);
        $this->resolverWith($ai)->resolve($this->aiText('x', 'formal'), $this->context());

        $this->assertSame(WorkflowAiPersona::FORMAL, $ai->recorded[0]['persona']);
    }

    public function test_null_and_unknown_persona_default_to_neutral(): void
    {
        $ai = new ScriptedWorkflowAiTextService(['a', 'b']);
        $resolver = $this->resolverWith($ai);

        $resolver->resolve($this->aiText('x', null), $this->context());
        $resolver->resolve($this->aiText('y', 'does-not-exist'), $this->context());

        $this->assertSame(WorkflowAiPersona::NEUTRAL, $ai->recorded[0]['persona']);
        $this->assertSame(WorkflowAiPersona::NEUTRAL, $ai->recorded[1]['persona']);
    }

    public function test_ai_output_is_not_reinterpreted_as_a_reference(): void
    {
        // The generated text LOOKS like a flat token; because ai-text output is masked out of the
        // reference passes, it is inserted verbatim and NOT resolved against the context.
        $ai = new ScriptedWorkflowAiTextService(['{{trigger.task_id}}']);

        $result = $this->resolverWith($ai)->resolve($this->aiText('x'), $this->context());

        $this->assertSame('{{trigger.task_id}}', $result);
    }

    public function test_empty_generation_resolves_to_empty_string(): void
    {
        // Fail-closed: the double returns '' (as the real service does on any AI failure).
        $ai = new ScriptedWorkflowAiTextService(null, '');

        $this->assertSame('', $this->resolverWith($ai)->resolve($this->aiText('x'), $this->context()));
        $this->assertSame('pre  post', $this->resolverWith($ai)->resolve('pre ' . $this->aiText('x') . ' post', $this->context()));
    }

    public function test_ai_text_inside_a_winning_if_block_branch_is_generated(): void
    {
        // ai-text nested in an if-block branch body must resolve when that branch wins.
        $ai = new ScriptedWorkflowAiTextService(['BRANCH TEXT']);
        $resolver = $this->resolverWith($ai);

        $condition = ['variableId' => 'trigger.fields.name', 'pipeline' => [
            ['stepId' => 's1', 'operationId' => 'text_is_not_empty', 'args' => [], 'outputType' => 'boolean'],
        ], 'resultType' => 'boolean'];

        $md = '```if-block ' . json_encode(['id' => 'if_1', 'v' => 1]) . "\n"
            . '[[IF ' . json_encode(['id' => 'b1', 'condition' => $condition]) . ']]' . "\n"
            . 'value=' . $this->aiText('Describe') . "\n"
            . '[[ELSE ' . json_encode(['id' => 'b2']) . ']]' . "\n" . 'none' . "\n```";

        $this->assertSame('value=BRANCH TEXT', $this->resolverWith($ai)->resolve($md, $this->context()));
    }

    public function test_nested_ai_text_is_capped_at_depth_three(): void
    {
        // Four ai-text levels nested through their prompts. With the depth-3 cap, the 4th level is
        // NOT sent to the AI (resolves to ''), so exactly THREE generations happen (deepest first),
        // and no recorded prompt carries the level-4 marker.
        $level4 = $this->aiText('four');
        $level3 = $this->aiText('three ' . $level4);
        $level2 = $this->aiText('two ' . $level3);
        $level1 = $this->aiText('one ' . $level2);

        // Calls happen deepest-first: L3 prompt, then L2 (with R3), then L1 (with R2).
        $ai = new ScriptedWorkflowAiTextService(['R3', 'R2', 'R1']);

        $result = $this->resolverWith($ai)->resolve($level1, $this->context());

        $this->assertSame('R1', $result);
        $this->assertCount(3, $ai->recorded);
        foreach ($ai->recorded as $call) {
            $this->assertStringNotContainsString('four', $call['prompt']);
        }
        $this->assertSame('three ', $ai->recorded[0]['prompt']);
        $this->assertSame('two R3', $ai->recorded[1]['prompt']);
        $this->assertSame('one R2', $ai->recorded[2]['prompt']);
    }

    public function test_malformed_ai_text_directive_is_left_literal(): void
    {
        // A payload that never JSON-decodes has no valid terminator → the marker stays literal and
        // no AI call is made.
        $ai = new ScriptedWorkflowAiTextService(['nope']);
        $broken = '@[ai-text]("not-json")';

        $this->assertSame('x ' . $broken . ' y', $this->resolverWith($ai)->resolve('x ' . $broken . ' y', $this->context()));
        $this->assertCount(0, $ai->recorded);
    }

    // ---- Real service + faked agent -------------------------------------------

    public function test_service_returns_the_agents_text_and_passes_the_resolved_prompt(): void
    {
        $seen = null;
        WorkflowAiTextAgent::fake(function (string $prompt) use (&$seen) {
            $seen = $prompt;

            return 'Hello world';
        });

        $text = (new WorkflowAiTextService)->generate('write a greeting', WorkflowAiPersona::FRIENDLY);

        $this->assertSame('Hello world', $text);
        $this->assertSame('write a greeting', $seen);
    }

    public function test_service_fails_closed_to_empty_on_agent_error(): void
    {
        WorkflowAiTextAgent::fake(fn () => throw new \RuntimeException('provider down'));

        $this->assertSame('', (new WorkflowAiTextService)->generate('anything', WorkflowAiPersona::NEUTRAL));
    }

    public function test_service_short_circuits_a_blank_prompt_without_calling_the_agent(): void
    {
        WorkflowAiTextAgent::fake(fn () => 'SHOULD NOT BE USED');

        $this->assertSame('', (new WorkflowAiTextService)->generate('   ', WorkflowAiPersona::NEUTRAL));
    }

    public function test_service_enforces_the_per_run_call_budget(): void
    {
        config()->set('workflows.ai_text_max_calls_per_run', 2);
        WorkflowAiTextAgent::fake(['one', 'two']);

        $service = new WorkflowAiTextService;

        $this->assertSame('one', $service->generate('a', WorkflowAiPersona::NEUTRAL));
        $this->assertSame('two', $service->generate('b', WorkflowAiPersona::NEUTRAL));
        // Third call is over budget → '' (the agent is not consulted).
        $this->assertSame('', $service->generate('c', WorkflowAiPersona::NEUTRAL));
    }

    public function test_service_truncates_to_the_configured_maximum(): void
    {
        config()->set('workflows.ai_text_max_chars', 5);
        WorkflowAiTextAgent::fake(['ABCDEFGHIJ']);

        $this->assertSame('ABCDE', (new WorkflowAiTextService)->generate('x', WorkflowAiPersona::NEUTRAL));
    }

    // ---- Agent instructions ----------------------------------------------------

    public function test_agent_instructions_carry_the_selected_persona_tone(): void
    {
        $this->assertStringContainsString(
            'formal, precise',
            (string) (new WorkflowAiTextAgent(WorkflowAiPersona::FORMAL))->instructions(),
        );
        $this->assertStringContainsString(
            'neutral, professional',
            (string) (new WorkflowAiTextAgent(WorkflowAiPersona::NEUTRAL))->instructions(),
        );
    }
}
