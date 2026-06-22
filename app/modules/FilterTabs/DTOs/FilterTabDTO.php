<?php

namespace App\Modules\FilterTabs\DTOs;

use App\Enums\IconEnum;
use Illuminate\Http\Request;

class FilterTabDTO
{
    public function __construct(
        public readonly ?string $userId,
        public readonly ?string $context,
        public readonly ?string $name,
        public readonly ?IconEnum $icon,
        public readonly ?array $filters,
        public readonly bool $hasIcon = false,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            userId: auth()->id(),
            context: $request->string('context')->toString() ?: null,
            name: $request->has('name') ? $request->string('name')->toString() : null,
            icon: $request->enum('icon', IconEnum::class),
            filters: $request->has('filters') ? $request->array('filters') : null,
            hasIcon: $request->has('icon'),
        );
    }
}
