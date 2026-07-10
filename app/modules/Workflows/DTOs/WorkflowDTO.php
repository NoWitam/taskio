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
        /** @var array<int, array{field: string, field_type: string, operator: string, value?: mixed}> */
        public readonly array $conditions,
        /** @var array<int, array{type: string, key: string, config: array<string, mixed>}> */
        public readonly array $steps,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->string('name')->value(),
            description: $request->string('description')->value() ?: null,
            icon: $request->string('icon')->value() ?: null,
            triggerType: $request->enum('trigger_type', WorkflowTriggerType::class),
            triggerConfig: $request->array('trigger_config'),
            conditions: array_values($request->array('conditions')),
            steps: array_values($request->array('steps')),
        );
    }
}
