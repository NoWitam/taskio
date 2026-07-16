<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnonymousFormCreationTest extends TestCase
{
    use RefreshDatabase;

    /** Valid form content with a single input field ({id, type, config} shape). */
    private function validContent(): array
    {
        return [
            [
                'id' => 'field_1',
                'type' => 'short_text',
                'config' => ['label' => 'Question', 'required' => false],
            ],
        ];
    }

    public function test_anonymous_form_is_created_without_a_name_and_auto_enabled(): void
    {
        $user = User::factory()->create();

        // No `name` is sent — anonymous forms get an auto-generated default.
        $response = $this->actingAs($user)
            ->postJson('/api/forms', [
                'is_anonymous' => true,
                'content' => $this->validContent(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_anonymous', true)
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.name', __('forms.anonymousDefaultName'));

        $form = Form::find($response->json('data.id'));
        $this->assertNotNull($form->enabled_at);
        $this->assertSame(__('forms.anonymousDefaultName'), $form->name);
    }

    public function test_anonymous_form_is_excluded_from_the_forms_list(): void
    {
        $user = User::factory()->create();
        $listed = Form::factory()->create(['creator_id' => $user->id]);
        $anonymous = Form::factory()->anonymous()->create(['creator_id' => $user->id]);

        $response = $this->actingAs($user)->getJson('/api/forms');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($listed->id, $ids);
        $this->assertNotContains($anonymous->id, $ids);
    }

    public function test_regular_form_still_requires_a_name(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/forms', [
                'is_anonymous' => false,
                'content' => $this->validContent(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }
}
