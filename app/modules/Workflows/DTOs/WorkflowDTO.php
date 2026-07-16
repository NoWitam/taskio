<?php

namespace App\Modules\Workflows\DTOs;

use App\Modules\Workflows\Enums\WorkflowTriggerType;
use Illuminate\Http\Request;

/**
 * Carries a validated workflow DEFINITION from the request into the service.
 *
 * Status is intentionally ABSENT: a workflow is created inactive and toggled only
 * through the dedicated status endpoint, never via the create/update body.
 *
 * trigger_config / conditions / steps arrive already validated per trigger_type by the
 * FormRequest, so the DTO only cleans up shape (missing arrays become []) and normalizes
 * the trigger_type into its enum.
 *
 * conditions is polymorphic: EITHER the legacy flat clause LIST (reindexed) OR the new logic
 * TREE object ({logic, children}). A list is reindexed with array_values; the tree's associative
 * shape is preserved verbatim so it round-trips through the json-cast column unchanged.
 */
class WorkflowDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?string $icon,
        public readonly WorkflowTriggerType $triggerType,
        /** @var array<string, mixed> */
        public readonly array $triggerConfig,
        /** @var array<int|string, mixed> a legacy clause list OR a logic tree */
        public readonly array $conditions,
        /** @var array<int, array{type: string, key: string, config: array<string, mixed>}> */
        public readonly array $steps,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $conditions = $request->array('conditions');

        return new self(
            name: $request->string('name')->value(),
            description: $request->string('description')->value() ?: null,
            icon: $request->string('icon')->value() ?: null,
            triggerType: $request->enum('trigger_type', WorkflowTriggerType::class),
            triggerConfig: $request->array('trigger_config'),
            // Reindex a clause LIST; preserve the associative TREE object as-is.
            conditions: array_is_list($conditions) ? array_values($conditions) : $conditions,
            steps: array_values($request->array('steps')),
        );
    }
}
