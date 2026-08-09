<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormSubmissionEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_edit_approved_submission(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->enabled()->create();

        $submission = FormSubmission::create([
            'form_id' => $form->id,
            'submittable_type' => Form::class,
            'submittable_id' => $form->id,
            'data' => ['field' => 'original'],
            'approved_at' => now(), // Approved
            'creator_id' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->putJson("/api/form-submissions/{$submission->id}", [
                'data' => ['field' => 'updated'],
            ]);

        $response->assertForbidden();
        $this->assertEquals(['field' => 'original'], $submission->fresh()->data);
    }

    public function test_can_edit_draft_submission(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->enabled()->create();

        $submission = FormSubmission::create([
            'form_id' => $form->id,
            'submittable_type' => Form::class,
            'submittable_id' => $form->id,
            'data' => ['field' => 'original'],
            'approved_at' => null, // Draft
            'creator_id' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->putJson("/api/form-submissions/{$submission->id}", [
                'data' => ['field' => 'updated'],
            ]);

        $response->assertOk();
        $this->assertEquals(['field' => 'updated'], $submission->fresh()->data);
    }

    public function test_manual_submissions_are_approved_automatically(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->enabled()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/form-submissions', [
                'form_id' => $form->id,
                'data' => ['field' => 'test'],
                // No submittable provided = manual submission
            ]);

        // Flat, not `data.*`: the resource's own `data` key (the answers) displaces Laravel's
        // envelope — pinned by FormSubmissionCreateTest::test_single_submission_responses_are_not_data_wrapped.
        $response->assertCreated()
            ->assertJsonPath('is_approved', true);

        $submission = FormSubmission::find($response->json('id'));
        $this->assertNotNull($submission->approved_at);
        $this->assertTrue($submission->isApproved());
    }

    public function test_submission_can_be_approved_manually(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->enabled()->create();

        $submission = FormSubmission::create([
            'form_id' => $form->id,
            'submittable_type' => Form::class,
            'submittable_id' => $form->id,
            'data' => ['field' => 'test'],
            'approved_at' => null, // Draft
            'creator_id' => $user->id,
        ]);

        $this->assertFalse($submission->isApproved());

        $submission->approve();

        $this->assertTrue($submission->fresh()->isApproved());
        $this->assertNotNull($submission->approved_at);
    }

    public function test_approving_already_approved_submission_is_idempotent(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->enabled()->create();

        $approvedAt = now()->subHour();
        $submission = FormSubmission::create([
            'form_id' => $form->id,
            'submittable_type' => Form::class,
            'submittable_id' => $form->id,
            'data' => ['field' => 'test'],
            'approved_at' => $approvedAt,
            'creator_id' => $user->id,
        ]);

        $submission->approve();

        // approved_at should remain the same
        $this->assertEquals(
            $approvedAt->timestamp,
            $submission->fresh()->approved_at->timestamp
        );
    }
}
