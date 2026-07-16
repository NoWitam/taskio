<?php

namespace App\Modules\Workspaces\Http\Controllers;

use App\Modules\Workspaces\DTOs\AcceptInvitationDTO;
use App\Modules\Workspaces\Http\Requests\AcceptInvitationRequest;
use App\Modules\Workspaces\Http\Requests\StoreInvitationRequest;
use App\Modules\Workspaces\Http\Resources\InvitationPreviewResource;
use App\Modules\Workspaces\Http\Resources\WorkspaceInvitationResource;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Models\WorkspaceInvitation;
use App\Modules\Workspaces\Services\InvitationNotFoundException;
use App\Modules\Workspaces\Services\InvitationService;
use Illuminate\Http\Request;

class WorkspaceInvitationsController
{
    public function __construct(
        private InvitationService $service
    ) {}

    // --- Admin endpoints (auth:sanctum; gate manageMembers) ---------------

    public function index(Request $request, Workspace $workspace)
    {
        abort_unless($request->user()->can('manageMembers', $workspace), 403);

        return WorkspaceInvitationResource::collection(
            $this->service->pending($workspace)
        );
    }

    public function store(StoreInvitationRequest $request, Workspace $workspace)
    {
        $invitation = $this->service->invite(
            $workspace,
            $request->string('email')->toString(),
            $request->user(),
        );

        return WorkspaceInvitationResource::make($invitation)
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, Workspace $workspace, WorkspaceInvitation $invitation)
    {
        abort_unless($request->user()->can('manageMembers', $workspace), 403);
        $this->assertBelongsTo($invitation, $workspace);

        $this->service->revoke($invitation);

        return response()->noContent();
    }

    public function resend(Request $request, Workspace $workspace, WorkspaceInvitation $invitation)
    {
        abort_unless($request->user()->can('manageMembers', $workspace), 403);
        $this->assertBelongsTo($invitation, $workspace);

        return WorkspaceInvitationResource::make(
            $this->service->resend($invitation)
        );
    }

    // --- Public endpoints (NO auth:sanctum; must never return 401) ---------

    public function preview(string $token)
    {
        $invitation = $this->service->findByToken($token);

        if ($invitation === null) {
            throw new InvitationNotFoundException;
        }

        [$valid, $accountExists] = $this->service->previewState($invitation);

        return new InvitationPreviewResource($invitation, $valid, $accountExists);
    }

    public function accept(AcceptInvitationRequest $request, string $token)
    {
        // Optional bearer auth: resolves the user if a token is present, null
        // otherwise. The route is outside auth:sanctum, so this never 401s.
        $authenticated = $request->user('sanctum');

        return response()->json(
            $this->service->accept(
                AcceptInvitationDTO::fromRequest($request, $token),
                $authenticated,
            )
        );
    }

    private function assertBelongsTo(WorkspaceInvitation $invitation, Workspace $workspace): void
    {
        abort_unless($invitation->workspace_id === $workspace->id, 404);
    }
}
