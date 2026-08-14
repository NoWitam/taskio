<?php

namespace App\Modules\Workspaces\Http\Controllers;

use App\Models\User;
use App\Modules\Workspaces\DTOs\WorkspaceDTO;
use App\Modules\Workspaces\DTOs\WorkspaceSettingsDTO;
use App\Modules\Workspaces\Http\Requests\StoreWorkspaceRequest;
use App\Modules\Workspaces\Http\Requests\UpdateWorkspaceRequest;
use App\Modules\Workspaces\Http\Resources\WorkspaceMemberResource;
use App\Modules\Workspaces\Http\Resources\WorkspaceResource;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\WorkspaceService;
use Illuminate\Http\Request;

class WorkspacesController
{
    public function __construct(
        private WorkspaceService $service
    ) {}

    public function index(Request $request)
    {
        return WorkspaceResource::collection(
            $this->service->forUser($request->user())
        );
    }

    public function store(StoreWorkspaceRequest $request)
    {
        $workspace = $this->service->create(
            WorkspaceDTO::fromRequest($request),
            $request->user()
        );

        return WorkspaceResource::make($workspace)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Workspace $workspace)
    {
        abort_unless($request->user()->can('view', $workspace), 403);

        return WorkspaceResource::make($workspace);
    }

    public function update(UpdateWorkspaceRequest $request, Workspace $workspace)
    {
        return WorkspaceResource::make(
            $this->service->updateSettings($workspace, WorkspaceSettingsDTO::fromRequest($request))
        );
    }

    public function members(Request $request, Workspace $workspace)
    {
        abort_unless($request->user()->can('view', $workspace), 403);

        return WorkspaceMemberResource::collection(
            $this->service->members($workspace)
                ->map(fn (User $user) => new WorkspaceMemberResource($user, $workspace))
        );
    }

    public function addMember(Request $request, Workspace $workspace)
    {
        abort_unless($request->user()->can('manageMembers', $workspace), 403);

        $data = $request->validate([
            // Kept raw on purpose: adding an existing, NOT-yet-member user is the
            // whole point, so the candidate is intentionally outside the active
            // workspace's membership.
            'user_id' => ['required', 'string', 'exists:users,id'],
        ]);

        // Bypass the member scope: the user being added is not a member of the
        // active workspace yet, so the scope would 404 a perfectly valid add.
        $user = User::withoutWorkspaceMemberScope()->findOrFail($data['user_id']);

        $this->service->addMember($workspace, $user);

        return WorkspaceResource::make($workspace->fresh());
    }

    public function removeMember(Request $request, Workspace $workspace, User $user)
    {
        abort_unless($request->user()->can('manageMembers', $workspace), 403);

        $this->service->removeMember($workspace, $user);

        return response()->noContent();
    }
}
