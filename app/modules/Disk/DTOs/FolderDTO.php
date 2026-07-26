<?php

namespace App\Modules\Disk\DTOs;

use Illuminate\Http\Request;

/**
 * Carries a validated folder definition from the request into the service.
 *
 * `path` is intentionally ABSENT: it is derived from the parent, never supplied by a client —
 * accepting it would let a caller forge an ancestry.
 */
class FolderDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $parentId,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->string('name')->trim()->value(),
            parentId: $request->string('parent_id')->value() ?: null,
        );
    }
}
