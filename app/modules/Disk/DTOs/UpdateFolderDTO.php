<?php

namespace App\Modules\Disk\DTOs;

use Illuminate\Http\Request;

/**
 * Metadata edits for a folder. Like {@see UpdateFileDTO}, every field is OPTIONAL and "absent"
 * is distinct from "null": absent leaves the value alone, null clears it (dropping a description
 * or an icon). Re-parenting is NOT here — it goes through the dedicated move endpoint.
 *
 * `labels` carries the folder's full GOVERNANCE set as `[{id, mode}]` (mode ∈ enforced|recommended);
 * the service syncs the `folder_label` pivot to exactly this set.
 */
class UpdateFolderDTO
{
    public function __construct(
        public readonly ?string $name,
        public readonly bool $hasName,
        public readonly ?string $description,
        public readonly bool $hasDescription,
        public readonly ?string $icon,
        public readonly bool $hasIcon,
        /** @var array<int, array{id: string, mode: string}>|null */
        public readonly ?array $labels,
        public readonly bool $hasLabels,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->has('name') ? $request->string('name')->trim()->value() : null,
            hasName: $request->has('name'),
            description: $request->filled('description') ? $request->string('description')->value() : null,
            hasDescription: $request->has('description'),
            icon: $request->filled('icon') ? $request->string('icon')->value() : null,
            hasIcon: $request->has('icon'),
            labels: $request->has('labels') ? array_map(
                fn (array $label) => ['id' => (string) $label['id'], 'mode' => (string) $label['mode']],
                $request->array('labels'),
            ) : null,
            hasLabels: $request->has('labels'),
        );
    }
}
