<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_and_context(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email'],
                'permissions',
                'workspaces',
                'current_workspace',
            ])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('current_workspace', $workspace->id);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_returns_context_with_token(): void
    {
        $user = User::factory()->create();

        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::factory()->create();

        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout')
            ->assertNoContent();

        // Simulate a fresh request boundary: within one test the container caches
        // the resolved guard user, so force re-authentication against the token.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_remember_me_extends_token_expiry(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => false,
        ])->assertOk();

        $shortLived = PersonalAccessToken::query()->latest('id')->first();
        $this->assertTrue($shortLived->expires_at->lessThan(now()->addDays(2)));

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ])->assertOk();

        $longLived = PersonalAccessToken::query()->latest('id')->first();
        $this->assertTrue($longLived->expires_at->greaterThan(now()->addDays(2)));
    }
}
