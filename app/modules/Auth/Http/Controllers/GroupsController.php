<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Requests\StoreGroupRequest;
use App\Modules\Auth\Http\Requests\UpdateGroupRequest;
use App\Modules\Auth\Http\Resources\GroupResource;
use App\Modules\Auth\Models\Group;
use App\Modules\Auth\Services\GroupService;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GroupsController
{
    public function __construct(
        private GroupService $service
    ) {}

    public function index(Request $request, Workspace $workspace)
    {
        abort_unless((bool) $request->user()?->can('manageGroups', $workspace), Response::HTTP_FORBIDDEN);

        return GroupResource::collection($this->service->forWorkspace($workspace));
    }

    public function store(StoreGroupRequest $request, Workspace $workspace)
    {
        $group = $this->service->create(
            $workspace,
            $request->string('name')->toString(),
            $request->array('user_ids'),
            $request->array('permissions'),
        );

        return GroupResource::make($group)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateGroupRequest $request, Workspace $workspace, Group $group)
    {
        $this->ensureGroupBelongsToWorkspace($workspace, $group);

        $group = $this->service->update(
            $group,
            $request->string('name')->toString(),
            $request->array('user_ids'),
            $request->array('permissions'),
        );

        return GroupResource::make($group);
    }

    public function destroy(Request $request, Workspace $workspace, Group $group)
    {
        abort_unless((bool) $request->user()?->can('manageGroups', $workspace), Response::HTTP_FORBIDDEN);
        $this->ensureGroupBelongsToWorkspace($workspace, $group);

        $this->service->delete($group);

        return response()->noContent();
    }

    private function ensureGroupBelongsToWorkspace(Workspace $workspace, Group $group): void
    {
        abort_unless($group->workspace_id === $workspace->id, Response::HTTP_NOT_FOUND);
    }
}
