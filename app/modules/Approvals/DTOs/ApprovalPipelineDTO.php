<?php

namespace App\Modules\Approvals\DTOs;

use Illuminate\Http\Request;

class ApprovalPipelineDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $icon,
        public readonly ?string $description,
        public readonly array $stages,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->string('name'),
            icon: $request->string('icon')->value() ?: null,
            description: $request->string('description')->value() ?: null,
            stages: $request->array('stages'),
        );
    }
}
