<?php

namespace App\Modules\Variables\DTOs;

use App\Modules\Variables\Http\Requests\StoreConstantRequest;

/**
 * Carries a validated CONSTANT from the request into the service. The request has already
 * validated the descriptor is a well-formed type and the value matches it, and resolved the stable
 * `key` (explicit or slugged from the name), so the DTO only shapes the persisted attributes.
 *
 * `value` is intentionally typed `mixed` — a constant literal may be a scalar, a list, an object, or
 * null (a nullable-typed constant) — and rides the model's json cast unchanged.
 */
class ConstantDTO
{
    public function __construct(
        public readonly string $name,
        public readonly string $key,
        /** @var array<string, mixed> the type descriptor */
        public readonly array $descriptor,
        public readonly mixed $value,
    ) {}

    public static function fromRequest(StoreConstantRequest $request): self
    {
        return new self(
            name: $request->string('name')->value(),
            key: $request->resolvedKey(),
            descriptor: $request->array('descriptor'),
            value: $request->input('value'),
        );
    }
}
