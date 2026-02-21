<?php

namespace App\Modules\Labels\DTOs;

use App\Enums\IconEnum;
use Illuminate\Http\Request;

class LabelDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?IconEnum $icon,
        public readonly ?string $color
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->string('name')->toString(),
            description: $request->string('description')->toString() ?: null,
            icon: $request->enum('icon', IconEnum::class),
            color: $request->string('color')->toString() ?: null
        );
    }
}