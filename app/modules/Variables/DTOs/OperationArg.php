<?php

namespace App\Modules\Variables\DTOs;

use App\Modules\Variables\Enums\OperationArgType;
use App\Modules\Variables\Enums\VariableType;

/**
 * One argument descriptor of a Operation: an id, its control type, and — for a
 * sourceMap arg only — the value type each option maps to (mapType ∈ text|number|date).
 *
 * This is the backend mirror of the FE `VariableOperationArgumentDefinition` MINUS the i18n
 * labels/placeholders/defaults (the FE owns those). It is the single shape both the write
 * validator and the runtime engine read to enforce an argument's presence and value shape, and
 * the shape the workflow-catalog endpoint serializes (toArray) so the FE can render the builder.
 */
final class OperationArg
{
    public function __construct(
        public readonly string $id,
        public readonly OperationArgType $type,
        /** Only set for a sourceMap arg: the value type each option maps to. */
        public readonly ?VariableType $mapType = null,
    ) {}

    /** A number/text/date/boolean literal arg. */
    public static function literal(string $id, OperationArgType $type): self
    {
        return new self($id, $type);
    }

    /** A sourceMap arg mapping each source option to a $mapType value. */
    public static function map(string $id, VariableType $mapType): self
    {
        return new self($id, OperationArgType::SOURCE_MAP, $mapType);
    }

    /**
     * A choiceRules arg: an ordered list of {when, then} match rules. The `then` option set is the
     * DESTINATION field's options, injected per-field at validation/render time (no static mapType).
     */
    public static function choiceRules(string $id): self
    {
        return new self($id, OperationArgType::CHOICE_RULES);
    }

    /**
     * A choiceFallback arg: one value of the DESTINATION field's option set (required for totality).
     * The option set is injected per-field at validation/render time (no static mapType).
     */
    public static function choiceFallback(string $id): self
    {
        return new self($id, OperationArgType::CHOICE_FALLBACK);
    }

    /**
     * A per-element PIPELINE arg (array-ops wave 2, map/filter/sort/reduce): a list of pipeline steps
     * rooted at the array's ELEMENT descriptor, terminal-gated per op. No mapType — its typing comes from
     * the enclosing op + the element descriptor, injected at validation time.
     */
    public static function elementPipeline(string $id): self
    {
        return new self($id, OperationArgType::ELEMENT_PIPELINE);
    }

    /**
     * A reduce SEED arg (array-ops wave 2): a self-describing typed literal `{type, value}` — the reducer
     * accumulator's initial value and the base type its pipeline must terminate in.
     */
    public static function reduceSeed(string $id): self
    {
        return new self($id, OperationArgType::REDUCE_SEED);
    }

    /**
     * An element DEFAULT arg (array-ops F4, array_at): a self-describing typed literal `{type, value}` whose
     * type is LOCKED to the array's element base — substituted for array_at's nullable element before a
     * following op consumes it. No mapType — the element base is injected at validation time from the running
     * array descriptor.
     */
    public static function elementDefault(string $id): self
    {
        return new self($id, OperationArgType::ELEMENT_DEFAULT);
    }

    /**
     * The label-less descriptor the catalog endpoint exposes: {id, type, mapType?}.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $descriptor = ['id' => $this->id, 'type' => $this->type->value];

        if ($this->mapType !== null) {
            $descriptor['mapType'] = $this->mapType->value;
        }

        return $descriptor;
    }
}
