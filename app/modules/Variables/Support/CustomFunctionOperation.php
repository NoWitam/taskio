<?php

namespace App\Modules\Variables\Support;

use App\Modules\Variables\Contracts\OperationDefinition;
use App\Modules\Variables\DTOs\OperationArg;
use App\Modules\Variables\Enums\OperationArgType;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Models\CustomFunction;

/**
 * A user CUSTOM FUNCTION seen as a pipeline OPERATION — the {@see OperationDefinition} view of a
 * {@see CustomFunction} row, so the pipeline walk can type-check a `fn:<uuid>` step exactly like a
 * built-in op. Its id is the reserved `fn:<uuid>` (guaranteeing no collision with a built-in op id
 * or the `globals` wire root); its input/output/args are the function's declared definition; it is
 * NONE of the special op kinds (a function produces no choice, is not an array/collection/presence
 * op), so it flows through the walker's plain-op path and its terminal is just its RETURN type.
 *
 * Phase 3a validates a function body that references ANOTHER function (nesting). Phase 3b adds the
 * RUNTIME view: this VO now also carries the function's BODY pipeline and its arg name→type map, so
 * the OperationExecutor can EXECUTE a `fn:<uuid>` op BY EXPANSION (bind {input + args} into a scope
 * frame, re-enter the executor on the body). body()/argTypes() are runtime-only concerns kept OFF the
 * {@see OperationDefinition} interface (a built-in op has neither) — the executor reads them after
 * discriminating on the concrete type.
 */
final class CustomFunctionOperation implements OperationDefinition
{
    /**
     * @param  array<int, OperationArg>  $args  the walk-facing arg descriptors (control-typed)
     * @param  array<int, mixed>  $body  the body pipeline the executor re-enters (Phase 3b)
     * @param  array<string, VariableType>  $argTypes  arg name → declared value type (frame binding, Phase 3b)
     */
    public function __construct(
        private string $uuid,
        private VariableType $inputType,
        private VariableType $returnType,
        private array $args,
        private array $body = [],
        private array $argTypes = [],
    ) {}

    /**
     * Build the operation view from a stored function row. Defensive against a malformed type (the
     * write-validator guarantees valid types, so the fallback is never hit in practice): an unknown
     * base collapses to text — fail-soft, never a crash. The BODY + arg name→type map are captured for
     * the runtime executor (Phase 3b); a malformed args entry is skipped from BOTH descriptors + types.
     */
    public static function fromModel(CustomFunction $function): self
    {
        $args = [];
        $argTypes = [];

        foreach (is_array($function->args) ? $function->args : [] as $arg) {
            if (!is_array($arg) || !is_string($arg['name'] ?? null)) {
                continue;
            }

            $type = VariableType::tryFrom((string) ($arg['type'] ?? '')) ?? VariableType::TEXT;
            $args[] = OperationArg::literal($arg['name'], self::argControl($type));
            $argTypes[$arg['name']] = $type;
        }

        return new self(
            (string) $function->getKey(),
            VariableType::tryFrom((string) $function->input_type) ?? VariableType::TEXT,
            VariableType::tryFrom((string) $function->return_type) ?? VariableType::TEXT,
            $args,
            is_array($function->body) ? $function->body : [],
            $argTypes,
        );
    }

    public function id(): string
    {
        return 'fn:' . $this->uuid;
    }

    /**
     * The BODY pipeline the executor re-enters over {input + args} (Phase 3b). The terminal value is the
     * op's output (coerced to the return type); [] is the identity function (valid only when input == return).
     *
     * @return array<int, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    /**
     * The declared value type of each named arg (Phase 3b) — the type the executor stamps on the scope
     * frame when it binds the (pre-resolved literal) arg values, so a body reads each arg at its real type.
     *
     * @return array<string, VariableType>
     */
    public function argTypes(): array
    {
        return $this->argTypes;
    }

    public function isCustom(): bool
    {
        return true;
    }

    public function inputType(): VariableType
    {
        return $this->inputType;
    }

    public function outputType(): VariableType
    {
        return $this->returnType;
    }

    /** @return array<int, OperationArg> */
    public function argDescriptors(): array
    {
        return $this->args;
    }

    public function producesChoice(): bool
    {
        return false;
    }

    public function isArrayOp(): bool
    {
        return false;
    }

    public function isCollectionOp(): bool
    {
        return false;
    }

    public function isPresenceOp(): bool
    {
        return false;
    }

    /**
     * A function's terminal is simply its declared RETURN type's descriptor — it consumes its input
     * and yields its return, independent of the running descriptor (unlike an array op).
     *
     * @param  array<string, mixed>  $inputDescriptor
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>|null  $terminalDescriptor
     * @return array<string, mixed>
     */
    public function outputDescriptor(array $inputDescriptor, array $args = [], ?array $terminalDescriptor = null): array
    {
        return $this->returnType->descriptor();
    }

    /**
     * The CALL-SITE control a function arg exposes, by its value type. The four base scalars map to
     * their literal controls; every other type (enum/multi/file/object/time) takes a stringifiable
     * text control for now — a function is only referenceable from another function's body in this
     * phase, and richer per-type call semantics arrive with runtime surfacing (Phase 3b).
     */
    private static function argControl(VariableType $type): OperationArgType
    {
        return match ($type) {
            VariableType::NUMBER => OperationArgType::NUMBER,
            VariableType::BOOLEAN => OperationArgType::BOOLEAN,
            VariableType::DATE => OperationArgType::DATE,
            default => OperationArgType::TEXT,
        };
    }
}
