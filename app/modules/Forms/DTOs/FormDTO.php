<?php

namespace App\Modules\Forms\DTOs;

use App\Enums\IconEnum;
use Illuminate\Http\Request;

class FormDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?IconEnum $icon,
        public readonly ?string $description,
        public readonly array $content,
        public readonly bool $is_anonymous
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->string('name'),
            icon: $request->enum('icon', IconEnum::class),
            description: $request->string('description'),
            content: $request->array('content'),
            is_anonymous: $request->boolean('is_anonymous', false)
        );
    }
}
