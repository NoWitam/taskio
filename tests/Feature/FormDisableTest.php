<?php

namespace Tests\Feature;

use App\Modules\Forms\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormDisableTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_disable_enabled_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'enabled_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/disable");

        $response->assertOk()
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.is_draft', true);

        $this->assertNull($form->fresh()->enabled_at);
    }

    public function test_disabling_creates_content_backup(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                ['id' => 'field', 'type' => 'short_text', 'config' => ['label' => 'Test']]
            ],
            'enabled_at' => now(),
        ]);

        $enabledContent = $form->content;

        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/disable");

        $form->refresh();
        $this->assertEquals($enabledContent, $form->content_backup);
    }

    public function test_disabling_already_disabled_form_is_idempotent(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'enabled_at' => null,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/disable");

        $response->assertOk();
        $this->assertNull($form->fresh()->enabled_at);
    }

    public function test_cannot_disable_anonymous_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'is_anonymous' => true,
            'enabled_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/disable");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['form']);

        $this->assertNotNull($form->fresh()->enabled_at);
    }

    public function test_only_creator_can_disable_form(): void
    {
        $creator = User::factory()->create();
        $otherUser = User::factory()->create();
        
        $form = Form::factory()->create([
            'creator_id' => $creator->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'enabled_at' => now(),
        ]);

        $response = $this->actingAs($otherUser)
            ->postJson("/api/forms/{$form->id}/disable");

        $response->assertForbidden();
        $this->assertNotNull($form->fresh()->enabled_at);
    }

    public function test_disabling_does_not_unindex_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'enabled_at' => now(),
            'indexed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/disable");

        $response->assertOk()
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.is_indexed', true);

        $form->refresh();
        $this->assertNull($form->enabled_at);
        $this->assertNotNull($form->indexed_at);
    }

    public function test_disabled_form_can_be_reenabled(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'enabled_at' => now(),
        ]);

        // Disable
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/disable")
            ->assertOk();

        $this->assertNull($form->fresh()->enabled_at);

        // Re-enable
        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable");

        $response->assertOk()
            ->assertJsonPath('data.is_enabled', true);

        $this->assertNotNull($form->fresh()->enabled_at);
    }
}
