<?php

namespace App\Modules\Variables\Services;

use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Models\CustomFunction;
use App\Modules\Variables\Support\CustomFunctionOperation;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Collection;

/**
 * The single, reusable authority for a CUSTOM FUNCTION's DEFINITION — the write-time twin of
 * ConstantTypeValidator, appending granular errors to the request Validator (input_type / return_type
 * / args.* / body*). Centralized here (not hand-rolled in the FormRequest) so the CRUD write path is
 * the ONE place a function's shape is validated and it can never drift from what the engine understands.
 *
 * A function definition is: one INPUT type, a list of typed named ARGS, one RETURN type, and a BODY
 * pipeline over {input + args} terminating in the return type. This validator enforces, in order:
 *   - input_type / return_type ∈ VariableType.
 *   - each arg {name, description?, type}: a UNIQUE, non-RESERVED (input/element/index), safe-identifier
 *     name and a type ∈ VariableType.
 *   - the BODY via PipelineValidator::validateFunctionBody over the scope frame {input, <argName>…},
 *     with a SCOPE-ONLY reference index (a globals/trigger/steps ref inside a body is rejected) and the
 *     workspace's OTHER functions available for nested `fn:<uuid>` references.
 *   - ACYCLICITY: the function reference graph (edge A→B iff A's body references fn:B), INCLUDING the
 *     pending create/update, must be acyclic — a self-reference or any cycle is rejected under `body`.
 *
 * Functions are NOT surfaced in the workflow catalog nor executed in this phase (Phase 3b) — the only
 * caller of a function is another function's body, so nesting is the only reachability, and the cycle
 * guard + the fail-closed OperationResolver keep it bounded.
 */
class FunctionDefinitionValidator
{
    /**
     * The scope roots a function body already owns — an arg may not shadow them. `input` is the body's
     * implicit first frame; `element`/`index` are the array-element scope a nested map/filter injects.
     *
     * @var array<int, string>
     */
    private const RESERVED_ARG_NAMES = ['input', 'element', 'index'];

    /** A safe arg identifier — a scope path segment read like a variable path (Arr::get-safe). */
    private const SAFE_NAME = '/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/';

    public function __construct(
        private PipelineValidator $pipeline,
    ) {}

    /**
     * Validate a pending function definition, appending errors to $validator. $workspaceFunctions is
     * EVERY function in the active workspace (on update it includes the pending row itself, keyed by
     * $pendingId; on create $pendingId is null). It seeds BOTH the nested-reference resolution (the walk)
     * and the cycle graph.
     *
     * @param  Collection<int, CustomFunction>  $workspaceFunctions
     */
    public function validate(
        Validator $validator,
        mixed $inputType,
        mixed $args,
        mixed $returnType,
        mixed $body,
        Collection $workspaceFunctions,
        ?string $pendingId,
    ): void {
        $input = is_string($inputType) ? VariableType::tryFrom($inputType) : null;

        if ($input === null) {
            $validator->errors()->add('input_type', 'The input type must be a valid variable type.');
        }

        $return = is_string($returnType) ? VariableType::tryFrom($returnType) : null;

        if ($return === null) {
            $validator->errors()->add('return_type', 'The return type must be a valid variable type.');
        }

        $argScope = $this->validateArgs($validator, $args);

        // Cycle detection depends ONLY on the referenced ids, so it runs regardless of any type/body
        // error — a self-referencing or cyclic definition is always caught.
        $this->validateAcyclic($validator, $body, $workspaceFunctions, $pendingId);

        if (!is_array($body)) {
            $validator->errors()->add('body', 'A function body pipeline is required.');

            return;
        }

        // The body can only be type-flowed once its input/return/args are well-formed.
        if ($input === null || $return === null || $argScope === null) {
            return;
        }

        // The scope FRAME the body reads: the original input + each named arg. `input` is always present;
        // the args were charset / uniqueness / reserved-name checked above.
        $scopeVars = ['input' => ['type' => $input, 'enumOptions' => null]] + $argScope;

        $this->pipeline->validateFunctionBody(
            $validator,
            $body,
            'body',
            $input,
            $return,
            $scopeVars,
            $this->walkFunctions($workspaceFunctions),
        );
    }

    /**
     * The distinct function uuids a body references — every `op` / `operationId` value carrying the
     * reserved `fn:<uuid>` prefix, collected at ANY nesting depth (element pipelines, arg-variable
     * sub-pipelines, choice-rule `when`s, reducers). A literal string value never sits under an op key,
     * so only real operation ids are collected. Public so the delete guard reads the SAME edge set the
     * cycle graph does.
     *
     * @return array<int, string>
     */
    public static function referencedFunctionIds(mixed $body): array
    {
        $ids = [];
        self::collectReferences($body, $ids);

        return array_values(array_unique($ids));
    }

    /**
     * Validate the args list and return the scope frame {name: {type, enumOptions}} it contributes, or
     * null when args is not a list (the body then cannot be scoped). Per-arg errors land under args.<i>.*;
     * a malformed arg is skipped from the frame but never aborts the others.
     *
     * @return array<string, array{type: VariableType, enumOptions: null}>|null
     */
    private function validateArgs(Validator $validator, mixed $args): ?array
    {
        if (!is_array($args) || !array_is_list($args)) {
            $validator->errors()->add('args', 'The args must be a list of { name, type } definitions.');

            return null;
        }

        $scope = [];
        $seen = [];

        foreach ($args as $i => $arg) {
            $key = 'args.' . $i;

            if (!is_array($arg)) {
                $validator->errors()->add($key, 'Each arg must be an object.');

                continue;
            }

            $type = is_string($arg['type'] ?? null) ? VariableType::tryFrom($arg['type']) : null;

            if ($type === null) {
                $validator->errors()->add($key . '.type', 'The arg type must be a valid variable type.');
            }

            if (array_key_exists('description', $arg) && $arg['description'] !== null && !is_string($arg['description'])) {
                $validator->errors()->add($key . '.description', 'The arg description must be text.');
            }

            $name = $arg['name'] ?? null;

            if (!is_string($name) || preg_match(self::SAFE_NAME, $name) !== 1) {
                $validator->errors()->add($key . '.name', 'The arg name must be a valid identifier (letters, digits and underscores, not starting with a digit).');

                continue;
            }

            if (in_array($name, self::RESERVED_ARG_NAMES, true)) {
                $validator->errors()->add($key . '.name', 'The arg name may not be a reserved scope name (' . implode(', ', self::RESERVED_ARG_NAMES) . ').');

                continue;
            }

            if (in_array($name, $seen, true)) {
                $validator->errors()->add($key . '.name', 'The arg names must be distinct.');

                continue;
            }

            $seen[] = $name;

            if ($type !== null) {
                $scope[$name] = ['type' => $type, 'enumOptions' => null];
            }
        }

        return $scope;
    }

    /**
     * Reject a self-reference or ANY cycle in the function reference graph (edge A→B iff A's body
     * references fn:B). The graph is built from every workspace function's body, with the PENDING body
     * substituted for its node — keyed by $pendingId on update, or a synthetic source node on create
     * (which nothing else can reference, so a create can never introduce a cycle). Reported under `body`.
     *
     * @param  Collection<int, CustomFunction>  $workspaceFunctions
     */
    private function validateAcyclic(Validator $validator, mixed $body, Collection $workspaceFunctions, ?string $pendingId): void
    {
        $edges = [];

        foreach ($workspaceFunctions as $function) {
            // The pending row's stored (old) body is replaced by the definition under validation below.
            if ((string) $function->getKey() === (string) $pendingId) {
                continue;
            }

            $edges[(string) $function->getKey()] = self::referencedFunctionIds($function->body);
        }

        $edges[$pendingId ?? '__pending__'] = self::referencedFunctionIds($body);

        if ($this->hasCycle($edges)) {
            $validator->errors()->add('body', 'A function may not reference itself, directly or through another function (a cycle was detected).');
        }
    }

    /**
     * The custom-function operations available for a body's nested references — every workspace function
     * as a CustomFunctionOperation. A self-reference resolves here (against the current row) and is then
     * rejected by validateAcyclic; a reference to any other function resolves to its current definition.
     *
     * @param  Collection<int, CustomFunction>  $workspaceFunctions
     * @return array<int, CustomFunctionOperation>
     */
    private function walkFunctions(Collection $workspaceFunctions): array
    {
        return $workspaceFunctions
            ->map(fn (CustomFunction $function): CustomFunctionOperation => CustomFunctionOperation::fromModel($function))
            ->all();
    }

    /**
     * Whether the directed graph $edges (node => referenced nodes) contains ANY cycle, via a
     * three-colour DFS (unvisited / visiting / done). A back-edge to a VISITING node — including a
     * node's edge to itself — is a cycle.
     *
     * @param  array<string, array<int, string>>  $edges
     */
    private function hasCycle(array $edges): bool
    {
        $state = [];

        foreach (array_keys($edges) as $node) {
            if (($state[$node] ?? 0) === 0 && $this->dfsHasCycle($node, $edges, $state)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array<int, string>>  $edges
     * @param  array<string, int>  $state  0 = unvisited, 1 = visiting, 2 = done
     */
    private function dfsHasCycle(string $node, array $edges, array &$state): bool
    {
        $state[$node] = 1;

        foreach ($edges[$node] ?? [] as $next) {
            $colour = $state[$next] ?? 0;

            if ($colour === 1) {
                return true; // back-edge (or self-edge) → cycle
            }

            if ($colour === 0 && $this->dfsHasCycle($next, $edges, $state)) {
                return true;
            }
        }

        $state[$node] = 2;

        return false;
    }

    /**
     * @param  array<int, string>  $ids
     */
    private static function collectReferences(mixed $node, array &$ids): void
    {
        if (!is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if (($key === 'op' || $key === 'operationId') && is_string($value) && str_starts_with($value, 'fn:')) {
                $ids[] = substr($value, 3);

                continue;
            }

            self::collectReferences($value, $ids);
        }
    }
}
