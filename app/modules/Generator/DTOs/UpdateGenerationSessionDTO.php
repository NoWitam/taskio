<?php

namespace App\Modules\Generator\DTOs;

use App\Modules\Generator\Http\Requests\UpdateGenerationSessionRequest;

/**
 * Carries a validated session EDIT (name / filled slot values) into the service. Only the two
 * user-mutable inputs — the recipe snapshot is immutable, and the results/status are engine-owned. A
 * field the request did not send stays null so the service leaves the stored value untouched.
 */
class UpdateGenerationSessionDTO
{
    public function __construct(
        public readonly ?string $name,
        /** @var array<string, mixed>|null the filled slot inputs (null = not sent → unchanged) */
        public readonly ?array $slotValues,
    ) {}

    public static function fromRequest(UpdateGenerationSessionRequest $request): self
    {
        $name = $request->input('name');

        return new self(
            name: is_string($name) ? $name : null,
            slotValues: $request->has('slot_values') ? $request->array('slot_values') : null,
        );
    }
}
