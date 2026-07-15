<?php

namespace App\Modules\Workflows\DTOs;

use App\Modules\Workflows\Enums\WorkflowOperationArgType;
use App\Modules\Workflows\Enums\WorkflowVariableType;

/**
 * One argument descriptor of a WorkflowOperation: an id, its control type, and — for a
 * sourceMap arg only — the value type each option maps to (mapType ∈ text|number|date).
 *
 * This is the backend mirror of the FE `VariableOperationArgumentDefinition` MINUS the i18n
 * labels/placeholders/defaults (the FE owns those). It is the single shape both the write
 * validator and the runtime engine read to enforce an argument's presence and value shape, and
 * the shape the workflow-catalog endpoint serializes (toArray) so the FE can render the builder.
 */
final class WorkflowOperationArg
{
    public function __construct(
        public readonly string $id,
        public readonly WorkflowOperationArgType $type,
        /** Only set for a sourceMap arg: the value type each option maps to. */
        public readonly ?WorkflowVariableType $mapType = null,
    ) {}

    /** A number/text/date/boolean literal arg. */
    public static function literal(string $id, WorkflowOperationArgType $type): self
    {
        return new self($id, $type);
    }

    /** A sourceMap arg mapping each source option to a $mapType value. */
    public static function map(string $id, WorkflowVariableType $mapType): self
    {
        return new self($id, WorkflowOperationArgType::SOURCE_MAP, $mapType);
    }

    /**
     * A choiceRules arg: an ordered list of {when, then} match rules. The `then` option set is the
     * DESTINATION field's options, injected per-field at validation/render time (no static mapType).
     */
    public static function choiceRules(string $id): self
    {
        return new self($id, WorkflowOperationArgType::CHOICE_RULES);
    }

    /**
     * A choiceFallback arg: one value of the DESTINATION field's option set (required for totality).
     * The option set is injected per-field at validation/render time (no static mapType).
     */
    public static function choiceFallback(string $id): self
    {
        return new self($id, WorkflowOperationArgType::CHOICE_FALLBACK);
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
