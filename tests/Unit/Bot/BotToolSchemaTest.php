<?php

namespace Tests\Unit\Bot;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\OpenAi\Concerns\MapsTools;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionClass;
use Stringable;
use Tests\TestCase;

/**
 * Guards the bot tool-set against a failure the rest of the suite can never see: the
 * scripted agent (ScriptedBotExecutionAgent) invokes tools directly, so a schema the
 * PROVIDER rejects still passes every feature test — and then kills real runs.
 *
 * laravel/ai sends `strict: true` for every function (Gateway\OpenAi\Concerns\MapsTools),
 * and OpenAI's strict function calling requires each object in the schema to enumerate
 * `properties`, mark them all `required`, and set `additionalProperties: false`. Two
 * shapes silently violate it:
 *
 *   1. an EMPTY schema — laravel/ai then omits `parameters` altogether, and the API
 *      answers "Invalid schema for function 'FinishTool': In context=(),
 *      'additionalProperties' is required to be supplied and to be false";
 *   2. a free-form `object()` with no properties (e.g. a dynamic answers map).
 *
 * Either 400s the ENTIRE run — every tool, not just the offending one. This test asserts
 * the invariant on the ACTUAL mapped payload, so a new tool is covered automatically.
 */
class BotToolSchemaTest extends TestCase
{
    public function test_every_bot_tool_maps_to_a_strict_mode_safe_openai_payload(): void
    {
        $tools = $this->toolClasses();

        $this->assertNotEmpty($tools, 'No bot tools were discovered — the glob is wrong.');

        foreach ($tools as $class) {
            // schema() never reads constructor state in this module, so the tools can be
            // introspected without their runtime dependencies.
            $this->assertToolIsStrictSafe(
                (new ReflectionClass($class))->newInstanceWithoutConstructor(),
                $class
            );
        }
    }

    /**
     * The guard must actually BITE — a schema check that cannot fail is decoration. Feeds
     * it the two shapes that broke real runs and asserts each is caught.
     */
    public function test_the_guard_rejects_an_empty_schema_and_a_free_form_object(): void
    {
        $emptySchema = new class implements Tool
        {
            public function description(): Stringable|string
            {
                return 'no arguments at all';
            }

            public function handle(Request $request): Stringable|string
            {
                return 'ok';
            }

            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };

        $freeFormObject = new class implements Tool
        {
            public function description(): Stringable|string
            {
                return 'takes an undeclared map';
            }

            public function handle(Request $request): Stringable|string
            {
                return 'ok';
            }

            public function schema(JsonSchema $schema): array
            {
                return ['answers' => $schema->object()->required()];
            }
        };

        foreach ([$emptySchema, $freeFormObject] as $tool) {
            try {
                $this->assertToolIsStrictSafe($tool, $tool::class);
            } catch (AssertionFailedError) {
                continue; // caught, as it must be
            }

            $this->fail('The strict-mode guard accepted a payload OpenAI would reject.');
        }
    }

    /** Assert one tool's mapped OpenAI function definition satisfies strict mode. */
    private function assertToolIsStrictSafe(Tool $tool, string $label): void
    {
        $mapper = new class
        {
            use MapsTools;

            public function map(Tool $tool): array
            {
                return $this->mapTool($tool);
            }
        };

        $definition = $mapper->map($tool);

        $this->assertArrayHasKey(
            'parameters',
            $definition,
            "{$label} maps to a function with NO `parameters` (its schema is empty), which OpenAI "
            . 'rejects under strict mode and fails the whole run. Declare at least one '
            . 'nullable()->required() property.'
        );

        $this->assertStrictObject($definition['parameters'], $label, 'parameters');
    }

    /**
     * Recursively assert an object node is fully specified: `properties` present, every
     * property `required`, and `additionalProperties` explicitly false.
     *
     * @param  array<string, mixed>  $node
     */
    private function assertStrictObject(array $node, string $class, string $path): void
    {
        $this->assertArrayHasKey('properties', $node, "{$class}: object at [{$path}] declares no `properties`.");
        $this->assertSame(
            false,
            $node['additionalProperties'] ?? null,
            "{$class}: object at [{$path}] must set `additionalProperties` to false (OpenAI strict mode)."
        );
        $this->assertEqualsCanonicalizing(
            array_keys($node['properties']),
            $node['required'] ?? [],
            "{$class}: object at [{$path}] must list EVERY property in `required` (use nullable() for optional ones)."
        );

        foreach ($node['properties'] as $name => $property) {
            $this->assertNodeIsStrict($property, $class, "{$path}.{$name}");
        }
    }

    /** Walk one schema node, descending into nested objects and array items. */
    private function assertNodeIsStrict(mixed $node, string $class, string $path): void
    {
        if (!is_array($node)) {
            return;
        }

        $types = (array) ($node['type'] ?? []);

        if (in_array('object', $types, true)) {
            $this->assertStrictObject($node, $class, $path);
        }

        if (in_array('array', $types, true) && isset($node['items'])) {
            $this->assertNodeIsStrict($node['items'], $class, "{$path}[]");
        }
    }

    /**
     * Every Tool implementation under app/modules/Bot/Tools (including the registry
     * sub-directory), discovered by scanning so a NEW tool is covered automatically.
     *
     * @return array<int, class-string<Tool>>
     */
    private function toolClasses(): array
    {
        $files = array_merge(
            glob(app_path('modules/Bot/Tools/*.php')) ?: [],
            glob(app_path('modules/Bot/Tools/Registry/*.php')) ?: [],
        );

        $classes = [];

        foreach ($files as $file) {
            $relative = str_replace([app_path('modules/'), '.php', '/'], ['', '', '\\'], $file);
            $class = 'App\\Modules\\' . $relative;

            if (class_exists($class) && (new ReflectionClass($class))->implementsInterface(Tool::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
