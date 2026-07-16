<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workspaces\Enums\WorkspaceInvitationStatus;
use App\Modules\Workspaces\Mail\WorkspaceInvitationMail;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Models\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WorkspaceInvitationAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_invitation_and_only_the_hash_is_persisted(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $response = $this->actingAs($owner)->postJson(
            "/api/workspaces/{$workspace->id}/invitations",
            ['email' => 'invitee@example.com'],
        );

        $response->assertCreated()
            ->assertJsonPath('data.email', 'invitee@example.com')
            ->assertJsonPath('data.status', 'pending');

        // No token/hash leaks into the response.
        $this->assertArrayNotHasKey('token', $response->json('data'));
        $this->assertArrayNotHasKey('token_hash', $response->json('data'));

        $invitation = WorkspaceInvitation::query()->firstOrFail();
        // sha256 hex digest is always 64 chars; the plaintext (64 random chars)
        // is never stored, only its hash.
        $this->assertSame(64, strlen($invitation->token_hash));
        $this->assertSame(WorkspaceInvitationStatus::Pending, $invitation->status);

        Mail::assertSent(WorkspaceInvitationMail::class, fn ($mail) => $mail->hasTo('invitee@example.com'));
    }

    public function test_plaintext_token_is_never_stored(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $plain = null;

        $this->actingAs($owner)->postJson(
            "/api/workspaces/{$workspace->id}/invitations",
            ['email' => 'invitee@example.com'],
        )->assertCreated();

        Mail::assertSent(WorkspaceInvitationMail::class, function (WorkspaceInvitationMail $mail) use (&$plain) {
            // The accept URL embeds the plaintext token.
            $plain = str_replace(config('app.url') . '/next/invitations/', '', $mail->acceptUrl);

            return true;
        });

        $this->assertNotNull($plain);

        // The plaintext must NOT be findable as a stored hash mismatch: the row
        // stores sha256(plain), never plain itself.
        $invitation = WorkspaceInvitation::query()->firstOrFail();
        $this->assertSame(hash('sha256', $plain), $invitation->token_hash);
        $this->assertNotSame($plain, $invitation->token_hash);
    }

    public function test_non_owner_cannot_create_invitation(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach($member->id);

        $this->actingAs($member)
            ->postJson("/api/workspaces/{$workspace->id}/invitations", ['email' => 'x@example.com'])
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_inviting_an_existing_member_is_rejected(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $member = User::factory()->create(['email' => 'member@example.com']);
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach($member->id);

        $this->actingAs($owner)
            ->postJson("/api/workspaces/{$workspace->id}/invitations", ['email' => 'member@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'already_member');
    }

    public function test_inviting_an_already_pending_email_is_rejected(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        WorkspaceInvitation::factory()->create([
            'workspace_id' => $workspace->id,
            'email' => 'dup@example.com',
            'invited_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->postJson("/api/workspaces/{$workspace->id}/invitations", ['email' => 'dup@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'already_invited');
    }

    public function test_lists_only_pending_invitations(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        WorkspaceInvitation::factory()->count(2)->create([
            'workspace_id' => $workspace->id,
            'invited_by' => $owner->id,
        ]);
        WorkspaceInvitation::factory()->revoked()->create([
            'workspace_id' => $workspace->id,
            'invited_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)->getJson("/api/workspaces/{$workspace->id}/invitations");

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_owner_can_revoke_invitation(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $workspace->id,
            'invited_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->deleteJson("/api/workspaces/{$workspace->id}/invitations/{$invitation->id}")
            ->assertNoContent();

        $this->assertSame(
            WorkspaceInvitationStatus::Revoked,
            $invitation->fresh()->status,
        );
    }

    public function test_owner_cannot_revoke_an_invitation_from_another_workspace(): void
    {
        $owner = User::factory()->create();
        $workspaceA = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspaceB = Workspace::factory()->create(['owner_id' => $owner->id]);

        $invitationB = WorkspaceInvitation::factory()->create([
            'workspace_id' => $workspaceB->id,
            'invited_by' => $owner->id,
        ]);

        // Mismatched {workspace}/{invitation} pair must not resolve.
        $this->actingAs($owner)
            ->deleteJson("/api/workspaces/{$workspaceA->id}/invitations/{$invitationB->id}")
            ->assertNotFound();

        $this->assertSame(
            WorkspaceInvitationStatus::Pending,
            $invitationB->fresh()->status,
        );
    }

    public function test_resend_rotates_the_token_so_the_old_one_no_longer_resolves(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $oldPlain = 'old-plaintext-token-value-for-resend-rotation-coverage-1234567';
        $invitation = WorkspaceInvitation::factory()
            ->withToken($oldPlain)
            ->create([
                'workspace_id' => $workspace->id,
                'invited_by' => $owner->id,
            ]);

        $oldHash = $invitation->token_hash;

        $this->actingAs($owner)
            ->postJson("/api/workspaces/{$workspace->id}/invitations/{$invitation->id}/resend")
            ->assertOk();

        $invitation->refresh();
        $this->assertNotSame($oldHash, $invitation->token_hash);

        // The old token no longer resolves on the public preview.
        $this->getJson("/api/invitations/{$oldPlain}")->assertNotFound();

        Mail::assertSent(WorkspaceInvitationMail::class, 1);
    }
}
