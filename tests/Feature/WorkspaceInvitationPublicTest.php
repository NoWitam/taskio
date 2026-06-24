<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workspaces\Enums\WorkspaceInvitationStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Models\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceInvitationPublicTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvitation(string $plain, array $overrides = []): WorkspaceInvitation
    {
        $owner = $overrides['owner'] ?? User::factory()->create();
        unset($overrides['owner']);

        $workspace = $overrides['workspace'] ?? Workspace::factory()->create(['owner_id' => $owner->id]);
        unset($overrides['workspace']);

        return WorkspaceInvitation::factory()->withToken($plain)->create(array_merge([
            'workspace_id' => $workspace->id,
            'invited_by' => $owner->id,
            'email' => 'invitee@example.com',
        ], $overrides));
    }

    // --- Preview -----------------------------------------------------------

    public function test_preview_for_a_valid_invitation(): void
    {
        $plain = 'valid-token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $this->makeInvitation($plain);

        $this->getJson("/api/invitations/{$plain}")
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.account_exists', false)
            ->assertJsonPath('data.email', 'invitee@example.com')
            ->assertJsonStructure(['data' => ['workspace_name', 'invited_by_name', 'email', 'status', 'valid', 'account_exists']]);
    }

    public function test_preview_reports_account_exists_when_a_user_has_that_email(): void
    {
        User::factory()->create(['email' => 'invitee@example.com']);
        $plain = 'exists-token-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $this->makeInvitation($plain);

        $this->getJson("/api/invitations/{$plain}")
            ->assertOk()
            ->assertJsonPath('data.account_exists', true);
    }

    public function test_preview_for_an_expired_invitation_is_valid_false_not_401(): void
    {
        $plain = 'expired-token-ccccccccccccccccccccccccccccccccccccccccccccccccc';
        $this->makeInvitation($plain, ['expires_at' => now()->subDay()]);

        $this->getJson("/api/invitations/{$plain}")
            ->assertOk()
            ->assertJsonPath('data.valid', false);
    }

    public function test_preview_for_a_revoked_invitation_is_valid_false(): void
    {
        $plain = 'revoked-token-ddddddddddddddddddddddddddddddddddddddddddddddddd';
        $this->makeInvitation($plain, ['status' => WorkspaceInvitationStatus::Revoked]);

        $this->getJson("/api/invitations/{$plain}")
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.status', 'revoked');
    }

    public function test_preview_for_an_unknown_token_is_404(): void
    {
        $this->getJson('/api/invitations/totally-unknown-token-value')
            ->assertNotFound();
    }

    // --- Accept security matrix --------------------------------------------

    public function test_authenticated_user_with_matching_email_joins(): void
    {
        $user = User::factory()->create(['email' => 'invitee@example.com']);
        $plain = 'authmatch-token-eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
        $invitation = $this->makeInvitation($plain);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/invitations/{$plain}/accept");

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('current_workspace', $invitation->workspace_id)
            ->assertJsonStructure(['user', 'permissions', 'workspaces', 'current_workspace']);

        // No new token is issued for an already-authenticated user.
        $this->assertArrayNotHasKey('token', $response->json());

        $this->assertDatabaseHas('workspace_user', [
            'workspace_id' => $invitation->workspace_id,
            'user_id' => $user->id,
        ]);
        $this->assertSame(WorkspaceInvitationStatus::Accepted, $invitation->fresh()->status);
    }

    public function test_authenticated_user_with_mismatched_email_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'someone-else@example.com']);
        $plain = 'authmismatch-token-fffffffffffffffffffffffffffffffffffffffffffff';
        $this->makeInvitation($plain);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/invitations/{$plain}/accept")
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'email_mismatch');
    }

    public function test_unauthenticated_existing_account_with_correct_password_joins_and_gets_token(): void
    {
        User::factory()->create(['email' => 'invitee@example.com']); // password "password"
        $plain = 'existpw-token-ggggggggggggggggggggggggggggggggggggggggggggggggg';
        $invitation = $this->makeInvitation($plain);

        $response = $this->postJson("/api/invitations/{$plain}/accept", [
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user', 'permissions', 'workspaces', 'current_workspace'])
            ->assertJsonPath('current_workspace', $invitation->workspace_id);

        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('workspace_user', [
            'workspace_id' => $invitation->workspace_id,
            'user_id' => User::query()->where('email', 'invitee@example.com')->value('id'),
        ]);
    }

    public function test_unauthenticated_existing_account_with_wrong_password_is_422_not_401(): void
    {
        User::factory()->create(['email' => 'invitee@example.com']);
        $plain = 'wrongpw-token-hhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhh';
        $this->makeInvitation($plain);

        $this->postJson("/api/invitations/{$plain}/accept", ['password' => 'not-the-password'])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'invalid_credentials');
    }

    public function test_unauthenticated_existing_account_without_password_requires_login(): void
    {
        User::factory()->create(['email' => 'invitee@example.com']);
        $plain = 'nopw-token-iiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiii';
        $this->makeInvitation($plain);

        $this->postJson("/api/invitations/{$plain}/accept")
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'login_required');
    }

    public function test_unauthenticated_no_account_registers_user_bound_to_invite_email(): void
    {
        $plain = 'register-token-jjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjj';
        $invitation = $this->makeInvitation($plain);

        $response = $this->postJson("/api/invitations/{$plain}/accept", [
            'name' => 'New Person',
            'password' => 'supersecret',
            'email' => 'attacker-tries-other@example.com', // must be ignored
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user', 'permissions', 'workspaces', 'current_workspace'])
            ->assertJsonPath('user.email', 'invitee@example.com')
            ->assertJsonPath('current_workspace', $invitation->workspace_id);

        $this->assertDatabaseHas('users', ['email' => 'invitee@example.com', 'name' => 'New Person']);
        $this->assertDatabaseMissing('users', ['email' => 'attacker-tries-other@example.com']);
    }

    public function test_unauthenticated_no_account_without_credentials_is_rejected(): void
    {
        $plain = 'needcreds-token-kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';
        $this->makeInvitation($plain);

        $this->postJson("/api/invitations/{$plain}/accept")
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'registration_required');
    }

    public function test_expired_invitation_cannot_be_accepted_and_creates_no_user(): void
    {
        $plain = 'expaccept-token-lllllllllllllllllllllllllllllllllllllllllllllll';
        $this->makeInvitation($plain, ['expires_at' => now()->subDay()]);

        $this->postJson("/api/invitations/{$plain}/accept", [
            'name' => 'Late Joiner',
            'password' => 'supersecret',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', 'expired');

        $this->assertDatabaseMissing('users', ['email' => 'invitee@example.com']);
    }

    public function test_revoked_invitation_cannot_be_accepted(): void
    {
        $plain = 'revaccept-token-mmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmm';
        $this->makeInvitation($plain, ['status' => WorkspaceInvitationStatus::Revoked]);

        $this->postJson("/api/invitations/{$plain}/accept", [
            'name' => 'Nope',
            'password' => 'supersecret',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', 'invalid_invitation');
    }

    public function test_already_accepted_invitation_cannot_be_reused(): void
    {
        $plain = 'reuse-token-nnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnn';
        $this->makeInvitation($plain, [
            'status' => WorkspaceInvitationStatus::Accepted,
            'accepted_at' => now(),
        ]);

        $this->postJson("/api/invitations/{$plain}/accept", ['password' => 'password'])
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', 'already_used');
    }

    public function test_accept_with_unknown_token_is_404(): void
    {
        $this->postJson('/api/invitations/unknown-token/accept', ['password' => 'password'])
            ->assertNotFound();
    }
}
