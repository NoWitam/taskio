<?php

namespace App\Modules\Workspaces\Http\Requests;

use App\Models\User;
use App\Modules\Workspaces\Enums\WorkspaceInvitationStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Models\WorkspaceInvitation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageMembers', $this->workspace()) ?? false;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $email = $this->string('email')->lower()->toString();
            $workspace = $this->workspace();

            if ($this->alreadyMember($workspace, $email)) {
                $validator->errors()->add('email', 'already_member');

                return;
            }

            if ($this->hasPendingInvitation($workspace, $email)) {
                $validator->errors()->add('email', 'already_invited');
            }
        });
    }

    private function alreadyMember(Workspace $workspace, string $email): bool
    {
        // Runs with an active workspace (the one being invited into). Without the
        // bypass the member scope hides an invited user who is NOT yet a member of
        // that workspace, so the "already a member" guard would silently never fire.
        $user = User::query()
            ->withoutWorkspaceMemberScope()
            ->whereRaw('lower(email) = ?', [$email])
            ->first();

        return $user !== null && $workspace->hasMember($user);
    }

    private function hasPendingInvitation(Workspace $workspace, string $email): bool
    {
        return WorkspaceInvitation::query()
            ->where('workspace_id', $workspace->id)
            ->whereRaw('lower(email) = ?', [$email])
            ->where('status', WorkspaceInvitationStatus::Pending)
            ->where('expires_at', '>', now())
            ->exists();
    }

    private function workspace(): Workspace
    {
        return $this->route('workspace');
    }
}
