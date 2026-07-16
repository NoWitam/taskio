<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Workspaces\Enums\WorkspaceInvitationStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Models\WorkspaceInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Modules\Workspaces\Models\WorkspaceInvitation>
 */
class WorkspaceInvitationFactory extends Factory
{
    protected $model = WorkspaceInvitation::class;

    /**
     * The most recent plaintext token the factory generated, so tests can
     * exercise the public flow with the same token whose hash was persisted.
     */
    public ?string $lastPlainToken = null;

    public function definition(): array
    {
        $plain = Str::random(64);
        $this->lastPlainToken = $plain;

        return [
            'workspace_id' => Workspace::factory(),
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', $plain),
            'invited_by' => User::factory(),
            'status' => WorkspaceInvitationStatus::Pending,
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
        ];
    }

    /**
     * Use a known plaintext token so a test can drive the public endpoints.
     */
    public function withToken(string $plain): static
    {
        $this->lastPlainToken = $plain;

        return $this->state(fn () => ['token_hash' => hash('sha256', $plain)]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['status' => WorkspaceInvitationStatus::Revoked]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => [
            'status' => WorkspaceInvitationStatus::Accepted,
            'accepted_at' => now(),
        ]);
    }
}
