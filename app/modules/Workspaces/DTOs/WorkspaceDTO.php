<?php

namespace App\Modules\Workspaces\DTOs;

use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use Illuminate\Http\Request;

class WorkspaceDTO
{
    public function __construct(
        public readonly string $name,
        public readonly WorkspaceDbMode $dbMode,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->string('name')->toString(),
            dbMode: $request->enum('db_mode', WorkspaceDbMode::class) ?? WorkspaceDbMode::Shared,
        );
    }
}
