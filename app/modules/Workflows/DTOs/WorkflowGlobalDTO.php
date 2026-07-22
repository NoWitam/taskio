<?php

namespace App\Modules\Workflows\DTOs;

use App\Modules\Workflows\Http\Requests\StoreWorkflowGlobalRequest;

/**
 * Carries a validated workflow GLOBAL from the request into the service. The request has already
 * validated the descriptor is a well-formed type and the value matches it, and resolved the stable
 * `key` (explicit or slugged from the name), so the DTO only shapes the persisted attributes.
 *
 * `value` is intentionally typed `mixed` — a global literal may be a scalar, a list, an object, or
 * null (a nullable-typed global) — and rides the model's json cast unchanged.
 */
class WorkflowGlobalDTO
{
    public function __construct(
        public readonly string $name,
        public readonly string $key,
        /** @var array<string, mixed> the type descriptor */
        public readonly array $descriptor,
        public readonly mixed $value,
    ) {}

    public static function fromRequest(StoreWorkflowGlobalRequest $request): self
    {
        return new self(
            name: $request->string('name')->value(),
            key: $request->resolvedKey(),
            descriptor: $request->array('descriptor'),
            value: $request->input('value'),
        );
    }
}
