<?php

namespace App\Modules\Disk\DTOs;

use Illuminate\Http\Request;

/**
 * Metadata edits for a disk file. Every field is OPTIONAL and "absent" is distinct from
 * "null": absent leaves the value alone, null clears it (moving a file to the root, dropping
 * a description). The service reads the presence flags, never `?? null`.
 */
class UpdateFileDTO
{
    public function __construct(
        public readonly ?string $name,
        public readonly bool $hasName,
        public readonly ?string $description,
        public readonly bool $hasDescription,
        public readonly ?string $folderId,
        public readonly bool $hasFolderId,
        /** @var array<int, string>|null */
        public readonly ?array $labelIds,
        public readonly bool $hasLabels,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->has('name') ? $request->string('name')->trim()->value() : null,
            hasName: $request->has('name'),
            description: $request->filled('description') ? $request->string('description')->value() : null,
            hasDescription: $request->has('description'),
            folderId: $request->filled('folder_id') ? $request->string('folder_id')->value() : null,
            hasFolderId: $request->has('folder_id'),
            labelIds: $request->has('labels') ? array_values($request->array('labels')) : null,
            hasLabels: $request->has('labels'),
        );
    }
}
