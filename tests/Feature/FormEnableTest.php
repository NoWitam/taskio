<?php

namespace Tests\Feature;

use App\Modules\Forms\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormEnableTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_enable_form_with_input_fields(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                [
                    'type' => 'short_text',
                    'label' => 'Test Field',
                    'required' => true,
                ]
            ],
            'enabled_at' => null,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable");

        $response->assertOk()
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonStructure(['data' => ['enabled_at']]);

        $this->assertNotNull($form->fresh()->enabled_at);
    }

    public function test_cannot_enable_form_without_input_fields(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                [
                    'type' => 'heading',
                    'text' => 'Just a heading',
                ]
            ],
            'enabled_at' => null,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['content']);

        $this->assertNull($form->fresh()->enabled_at);
    }

    public function test_enabling_already_enabled_form_is_idempotent(): void
    {
        $user = User::factory()->create();
        $enabledAt = now()->subHour();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'enabled_at' => $enabledAt,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable");

        $response->assertOk();
        
        // enabled_at should remain the same
        $this->assertEquals(
            $enabledAt->timestamp,
            $form->fresh()->enabled_at->timestamp
        );
    }

    public function test_only_creator_can_enable_form(): void
    {
        $creator = User::factory()->create();
        $otherUser = User::factory()->create();
        
        $form = Form::factory()->create([
            'creator_id' => $creator->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'enabled_at' => null,
        ]);

        $response = $this->actingAs($otherUser)
            ->postJson("/api/forms/{$form->id}/enable");

        $response->assertForbidden();
        $this->assertNull($form->fresh()->enabled_at);
    }

    public function test_anonymous_forms_are_enabled_automatically(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/forms', [
                'name' => 'Anonymous Form',
                'is_anonymous' => true,
                'content' => [],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_enabled', true);

        $form = Form::find($response->json('data.id'));
        $this->assertNotNull($form->enabled_at);
    }
}
