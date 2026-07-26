<?php

namespace Tests\Unit\Workflows;

use App\Modules\Variables\Enums\Operation as WorkflowOperation;
use App\Modules\Variables\Enums\VariableType as WorkflowVariableType;
use App\Modules\Variables\Services\PipelineValidator;
use App\Modules\Workflows\Services\WorkflowVariableCatalogService;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Write-validation of array<object> (repeater) / array<file> ELEMENT ACCESS (array-ops wave 3): the
 * descriptor-seeded walk over a repeater source, the element SCOPE exposing exactly `element.<subfield>`,
 * the scope-rooted (union) element pipeline for map/filter/sort, and the fail-closed rejection of a scope
 * subfield used OUTSIDE an element pipeline. Driven straight through PipelineValidator (which now owns
 * the value-pipeline type-flow validation) so the repeater source (which is not a condition source) can
 * be exercised without a full workflow round-trip.
 */
class WorkflowElementScopeValidationTest extends TestCase
{
    private function validator(): PipelineValidator
    {
        return app(PipelineValidator::class);
    }

    private function bag(): ValidatorContract
    {
        return Validator::make([], []);
    }

    /** A repeater descriptor: array<object> with a numeric `price` + text `name`. */
    private function repeaterDescriptor(): array
    {
        return WorkflowVariableType::OBJECT->descriptor(
            fields: [
                ['key' => 'price', 'label' => 'Price', 'descriptor' => WorkflowVariableType::NUMBER->descriptor()],
                ['key' => 'name', 'label' => 'Name', 'descriptor' => WorkflowVariableType::TEXT->descriptor()],
            ],
            array: true,
        );
    }

    /** A scope subfield-rooted element pipeline union `{ref, pipeline}`. */
    private function union(string $path, string $type, array $steps = []): array
    {
        return ['kind' => 'variable', 'ref' => ['source' => 'scope', 'path' => $path, 'type' => $type], 'pipeline' => $steps];
    }

    private function op(string $id, array $args = []): array
    {
        return ['op' => $id, 'args' => $args];
    }

    public function test_a_repeater_filter_by_a_numeric_element_subfield_validates(): void
    {
        $bag = $this->bag();

        // array<object> |> filter(element.price > 100) |> count: descriptor-seeded so array_filter gates on
        // the repeater's array-ness, the element pipeline is a UNION rooted at element.price → boolean, and
        // the filtered array<object> feeds array_count → number (an array<object> is flat `object`, so it is
        // only downstream-usable via another array op — count here).
        $this->validator()->validateValuePipeline(
            $bag,
            [
                $this->op('array_filter', ['pipeline' => $this->union('element.price', 'number', [
                    $this->op('num_gt', ['value' => 100]),
                ])]),
                $this->op('array_count'),
            ],
            'field.pipeline',
            WorkflowVariableType::TEXT, // the repeater's DEGRADED flat wire type
            [WorkflowVariableType::NUMBER],
            null,
            null,
            ['index' => [], 'fields_available' => true],
            0,
            null,
            $this->repeaterDescriptor(),
        );

        $this->assertTrue($bag->errors()->isEmpty(), $bag->errors()->first());
    }

    public function test_a_repeater_map_to_a_text_element_subfield_validates(): void
    {
        $bag = $this->bag();

        $this->validator()->validateValuePipeline(
            $bag,
            [$this->op('array_map', ['pipeline' => $this->union('element.name', 'text')])],
            'field.pipeline',
            WorkflowVariableType::TEXT,
            [WorkflowVariableType::MULTI],
            null,
            null,
            ['index' => [], 'fields_available' => true],
            0,
            null,
            $this->repeaterDescriptor(),
        );

        $this->assertTrue($bag->errors()->isEmpty(), $bag->errors()->first());
    }

    public function test_the_element_scope_exposes_only_the_element_subfields(): void
    {
        $bag = $this->bag();

        // element.nope is not a field of the repeater element → rejected (the scope exposes exactly the
        // declared subfields and nothing more).
        $this->validator()->validateValuePipeline(
            $bag,
            [$this->op('array_map', ['pipeline' => $this->union('element.nope', 'text')])],
            'field.pipeline',
            WorkflowVariableType::TEXT,
            [WorkflowVariableType::MULTI],
            null,
            null,
            ['index' => [], 'fields_available' => true],
            0,
            null,
            $this->repeaterDescriptor(),
        );

        $this->assertFalse($bag->errors()->isEmpty());
    }

    public function test_a_filter_element_pipeline_that_does_not_end_in_boolean_is_rejected(): void
    {
        $bag = $this->bag();

        // filter demands a boolean terminal; element.name |> (identity text) ends in text → rejected.
        $this->validator()->validateValuePipeline(
            $bag,
            [$this->op('array_filter', ['pipeline' => $this->union('element.name', 'text')])],
            'field.pipeline',
            WorkflowVariableType::TEXT,
            [WorkflowVariableType::MULTI],
            null,
            null,
            ['index' => [], 'fields_available' => true],
            0,
            null,
            $this->repeaterDescriptor(),
        );

        $this->assertFalse($bag->errors()->isEmpty());
    }

    public function test_element_scope_subfields_descends_object_and_file_arrays_but_not_scalars(): void
    {
        $catalog = app(WorkflowVariableCatalogService::class);

        // array<object> (repeater) → its object element's declared fields under the synthetic `element` root.
        $repeater = $catalog->elementScopeSubfields($this->repeaterDescriptor());
        $this->assertSame(WorkflowVariableType::NUMBER, $repeater['element.price'] ?? null);
        $this->assertSame(WorkflowVariableType::TEXT, $repeater['element.name'] ?? null);
        $this->assertArrayNotHasKey('element.nope', $repeater);

        // array<file> → the fixed file subfields {id,name,type,size,url}.
        $fileArray = ['base' => WorkflowVariableType::FILE->value, 'nullable' => false, 'array' => true];
        $file = $catalog->elementScopeSubfields($fileArray);
        $this->assertSame(WorkflowVariableType::TEXT, $file['element.name'] ?? null);
        $this->assertSame(WorkflowVariableType::TEXT, $file['element.type'] ?? null);
        $this->assertSame(WorkflowVariableType::NUMBER, $file['element.size'] ?? null);

        // array<scalar/enum> element → no subfields.
        $this->assertSame([], $catalog->elementScopeSubfields([
            'base' => WorkflowVariableType::ENUM->value, 'nullable' => false, 'array' => true,
        ]));
    }

    public function test_a_non_scope_arg_variable_inside_a_scope_rooted_element_pipeline_is_rejected(): void
    {
        $bag = $this->bag();

        // A globals.* arg-variable INSIDE an object-array filter's element pipeline: at runtime the whole
        // scope union is never pre-resolved (resolveScopePipeline fails it CLOSED), so the write-validator
        // must REJECT it even though globals.foo IS a known number in the reference index — the scope-rooted
        // path validates its inner arg-variables against a SCOPE-ONLY index, so a non-scope ref can't be
        // saved. Wrapped in |> array_count so the OUTER terminal is a valid number: the ONLY failure is the
        // rejected non-scope ref.
        $this->validator()->validateValuePipeline(
            $bag,
            [
                $this->op('array_filter', ['pipeline' => $this->union('element.price', 'number', [
                    $this->op('num_gt', ['value' => ['kind' => 'variable', 'ref' => ['source' => 'globals', 'path' => 'globals.foo', 'type' => 'number']]]),
                ])]),
                $this->op('array_count'),
            ],
            'field.pipeline',
            WorkflowVariableType::TEXT,
            [WorkflowVariableType::NUMBER],
            null,
            null,
            ['index' => ['globals.foo' => ['type' => WorkflowVariableType::NUMBER, 'enumOptions' => null]], 'fields_available' => true],
            0,
            null,
            $this->repeaterDescriptor(),
        );

        $this->assertFalse($bag->errors()->isEmpty());
    }

    public function test_a_scope_arg_variable_inside_a_scope_rooted_element_pipeline_still_validates(): void
    {
        $bag = $this->bag();

        // The scope-only inner refCtx must NOT break legitimate SCOPE refs: filter rooted at element.price
        // with an inner num_gt whose value is the scope element.price again — a scope arg-variable resolves
        // via $scopeVars (never the reference index), so it still validates.
        $this->validator()->validateValuePipeline(
            $bag,
            [
                $this->op('array_filter', ['pipeline' => $this->union('element.price', 'number', [
                    $this->op('num_gt', ['value' => ['kind' => 'variable', 'ref' => ['source' => 'scope', 'path' => 'element.price', 'type' => 'number']]]),
                ])]),
                $this->op('array_count'),
            ],
            'field.pipeline',
            WorkflowVariableType::TEXT,
            [WorkflowVariableType::NUMBER],
            null,
            null,
            ['index' => [], 'fields_available' => true],
            0,
            null,
            $this->repeaterDescriptor(),
        );

        $this->assertTrue($bag->errors()->isEmpty(), $bag->errors()->first());
    }

    public function test_a_repeater_map_to_the_bare_object_element_is_rejected(): void
    {
        $bag = $this->bag();

        // A map must terminate in a BASE SCALAR. A bare `element` union (the whole object, empty inner
        // pipeline) walks to a non-scalar `object` terminal → rejected, symmetric with the array reject.
        // Wrapped in |> array_count so the OUTER terminal is a valid number: the ONLY failure is the
        // non-scalar map terminal.
        $this->validator()->validateValuePipeline(
            $bag,
            [
                $this->op('array_map', ['pipeline' => $this->union('element', 'object')]),
                $this->op('array_count'),
            ],
            'field.pipeline',
            WorkflowVariableType::TEXT,
            [WorkflowVariableType::NUMBER],
            null,
            null,
            ['index' => [], 'fields_available' => true],
            0,
            null,
            $this->repeaterDescriptor(),
        );

        $this->assertFalse($bag->errors()->isEmpty());
    }

    public function test_an_element_subfield_ref_is_rejected_outside_an_element_pipeline(): void
    {
        $bag = $this->bag();

        // A scope element.<subfield> used as an ordinary op ARGUMENT (no enclosing element pipeline) is
        // fail-closed: the write-validator rejects it because $scopeVars is absent there.
        $this->validator()->validateValuePipeline(
            $bag,
            [$this->op('num_gt', ['value' => ['kind' => 'variable', 'ref' => ['source' => 'scope', 'path' => 'element.price', 'type' => 'number']]])],
            'field.pipeline',
            WorkflowVariableType::NUMBER,
            [WorkflowVariableType::BOOLEAN],
            null,
            null,
            ['index' => [], 'fields_available' => true],
            0,
        );

        $this->assertFalse($bag->errors()->isEmpty());
    }

    // ── structural-element-array membership backstop (adversarial review) ──────

    /** An array<file> source descriptor (multi-file) — a STRUCTURAL element with no option set. */
    private function fileArrayDescriptor(): array
    {
        return WorkflowVariableType::FILE->descriptor(array: true);
    }

    /** Walk a one-op pipeline over the given array source descriptor (source rides `multi`, no options). */
    private function walkArraySource(array $pipeline, array $descriptor): ValidatorContract
    {
        $bag = $this->bag();

        $this->validator()->validateValuePipeline(
            $bag,
            $pipeline,
            'field.pipeline',
            WorkflowVariableType::MULTI,
            [WorkflowVariableType::BOOLEAN, WorkflowVariableType::NUMBER],
            null, // a structural-element array carries NO source options
            null,
            ['index' => [], 'fields_available' => true],
            0,
            null,
            $descriptor,
        );

        return $bag;
    }

    public function test_a_membership_op_over_an_array_file_source_is_rejected_under_the_arg_key(): void
    {
        // array<file> reads as `multi` via fromDescriptor, so multi_excludes PASSES the op-accept gate — but
        // its element is a FILE (no option set), so a stored `multi_excludes ''` would `!in_array('', […],
        // true)` to TRUE forever (an always-open gate). The backstop rejects the option arg under its key.
        $bag = $this->walkArraySource([$this->op('multi_excludes', ['value' => ''])], $this->fileArrayDescriptor());

        $this->assertArrayHasKey('field.pipeline.0.args.value', $bag->errors()->toArray());
    }

    public function test_a_multi_includes_any_over_an_array_file_source_is_rejected_under_the_arg_key(): void
    {
        // The list-shaped membership op (sourceOptions) is likewise meaningless over a structural element.
        $bag = $this->walkArraySource([$this->op('multi_includes_any', ['values' => []])], $this->fileArrayDescriptor());

        $this->assertArrayHasKey('field.pipeline.0.args.values', $bag->errors()->toArray());
    }

    public function test_an_array_count_over_an_array_file_source_still_validates(): void
    {
        // The element-AGNOSTIC array_count carries no option arg — untouched by the backstop, so a multi-file
        // source stays countable (|> num_gt → boolean).
        $bag = $this->walkArraySource(
            [$this->op('array_count'), $this->op('num_gt', ['value' => 0])],
            $this->fileArrayDescriptor(),
        );

        $this->assertTrue($bag->errors()->isEmpty(), $bag->errors()->first());
    }

    public function test_a_membership_op_over_a_repeater_source_is_rejected_under_the_arg_key(): void
    {
        // A repeater (array<object>) is a structural element too. It also fails the op-accept type gate
        // (object ≠ multi), but the backstop runs FIRST so the option arg is flagged under its own key.
        $bag = $this->walkArraySource([$this->op('multi_excludes', ['value' => ''])], $this->repeaterDescriptor());

        $this->assertArrayHasKey('field.pipeline.0.args.value', $bag->errors()->toArray());
    }

    // ── array_at REQUIRED typed default (F4) ───────────────────────────────────

    /** A MULTI (array<enum>) source descriptor with the given option keys. */
    private function multiDescriptor(array $keys = ['fb', 'ig']): array
    {
        return WorkflowVariableType::MULTI->descriptor(
            array_map(fn (string $key): array => ['key' => $key, 'label' => $key], $keys),
        );
    }

    /** Validate a value pipeline over a MULTI (array<enum>) source, returning the error bag. */
    private function walkMultiPipeline(array $pipeline, array $allowedTerminals): ValidatorContract
    {
        $bag = $this->bag();

        $this->validator()->validateValuePipeline(
            $bag,
            $pipeline,
            'field.pipeline',
            WorkflowVariableType::MULTI,
            $allowedTerminals,
            ['fb', 'ig'],
            null,
            ['index' => [], 'fields_available' => true],
            0,
            null,
            $this->multiDescriptor(),
        );

        return $bag;
    }

    public function test_a_terminal_array_at_without_a_default_is_legal(): void
    {
        // array_at as the FINAL step is a legal NULLABLE terminal (the element base ENUM) — no default
        // required. The clamp/terminal behaviour is unchanged; only a FOLLOWING op forces a default.
        $bag = $this->walkMultiPipeline([$this->op('array_at', ['index' => 1])], [WorkflowVariableType::ENUM]);

        $this->assertTrue($bag->errors()->isEmpty(), $bag->errors()->first());
    }

    public function test_a_non_terminal_array_at_without_a_default_is_rejected(): void
    {
        // array_at |> enum_is: the following op consumes array_at's nullable element → a default is REQUIRED.
        $bag = $this->walkMultiPipeline(
            [$this->op('array_at', ['index' => 1]), $this->op('enum_is', ['value' => 'fb'])],
            [WorkflowVariableType::BOOLEAN],
        );

        $this->assertArrayHasKey('field.pipeline.0.args.default', $bag->errors()->toArray());
    }

    public function test_a_non_terminal_array_at_with_a_valid_enum_default_validates(): void
    {
        // A valid enum default (a member of the element option set) makes the non-terminal array_at legal.
        $bag = $this->walkMultiPipeline(
            [
                $this->op('array_at', ['index' => 1, 'default' => ['type' => 'enum', 'value' => 'fb']]),
                $this->op('enum_is', ['value' => 'ig']),
            ],
            [WorkflowVariableType::BOOLEAN],
        );

        $this->assertTrue($bag->errors()->isEmpty(), $bag->errors()->first());
    }

    public function test_an_array_at_default_of_the_wrong_type_is_rejected(): void
    {
        // The element base is ENUM; a `number`-typed default is a locked-type mismatch → a granular
        // `.default.type` error.
        $bag = $this->walkMultiPipeline(
            [
                $this->op('array_at', ['index' => 1, 'default' => ['type' => 'number', 'value' => 5]]),
                $this->op('enum_is', ['value' => 'fb']),
            ],
            [WorkflowVariableType::BOOLEAN],
        );

        $this->assertArrayHasKey('field.pipeline.0.args.default.type', $bag->errors()->toArray());
    }

    public function test_an_array_at_enum_default_outside_the_element_options_is_rejected(): void
    {
        // The default's VALUE must be a member of the element's option set (enum membership).
        $bag = $this->walkMultiPipeline(
            [
                $this->op('array_at', ['index' => 1, 'default' => ['type' => 'enum', 'value' => 'zzz']]),
                $this->op('enum_is', ['value' => 'fb']),
            ],
            [WorkflowVariableType::BOOLEAN],
        );

        $this->assertArrayHasKey('field.pipeline.0.args.default.value', $bag->errors()->toArray());
    }

    public function test_a_membership_op_over_a_real_enum_multi_still_validates(): void
    {
        // A checklist's element is an ENUM (an option set), NOT a structural container — the backstop never
        // fires and a valid option passes, so real multi-select conditions are untouched.
        $bag = $this->walkMultiPipeline([$this->op('multi_excludes', ['value' => 'fb'])], [WorkflowVariableType::BOOLEAN]);

        $this->assertTrue($bag->errors()->isEmpty(), $bag->errors()->first());
    }

    public function test_a_non_terminal_array_at_with_a_blank_text_default_is_rejected(): void
    {
        // `{type:'text', value:''}` is NOT an ENTERED default (an empty string is not a value — owner
        // directive), so the following op still consumes a nullable element → a default is REQUIRED. The
        // blank-value check fires BEFORE the type check, so the error lands under `.default` (not `.default.type`).
        $bag = $this->walkMultiPipeline(
            [
                $this->op('array_at', ['index' => 1, 'default' => ['type' => 'text', 'value' => '']]),
                $this->op('enum_is', ['value' => 'fb']),
            ],
            [WorkflowVariableType::BOOLEAN],
        );

        $this->assertArrayHasKey('field.pipeline.0.args.default', $bag->errors()->toArray());
    }

    public function test_a_blank_default_does_not_flip_array_at_output_non_nullable(): void
    {
        // The hasValidElementDefault twin of the write rule: a blank value keeps array_at's element NULLABLE
        // (no non-null flip), so the descriptor matches the write validator + executor's "no default"
        // treatment. An entered value flips it; number 0 / boolean false ARE values and flip it too.
        $enumArray = $this->multiDescriptor();

        $blank = WorkflowOperation::ARRAY_AT->outputDescriptor($enumArray, ['index' => 1, 'default' => ['type' => 'enum', 'value' => '']]);
        $this->assertTrue($blank['nullable']);

        $entered = WorkflowOperation::ARRAY_AT->outputDescriptor($enumArray, ['index' => 1, 'default' => ['type' => 'enum', 'value' => 'fb']]);
        $this->assertFalse($entered['nullable']);

        $zero = WorkflowOperation::ARRAY_AT->outputDescriptor(
            WorkflowVariableType::NUMBER->descriptor(array: true),
            ['index' => 1, 'default' => ['type' => 'number', 'value' => 0]],
        );
        $this->assertFalse($zero['nullable']);
    }

    /** A repeater whose element carries a MULTI subfield — so a map element pipeline can end in array_at. */
    private function repeaterWithMultiSubfield(): array
    {
        return WorkflowVariableType::OBJECT->descriptor(
            fields: [
                ['key' => 'tags', 'label' => 'Tags', 'descriptor' => WorkflowVariableType::MULTI->descriptor([
                    ['key' => 'x', 'label' => 'X'], ['key' => 'y', 'label' => 'Y'],
                ])],
            ],
            array: true,
        );
    }

    public function test_a_map_element_pipeline_ending_in_array_at_requires_a_default(): void
    {
        $bag = $this->bag();

        // map forces a NON-null element base, so a LAST-step array_at inside a map's (scope-rooted) element
        // pipeline is treated as non-terminal → a default is REQUIRED. Wrapped in |> array_count so the
        // outer terminal is a valid number; the ONLY failure is the missing element default.
        $this->validator()->validateValuePipeline(
            $bag,
            [
                $this->op('array_map', ['pipeline' => $this->union('element.tags', 'multi', [
                    $this->op('array_at', ['index' => 1]),
                ])]),
                $this->op('array_count'),
            ],
            'field.pipeline',
            WorkflowVariableType::TEXT,
            [WorkflowVariableType::NUMBER],
            null,
            null,
            ['index' => [], 'fields_available' => true],
            0,
            null,
            $this->repeaterWithMultiSubfield(),
        );

        $this->assertArrayHasKey('field.pipeline.0.args.pipeline.pipeline.0.args.default', $bag->errors()->toArray());
    }

    public function test_a_map_element_pipeline_ending_in_array_at_with_a_default_validates(): void
    {
        $bag = $this->bag();

        // The same map, with a valid enum default on the last-step array_at, validates.
        $this->validator()->validateValuePipeline(
            $bag,
            [
                $this->op('array_map', ['pipeline' => $this->union('element.tags', 'multi', [
                    $this->op('array_at', ['index' => 1, 'default' => ['type' => 'enum', 'value' => 'x']]),
                ])]),
                $this->op('array_count'),
            ],
            'field.pipeline',
            WorkflowVariableType::TEXT,
            [WorkflowVariableType::NUMBER],
            null,
            null,
            ['index' => [], 'fields_available' => true],
            0,
            null,
            $this->repeaterWithMultiSubfield(),
        );

        $this->assertTrue($bag->errors()->isEmpty(), $bag->errors()->first());
    }
}
