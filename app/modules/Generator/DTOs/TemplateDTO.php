<?php

namespace App\Modules\Generator\DTOs;

use App\Modules\Generator\Http\Requests\StoreTemplateRequest;

/**
 * Carries a validated TEMPLATE from the request into the service. The request has already validated the
 * identity + content_type, the DECLARED slots (name/description/descriptor via TemplateSlotValidator) and
 * the per-part `content` (directive references + pipelines + image/scene plans via TemplateContentValidator),
 * so the DTO only shapes the persisted attributes.
 */
class TemplateDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $contentType,
        /** @var array<int, array{name: string, description?: string|null, descriptor: array<string, mixed>}> */
        public readonly array $slots,
        /** @var array<string, mixed> the per-part authored content map, keyed by the type's part keys */
        public readonly array $content,
    ) {}

    public static function fromRequest(StoreTemplateRequest $request): self
    {
        $description = $request->input('description');

        return new self(
            name: $request->string('name')->value(),
            description: is_string($description) ? $description : null,
            contentType: (string) $request->input('content_type'),
            slots: $request->array('slots'),
            content: $request->array('content'),
        );
    }
}
