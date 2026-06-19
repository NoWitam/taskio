<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Workspaces\DTOs\WorkspaceDTO;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Services\WorkspaceService;
use Illuminate\Database\Seeder;

class WorkspaceSeeder extends Seeder
{
    public function __construct(
        private WorkspaceService $workspaceService,
    ) {}

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all();

        if ($users->isEmpty()) {
            return;
        }

        $owner = $users->first();

        $workspace = $this->workspaceService->create(
            new WorkspaceDTO(name: 'Default Workspace', dbMode: WorkspaceDbMode::Shared),
            $owner,
        );

        $remaining = $users->skip(1);

        foreach ($remaining as $user) {
            $this->workspaceService->addMember($workspace, $user);
        }
    }
}
