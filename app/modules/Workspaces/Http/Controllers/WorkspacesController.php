<?php

namespace App\Modules\Workspaces\Http\Controllers;

use App\Models\User;
use App\Modules\Workspaces\DTOs\WorkspaceDTO;
use App\Modules\Workspaces\Http\Requests\StoreWorkspaceRequest;
use App\Modules\Workspaces\Http\Requests\UpdateWorkspaceRequest;
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
            $this->service->rename($workspace, $request->string('name')->toString())
        );
    }

    public function addMember(Request $request, Workspace $workspace)
    {
        abort_unless($request->user()->can('manageMembers', $workspace), 403);

        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
        ]);

        $this->service->addMember($workspace, User::findOrFail($data['user_id']));

        return WorkspaceResource::make($workspace->fresh());
    }

    public function removeMember(Request $request, Workspace $workspace, User $user)
    {
        abort_unless($request->user()->can('manageMembers', $workspace), 403);

        $this->service->removeMember($workspace, $user);

        return response()->noContent();
    }
}
