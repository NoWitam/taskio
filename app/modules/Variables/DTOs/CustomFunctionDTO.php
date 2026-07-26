<?php

namespace App\Modules\Variables\DTOs;

use App\Modules\Variables\Http\Requests\StoreCustomFunctionRequest;

/**
 * Carries a validated CUSTOM FUNCTION from the request into the service. The request has already
 * validated the signature (input/return types, typed args) and the body pipeline (over {input + args},
 * terminating in the return type, acyclic), so the DTO only shapes the persisted attributes.
 */
class CustomFunctionDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $inputType,
        /** @var array<int, array{name: string, description?: string|null, type: string}> */
        public readonly array $args,
        public readonly string $returnType,
        /** @var array<int, mixed> the body pipeline steps */
        public readonly array $body,
    ) {}

    public static function fromRequest(StoreCustomFunctionRequest $request): self
    {
        $description = $request->input('description');

        return new self(
            name: $request->string('name')->value(),
            description: is_string($description) ? $description : null,
            inputType: (string) $request->input('input_type'),
            args: $request->array('args'),
            returnType: (string) $request->input('return_type'),
            body: $request->array('body'),
        );
    }
}
