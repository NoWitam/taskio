<?php

namespace App\Modules\Workspaces\Services;

use App\Models\User;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Workspaces\DTOs\AcceptInvitationDTO;
use App\Modules\Workspaces\Enums\WorkspaceInvitationStatus;
use App\Modules\Workspaces\Mail\WorkspaceInvitationMail;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Models\WorkspaceInvitation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvitationService
{
    private const EXPIRY_DAYS = 7;

    public function __construct(
        private WorkspaceService $workspaces,
        private AuthService $auth,
    ) {}

    /**
     * Pending (and not-yet-expired) invitations for a workspace, newest first.
     */
    public function pending(Workspace $workspace): Collection
    {
        return WorkspaceInvitation::query()
            ->with('invitedBy')
            ->where('workspace_id', $workspace->id)
            ->pending()
            ->latest()
            ->get();
    }

    /**
     * Create a pending invitation, store only the token hash, and email the
     * plaintext token. The plaintext is never persisted or returned.
     */
    public function invite(Workspace $workspace, string $email, User $invitedBy): WorkspaceInvitation
    {
        [$plain, $hash] = $this->generateToken();

        $invitation = WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => $email,
            'token_hash' => $hash,
            'invited_by' => $invitedBy->id,
            'status' => WorkspaceInvitationStatus::Pending,
            'expires_at' => now()->addDays(self::EXPIRY_DAYS),
        ]);

        $invitation->setRelation('workspace', $workspace);
        $invitation->setRelation('invitedBy', $invitedBy);

        $this->sendMail($invitation, $plain);

        return $invitation;
    }

    /**
     * Revoke a pending invitation. Idempotent on status — already-terminal
     * invitations stay as they are.
     */
    public function revoke(WorkspaceInvitation $invitation): void
    {
        if ($invitation->status === WorkspaceInvitationStatus::Pending) {
            $invitation->update(['status' => WorkspaceInvitationStatus::Revoked]);
        }
    }

    /**
     * Rotate the token (new plaintext + hash) so any previously emailed link
     * stops resolving, reset expiry, and re-send the mail.
     */
    public function resend(WorkspaceInvitation $invitation): WorkspaceInvitation
    {
        [$plain, $hash] = $this->generateToken();

        $invitation->update([
            'token_hash' => $hash,
            'status' => WorkspaceInvitationStatus::Pending,
            'expires_at' => now()->addDays(self::EXPIRY_DAYS),
        ]);

        $invitation->load(['workspace', 'invitedBy']);

        $this->sendMail($invitation, $plain);

        return $invitation;
    }

    /**
     * Resolve an invitation by its plaintext token (hashing it for lookup), or
     * null if no row matches. Eager-loads the relations the preview needs.
     */
    public function findByToken(string $token): ?WorkspaceInvitation
    {
        return WorkspaceInvitation::query()
            ->with(['workspace', 'invitedBy'])
            ->where('token_hash', $this->hash($token))
            ->first();
    }

    /**
     * @return array{0: bool, 1: bool} [valid, accountExists]
     */
    public function previewState(WorkspaceInvitation $invitation): array
    {
        $valid = $invitation->status === WorkspaceInvitationStatus::Pending
            && !$invitation->isExpired();

        // Defensive bypass: this runs on the public preview route with no active
        // workspace (so the scope is inert anyway), but belt-and-suspenders against
        // any caller that reaches here with a workspace active.
        $accountExists = User::query()
            ->withoutWorkspaceMemberScope()
            ->whereRaw('lower(email) = ?', [Str::lower($invitation->email)])
            ->exists();

        return [$valid, $accountExists];
    }

    /**
     * Accept an invitation. Whole flow is transactional so a failed branch never
     * leaves a half-created user or a half-joined workspace. Returns the
     * login-shaped context; for new/credentialed users it includes a fresh token.
     *
     * @return array<string, mixed>
     */
    public function accept(AcceptInvitationDTO $dto, ?User $authenticated): array
    {
        return DB::transaction(function () use ($dto, $authenticated) {
            $invitation = $this->findByToken($dto->token);

            // Unknown token: the only 404 the public accept route emits. The FE
            // handles it without the 401 interceptor, so no redirect is triggered.
            if ($invitation === null) {
                throw new InvitationNotFoundException;
            }

            $this->assertAcceptable($invitation);

            $email = Str::lower($invitation->email);

            if ($authenticated !== null) {
                return $this->acceptAsAuthenticated($invitation, $authenticated, $email);
            }

            // Defensive bypass: public accept route runs with no active workspace
            // (scope inert), but never let an active workspace hide the existing
            // account and force an erroneous "new user" branch.
            $existing = User::query()
                ->withoutWorkspaceMemberScope()
                ->whereRaw('lower(email) = ?', [$email])
                ->first();

            return $existing !== null
                ? $this->acceptAsExistingUser($invitation, $existing, $dto)
                : $this->acceptAsNewUser($invitation, $dto);
        });
    }

    /**
     * Authenticated request: the bearer user joins iff their email matches the
     * invite. No new token is issued — the SPA already holds one.
     *
     * @return array<string, mixed>
     */
    private function acceptAsAuthenticated(WorkspaceInvitation $invitation, User $user, string $email): array
    {
        if (Str::lower($user->email) !== $email) {
            throw ValidationException::withMessages([
                'email' => ['email_mismatch'],
            ]);
        }

        $this->join($invitation, $user);

        return $this->auth->context($user, $invitation->workspace_id);
    }

    /**
     * Unauthenticated but an account exists: require and verify a password, then
     * join and issue a fresh token. Wrong/absent password is a structured 422,
     * never a 401 (which would trip the SPA redirect interceptor).
     *
     * @return array<string, mixed>
     */
    private function acceptAsExistingUser(WorkspaceInvitation $invitation, User $user, AcceptInvitationDTO $dto): array
    {
        if ($dto->password === null) {
            throw ValidationException::withMessages([
                'password' => ['login_required'],
            ]);
        }

        if (!Hash::check($dto->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['invalid_credentials'],
            ]);
        }

        $this->join($invitation, $user);

        return $this->auth->issueToken($user, false, $invitation->workspace_id);
    }

    /**
     * Unauthenticated and no account: create the user with the INVITE's email
     * (bound to the invite, not chosen), then join and issue a token.
     *
     * @return array<string, mixed>
     */
    private function acceptAsNewUser(WorkspaceInvitation $invitation, AcceptInvitationDTO $dto): array
    {
        if ($dto->name === null || $dto->password === null) {
            throw ValidationException::withMessages([
                'password' => ['registration_required'],
            ]);
        }

        $user = User::create([
            'name' => $dto->name,
            'email' => $invitation->email,
            'password' => Hash::make($dto->password),
        ]);

        $this->join($invitation, $user);

        return $this->auth->issueToken($user, false, $invitation->workspace_id);
    }

    /**
     * Attach the user to the workspace (reusing WorkspaceService) and mark the
     * invitation accepted.
     */
    private function join(WorkspaceInvitation $invitation, User $user): void
    {
        $this->workspaces->addMember($invitation->workspace, $user);

        $invitation->update([
            'status' => WorkspaceInvitationStatus::Accepted,
            'accepted_at' => now(),
        ]);
    }

    /**
     * Reject expired/revoked/accepted invitations BEFORE any user is created.
     */
    private function assertAcceptable(WorkspaceInvitation $invitation): void
    {
        if ($invitation->status === WorkspaceInvitationStatus::Accepted) {
            throw ValidationException::withMessages([
                'token' => ['already_used'],
            ]);
        }

        if ($invitation->status === WorkspaceInvitationStatus::Revoked) {
            throw ValidationException::withMessages([
                'token' => ['invalid_invitation'],
            ]);
        }

        if ($invitation->isExpired()) {
            throw ValidationException::withMessages([
                'token' => ['expired'],
            ]);
        }
    }

    private function sendMail(WorkspaceInvitation $invitation, string $plainToken): void
    {
        Mail::to($invitation->email)->send(new WorkspaceInvitationMail($invitation, $plainToken));
    }

    /**
     * @return array{0: string, 1: string} [plaintext, sha256 hash]
     */
    private function generateToken(): array
    {
        $plain = Str::random(64);

        return [$plain, $this->hash($plain)];
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
