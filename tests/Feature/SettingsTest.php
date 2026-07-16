<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_their_profile(): void
    {
        $user = User::factory()->create(['name' => 'Ann']);
        Sanctum::actingAs($user);

        $this->getJson('/api/settings/profile')
            ->assertOk()
            ->assertJsonPath('name', 'Ann')
            ->assertJsonPath('email', $user->email);
    }

    public function test_user_can_update_their_profile(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/settings/profile', [
            'name' => 'New Name',
            'email' => 'new@example.com',
            'locale' => 'pl',
        ])
            ->assertOk()
            ->assertJsonPath('name', 'New Name')
            ->assertJsonPath('locale', 'pl');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'new@example.com']);
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/settings/profile', ['name' => 'X', 'email' => 'taken@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_password_change_requires_correct_current_password(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/settings/password', [
            'current_password' => 'wrong-password',
            'password' => 'newsecret1',
            'password_confirmation' => 'newsecret1',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_changing_password_revokes_other_sessions_but_keeps_current(): void
    {
        $user = User::factory()->create();

        $tokenA = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('token');
        $this->app['auth']->forgetGuards();

        $tokenB = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('token');
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->putJson('/api/settings/password', [
                'current_password' => 'password',
                'password' => 'newsecret1',
                'password_confirmation' => 'newsecret1',
            ])
            ->assertNoContent();
        $this->app['auth']->forgetGuards();

        // The other session is revoked, the current one still works.
        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->getJson('/api/auth/me')
            ->assertOk();
    }
}
