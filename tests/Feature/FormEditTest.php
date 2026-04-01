<?php

namespace Tests\Feature;

use App\Modules\Forms\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_edit_content_of_disabled_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Original']
            ],
            'enabled_at' => null,
        ]);

        $newContent = [
            ['type' => 'long_text', 'label' => 'Updated']
        ];

        $response = $this->actingAs($user)
            ->putJson("/api/forms/{$form->id}", [
                'name' => $form->name,
                'content' => $newContent,
                'is_anonymous' => false,
            ]);

        $response->assertOk();
        $this->assertEquals($newContent, $form->fresh()->content);
    }

    public function test_cannot_edit_content_of_enabled_form(): void
    {
        $user = User::factory()->create();
        $originalContent = [
            ['type' => 'short_text', 'label' => 'Original']
        ];
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => $originalContent,
            'enabled_at' => now(),
        ]);

        $newContent = [
            ['type' => 'long_text', 'label' => 'Updated']
        ];

        $response = $this->actingAs($user)
            ->putJson("/api/forms/{$form->id}", [
                'name' => $form->name,
                'content' => $newContent,
                'is_anonymous' => false,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['content']);

        // Content should remain unchanged
        $this->assertEquals($originalContent, $form->fresh()->content);
    }

    public function test_can_edit_metadata_of_enabled_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'name' => 'Original Name',
            'description' => 'Original Description',
            'content' => [
                ['type' => 'short_text', 'label' => 'Field']
            ],
            'enabled_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->putJson("/api/forms/{$form->id}", [
                'name' => 'Updated Name',
                'description' => 'Updated Description',
                'is_anonymous' => false,
            ]);

        $response->assertOk();
        
        $form->refresh();
        $this->assertEquals('Updated Name', $form->name);
        $this->assertEquals('Updated Description', $form->description);
    }
}
