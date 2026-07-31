<?php

namespace Tests\Unit\Variables;

use App\Modules\Variables\Contracts\AiTextGenerator;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Services\OperationExecutor;
use App\Modules\Variables\Services\VariableResolver;
use App\Modules\Variables\Support\CustomFunctionOperation;
use App\Modules\Variables\Support\FunctionScope;
use Tests\TestCase;

/**
 * CHARACTERIZATION pin for the R2 PR-1a down-move: the interpolation engine relocated from
 * Workflows into Variables (WorkflowVariableResolver → VariableResolver) and its AI-text call went
 * through the new Variables AiTextGenerator seam. This resolves ONE representative "workflow run"
 * config — covering every resolution path the move touched — and pins the output BYTE-IDENTICAL:
 *
 *   - an identity directive over a `globals.*` reference,
 *   - a directive PIPELINE ending in a built-in op,
 *   - a directive PIPELINE ending in a `fn:<uuid>` CUSTOM FUNCTION (executed by expansion),
 *   - a legacy flat `{{steps.<key>.*}}` token,
 *   - a fenced IF-BLOCK whose winning branch body is emitted,
 *   - a structured value-or-variable PIPELINE,
 *   - and an `@[ai-text]` directive resolved through a FAKE AiTextGenerator (deterministic, no
 *     provider), whose prompt embeds a variable to prove it reached the generator FULLY resolved.
 *
 * The resolver depends ONLY on the AiTextGenerator interface here — no Workflows class is named —
 * which is exactly the seam the move introduced.
 */
class VariableResolverCharacterizationTest extends TestCase
{
    /** Records each call so the test can prove the prompt arrived fully resolved. */
    private function fakeAi(): AiTextGenerator
    {
        return new class implements AiTextGenerator
        {
            /** @var array<int, array{prompt: string, personaId: ?string, authorId: ?string}> */
            public array $seen = [];

            public function generate(string $prompt, ?string $personaId, ?string $authorId): string
            {
                $this->seen[] = ['prompt' => $prompt, 'personaId' => $personaId, 'authorId' => $authorId];

                return 'GEN(' . $prompt . ')';
            }
        };
    }

    /** The run context: trigger + steps + globals, plus the workspace functions under FunctionScope. */
    private function context(): array
    {
        // fn:shout(text) -> text : uppercases its input (a one-op body the executor runs by expansion).
        $shout = new CustomFunctionOperation(
            'shout',
            VariableType::TEXT,
            VariableType::TEXT,
            [],
            [['op' => 'text_uppercase', 'args' => []]],
            [],
        );

        return FunctionScope::forFunctions([$shout])->writeInto([
            'trigger' => ['fields' => ['name' => 'ada', 'topic' => 'launch']],
            'steps' => ['prep' => ['result' => 'ready']],
            'globals' => ['brand' => 'Taskio', 'tagline' => 'ship it'],
        ]);
    }

    /** The path → real base type map the runner hands the resolver (recovers a piped ref's true type). */
    private function typeMap(): array
    {
        return [
            'globals.brand' => VariableType::TEXT,
            'globals.tagline' => VariableType::TEXT,
            'trigger.fields.name' => VariableType::TEXT,
            'trigger.fields.topic' => VariableType::TEXT,
            'steps.prep.result' => VariableType::TEXT,
        ];
    }

    /** An identity `@[variable]("…")` directive (empty pipeline). */
    private function directive(string $path, string $type = 'text'): string
    {
        return $this->encodeDirective($path, $type, []);
    }

    /** A `@[variable]("…")` directive carrying an editor pipeline. */
    private function directiveWithPipeline(string $path, string $type, array $pipeline): string
    {
        return $this->encodeDirective($path, $type, $pipeline);
    }

    private function encodeDirective(string $path, string $type, array $pipeline): string
    {
        $payload = json_encode([
            'v' => 1,
            'data' => [
                'id' => $path,
                'name' => $path,
                'type' => $type,
                'locked' => false,
                'pipeline' => $pipeline,
                'resultType' => $type,
            ],
        ]);

        return '@[variable]("' . str_replace('"', '\\"', $payload) . '")';
    }

    /** One editor-shaped pipeline step (operationId, not op). */
    private function step(string $operationId, array $args = []): array
    {
        return ['stepId' => 's_' . $operationId, 'operationId' => $operationId, 'args' => $args, 'outputType' => 'text'];
    }

    /** An `@[ai-text]("…")` directive encoded exactly as the editor does. */
    private function aiText(string $prompt, ?string $personaId = null, ?string $authorId = null): string
    {
        $data = ['id' => 'ai_1', 'personaId' => $personaId, 'prompt' => $prompt, 'labels' => []];

        if ($authorId !== null) {
            $data['authorId'] = $authorId;
        }

        $payload = json_encode(['v' => 1, 'data' => $data]);

        return '@[ai-text]("' . str_replace('"', '\\"', $payload) . '")';
    }

    /** A fenced if-block whose single IF branch fires when $variableId is non-empty. */
    private function ifBlockOnNotEmpty(string $variableId, string $body): string
    {
        $condition = [
            'variableId' => $variableId,
            'pipeline' => [$this->step('text_is_not_empty')],
            'resultType' => 'boolean',
        ];

        return '```if-block ' . json_encode(['id' => 'if_1', 'v' => 1]) . "\n"
            . '[[IF ' . json_encode(['id' => 'b1', 'condition' => $condition]) . ']]' . "\n"
            . $body . "\n"
            . '[[ELSE ' . json_encode(['id' => 'b2']) . ']]' . "\n" . 'none' . "\n```";
    }

    public function test_a_representative_run_config_resolves_byte_identically(): void
    {
        $ai = $this->fakeAi();
        // Default OperationResolver (built-ins ∪ the context's functions) — the direct-`new` unit shape.
        $resolver = new VariableResolver(new OperationExecutor, $ai);
        $context = $this->context();

        // The TEXT-field surface generic resolve() interprets: directives, pipelines, flat tokens,
        // if-blocks, and ai-text (a non-string scalar passes through untouched).
        $config = [
            // identity directive over a global
            'title' => $this->directive('globals.brand'),
            // directive pipeline ending in a CUSTOM FUNCTION: 'ada' -> fn:shout -> 'ADA'
            'shout' => $this->directiveWithPipeline('trigger.fields.name', 'text', [$this->step('fn:shout')]),
            // directive pipeline ending in a BUILT-IN: 'ship it' -> upper -> 'SHIP IT'
            'upper' => $this->directiveWithPipeline('globals.tagline', 'text', [$this->step('text_uppercase')]),
            // legacy flat token
            'flat' => 'run {{steps.prep.result}}',
            // if-block: topic is non-empty, so the IF branch body is emitted (trimmed)
            'branch' => $this->ifBlockOnNotEmpty('trigger.fields.topic', 'topic present'),
            // ai-text whose prompt embeds a variable: the generator sees the RESOLVED prompt
            'ai' => $this->aiText('draft ' . $this->directive('trigger.fields.topic'), 'formal'),
            // a non-string scalar passes through untouched
            'count' => 7,
        ];

        $resolved = $resolver->resolve($config, $context, $this->typeMap());

        $this->assertSame([
            'title' => 'Taskio',
            'shout' => 'ADA',
            'upper' => 'SHIP IT',
            'flat' => 'run ready',
            'branch' => 'topic present',
            'ai' => 'GEN(draft launch)',
            'count' => 7,
        ], $resolved);

        // The ai-text prompt reached the generator FULLY resolved (the embedded variable substituted),
        // and the persona id rode through as the plain contract `?string`. A block with NO author yields
        // a null authorId — the pre-author byte-shape.
        $this->assertSame([['prompt' => 'draft launch', 'personaId' => 'formal', 'authorId' => null]], $ai->seen);

        // The STRUCTURED value-or-variable pipeline path (used by step services for non-text slots):
        // 'Taskio' -> text_lowercase -> 'taskio', coerced to the field's expected type.
        $structured = [
            'kind' => 'variable',
            'ref' => ['source' => 'globals', 'path' => 'globals.brand', 'type' => 'text'],
            'pipeline' => [['op' => 'text_lowercase']],
        ];
        $this->assertSame('taskio', $resolver->resolveValueOrVariable($structured, $context, VariableType::TEXT));
    }

    /**
     * The per-block AUTHOR rides the SAME seam as the persona: the resolver decodes it off the payload and
     * hands it to the generator UNTOUCHED — it never looks up, validates or interprets an author (that is
     * the upper layer's job through the AuthorVoiceResolver seam). Tolerance is identical to the persona's:
     * a non-string author (or none at all) decodes to null, so a malformed payload key can never break a
     * generation, it only means "no author for this block".
     */
    public function test_the_block_author_id_is_passed_through_to_the_generator_untouched(): void
    {
        $ai = $this->fakeAi();
        $resolver = new VariableResolver(new OperationExecutor, $ai);

        $author = 'a1b2c3d4-0000-4000-8000-000000000001';

        $this->assertSame(
            'GEN(draft launch)',
            $resolver->resolve($this->aiText('draft ' . $this->directive('trigger.fields.topic'), 'formal', $author), $this->context(), $this->typeMap()),
        );
        $this->assertSame([['prompt' => 'draft launch', 'personaId' => 'formal', 'authorId' => $author]], $ai->seen);

        // A non-string author decodes to null (the persona's tolerance), and the generation still runs.
        $broken = '@[ai-text]("' . str_replace('"', '\\"', (string) json_encode([
            'v' => 1,
            'data' => ['id' => 'ai_1', 'personaId' => null, 'authorId' => ['nope'], 'prompt' => 'plain', 'labels' => []],
        ])) . '")';

        $ai2 = $this->fakeAi();
        $this->assertSame('GEN(plain)', (new VariableResolver(new OperationExecutor, $ai2))->resolve($broken, $this->context()));
        $this->assertSame([['prompt' => 'plain', 'personaId' => null, 'authorId' => null]], $ai2->seen);
    }

    public function test_the_slots_root_is_inert_for_a_workflow_context(): void
    {
        // The ROOTS superset carries `slots` (a template root). In a workflow context — which never
        // populates `slots` — a `slots.*` reference IS a whitelisted lookup but resolves to nothing:
        // a standalone token reads the missing value (null), and an embedded one stringifies to ''.
        // Either way it is inert (never leaks, never throws).
        $resolver = new VariableResolver(new OperationExecutor, $this->fakeAi());
        $context = $this->context();

        $this->assertContains('slots', VariableResolver::ROOTS);
        $this->assertNull($resolver->resolve('{{slots.headline}}', $context, $this->typeMap()));
        $this->assertSame('x  y', $resolver->resolve('x {{slots.headline}} y', $context, $this->typeMap()));
    }

    public function test_the_parts_root_is_inert_for_a_workflow_context_but_resolves_from_a_populated_one(): void
    {
        // Phase A adds the `parts` root (a generation session's earlier rendered parts). It reuses the SAME
        // engine — NO fork — so it behaves EXACTLY like `globals`: inert when the context never populates it
        // (a workflow run), and a plain whitelisted dotted lookup when it does.
        $resolver = new VariableResolver(new OperationExecutor, $this->fakeAi());
        $context = $this->context(); // a workflow context: no `parts` key

        $this->assertContains('parts', VariableResolver::ROOTS);

        // Inert in a workflow context (never leaks, never throws) — a standalone flat token reads null, an
        // embedded directive stringifies the missing value to ''.
        $this->assertNull($resolver->resolve('{{parts.body}}', $context, $this->typeMap()));
        $this->assertSame('x  y', $resolver->resolve('x ' . $this->directive('parts.body') . ' y', $context, $this->typeMap()));

        // Populated exactly like globals (the executor injects a `{<key>: <rendered text>}` map): a
        // standalone directive returns the stored string, an embedded one stringifies into the text.
        $seeded = ['parts' => ['body' => 'HELLO WORLD']] + $context;
        $this->assertSame('HELLO WORLD', $resolver->resolve($this->directive('parts.body'), $seeded, $this->typeMap()));
        $this->assertSame('Recap: HELLO WORLD.', $resolver->resolve('Recap: ' . $this->directive('parts.body') . '.', $seeded, $this->typeMap()));

        // INJECTION: a `parts.*` value carrying `{{…}}` / `@[…]` bytes is DATA — it rides the SAME NUL-mask a
        // resolved global does, so it is embedded VERBATIM, never re-interpreted as a second-order reference.
        $injected = ['parts' => ['body' => 'INJECT{{globals.tagline}}@[variable]("globals.brand")END']] + $context;
        $this->assertSame(
            'A INJECT{{globals.tagline}}@[variable]("globals.brand")END B',
            $resolver->resolve('A ' . $this->directive('parts.body') . ' B', $injected, $this->typeMap()),
        );
    }
}
