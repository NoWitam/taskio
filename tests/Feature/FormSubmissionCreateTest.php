<?php

namespace Tests\Feature;

use App\Modules\Forms\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormSubmissionCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_submission_for_enabled_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'content' => [
                ['type' => 'short_text', 'label' => 'Name', 'id' => 'name']
            ],
            'enabled_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/form-submissions', [
                'form_id' => $form->id,
                'data' => [
                    'name' => 'John Doe'
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.form_id', $form->id);
    }

    public function test_cannot_create_submission_for_disabled_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'content' => [
                ['type' => 'short_text', 'label' => 'Name']
            ],
            'enabled_at' => null,
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/form-submissions', [
                'form_id' => $form->id,
                'data' => [
                    'name' => 'John Doe'
                ],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['form_id']);
    }

    public function test_manual_submission_has_submittable_pointing_to_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'content' => [
                ['type' => 'short_text', 'label' => 'Name']
            ],
            'enabled_at' => now(),
        ]);

        // Not providing submittable_type and submittable_id
        $response = $this->actingAs($user)
            ->postJson('/api/form-submissions', [
                'form_id' => $form->id,
                'data' => [
                    'name' => 'John Doe'
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_approved', true);
        
        $submission = $form->submissions()->first();
        $this->assertEquals(Form::class, $submission->submittable_type);
        $this->assertEquals($form->id, $submission->submittable_id);
        $this->assertNotNull($submission->approved_at);
    }
}
