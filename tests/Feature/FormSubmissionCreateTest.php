<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
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
                ['type' => 'short_text', 'id' => 'name', 'config' => ['label' => 'Name']],
            ],
            'enabled_at' => now(),
        ]);

        $form->refresh();
        $fieldId = $form->content[0]['id'];

        $response = $this->actingAs($user)
            ->postJson('/api/form-submissions', [
                'form_id' => $form->id,
                'data' => [
                    $fieldId => 'John Doe',
                ],
            ]);

        // NOT `data.form_id`: FormSubmissionResource carries its own top-level `data` key
        // (the answers), which suppresses Laravel's default `data` envelope — see
        // test_single_submission_responses_are_not_data_wrapped below.
        $response->assertCreated()
            ->assertJsonPath('form_id', $form->id);
    }

    public function test_cannot_create_submission_for_disabled_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'content' => [
                ['type' => 'short_text', 'id' => 'name', 'config' => ['label' => 'Name']],
            ],
            'enabled_at' => null,
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/form-submissions', [
                'form_id' => $form->id,
                'data' => [
                    'name' => 'John Doe',
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
                ['type' => 'short_text', 'id' => 'name', 'config' => ['label' => 'Name']],
            ],
            'enabled_at' => now(),
        ]);

        $form->refresh();
        $fieldId = $form->content[0]['id'];

        // Not providing submittable_type and submittable_id
        $response = $this->actingAs($user)
            ->postJson('/api/form-submissions', [
                'form_id' => $form->id,
                'data' => [
                    $fieldId => 'John Doe',
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('is_approved', true);

        $submission = $form->submissions()->first();

        // The ALIAS, not the FQCN. FormsModuleServiceProvider enforces a morph map, so
        // `StoreFormSubmissionRequest::prepareForValidation()` stamps `(new Form)->getMorphClass()`
        // = 'form'. That alias is what is persisted, what `FormSubmissionResource` ships as
        // `source`, and what the frontend filters on (`SubmissionFilters.sources`) — asserting the
        // class name here checked a value the column has never held.
        $this->assertSame('form', $submission->submittable_type);
        $this->assertEquals($form->id, $submission->submittable_id);
        $this->assertTrue($submission->submittable->is($form));
        $this->assertNotNull($submission->approved_at);

        $response->assertJsonPath('source', 'form');
    }

    /**
     * PINS AN ACCIDENTAL BUT LOAD-BEARING API SHAPE.
     *
     * Every other single-resource endpoint in this application answers `{"data": {...}}`.
     * These do not, and the reason is not a decision anybody made: `FormSubmissionResource`
     * exposes the submission's answers under a top-level `data` key, and Laravel's
     * `ResourceResponse::haveDefaultWrapperAndDataIsUnwrapped()` skips the envelope whenever
     * the resolved array already contains the wrapper key. So the answers payload silently
     * takes the envelope's place and the resource is returned flat.
     *
     * The "next" frontend reads these endpoints flat (`api.get<FormSubmission>` in
     * `stores/forms.ts`, documented there as "unwrapped"), so the shape is real contract now
     * and cannot be changed without a coordinated frontend change. Two hazards follow, and
     * this test exists to make both of them loud:
     *
     *  1. Anyone writing a new assertion by convention reaches for `data.*` and silently gets
     *     null — that is exactly how three tests in this file and FormSubmissionEditTest sat
     *     red from the day they were written.
     *  2. Renaming or removing the resource's `data` key (e.g. to `answers`) would flip the
     *     envelope back ON and break every consumer at once, with nothing else to catch it.
     *
     * COLLECTIONS ARE DIFFERENT AND STAY DIFFERENT: `GET /forms/{form}/submissions` wraps
     * normally, because the envelope is applied around the item list, not inside an item.
     */
    public function test_single_submission_responses_are_not_data_wrapped(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'content' => [
                ['type' => 'short_text', 'id' => 'name', 'config' => ['label' => 'Name']],
            ],
            'enabled_at' => now(),
        ]);

        $form->refresh();
        $fieldId = $form->content[0]['id'];

        $store = $this->actingAs($user)
            ->postJson('/api/form-submissions', [
                'form_id' => $form->id,
                'data' => [$fieldId => 'John Doe'],
            ])
            ->assertCreated();

        // The envelope is absent, and `data` is the answers — not a nested resource.
        $store->assertJsonPath('id', fn ($id) => is_string($id) && $id !== '')
            ->assertJsonPath('form_id', $form->id)
            ->assertJsonPath('is_approved', true)
            ->assertJsonPath('data', [$fieldId => 'John Doe'])
            ->assertJsonMissingPath('data.form_id')
            ->assertJsonMissingPath('data.is_approved');

        $id = $store->json('id');

        // The same flat shape on show and update, which the frontend also reads directly.
        $this->actingAs($user)
            ->getJson("/api/form-submissions/{$id}")
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('form_id', $form->id);

        $draft = FormSubmission::create([
            'form_id' => $form->id,
            'submittable_type' => Form::class,
            'submittable_id' => $form->id,
            'data' => [$fieldId => 'before'],
            'approved_at' => null,
            'creator_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->putJson("/api/form-submissions/{$draft->id}", ['data' => [$fieldId => 'after']])
            ->assertOk()
            ->assertJsonPath('id', $draft->id)
            ->assertJsonPath('data', [$fieldId => 'after']);

        // ...while the list endpoint keeps the conventional envelope.
        $this->actingAs($user)
            ->getJson("/api/forms/{$form->id}/submissions")
            ->assertOk()
            ->assertJsonPath('data.0.form_id', $form->id);
    }
}
