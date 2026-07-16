<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormSubmissionDeleteTest extends TestCase
{
    use RefreshDatabase;

    /** Acting user must be set BEFORE creating models (changelog needs a causer). */
    private function submission(User $user, Form $form): FormSubmission
    {
        return FormSubmission::create([
            'form_id' => $form->id,
            'submittable_type' => Form::class,
            'submittable_id' => $form->id,
            'data' => ['field' => 'value'],
            'approved_at' => now(),
            'creator_id' => $user->id,
        ]);
    }

    public function test_submission_can_be_soft_deleted(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Form::factory()->enabled()->create();
        $submission = $this->submission($user, $form);

        $this->deleteJson("/api/form-submissions/{$submission->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('form_submissions', ['id' => $submission->id]);
    }

    public function test_trashed_submission_can_be_restored(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Form::factory()->enabled()->create();
        $submission = $this->submission($user, $form);
        $submission->delete();

        // A single submission resource is NOT wrapped in `data` (its own `data`
        // answers key collides with Laravel's default wrapper), so the id is at
        // the top level of the body.
        $this->postJson("/api/form-submissions/{$submission->id}/restore")
            ->assertOk()
            ->assertJsonPath('id', $submission->id);

        $this->assertNotSoftDeleted('form_submissions', ['id' => $submission->id]);
    }

    public function test_trashed_submission_can_be_force_deleted(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Form::factory()->enabled()->create();
        $submission = $this->submission($user, $form);
        $submission->delete();

        $this->deleteJson("/api/form-submissions/{$submission->id}/force")
            ->assertNoContent();

        $this->assertDatabaseMissing('form_submissions', ['id' => $submission->id]);
    }
}
