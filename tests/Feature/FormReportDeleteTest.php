<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FormReportDeleteTest extends TestCase
{
    use RefreshDatabase;

    /** The form creator must be the acting user (delete policy = form owner). */
    private function report(Form $form): FormReport
    {
        return FormReport::create([
            'form_id' => $form->id,
            'name' => 'Report',
            'sources' => ['form'],
            'submissions_from' => now()->subWeek(),
            'submissions_to' => now(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // The CreateFormReport job is dispatched on create — don't run it in tests.
        Queue::fake();
    }

    public function test_report_can_be_soft_deleted_by_form_owner(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Form::factory()->enabled()->create(['creator_id' => $user->id]);
        $report = $this->report($form);

        $this->deleteJson("/api/form-reports/{$report->id}")->assertNoContent();
        $this->assertSoftDeleted('form_reports', ['id' => $report->id]);
    }

    public function test_trashed_report_can_be_restored(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Form::factory()->enabled()->create(['creator_id' => $user->id]);
        $report = $this->report($form);
        $report->delete();

        $this->postJson("/api/form-reports/{$report->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $report->id);
        $this->assertNotSoftDeleted('form_reports', ['id' => $report->id]);
    }

    public function test_trashed_report_can_be_force_deleted(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Form::factory()->enabled()->create(['creator_id' => $user->id]);
        $report = $this->report($form);
        $report->delete();

        $this->deleteJson("/api/form-reports/{$report->id}/force")->assertNoContent();
        $this->assertDatabaseMissing('form_reports', ['id' => $report->id]);
    }

    public function test_non_owner_cannot_delete_a_report(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Form::factory()->enabled()->create(['creator_id' => $user->id]);
        $report = $this->report($form);

        $stranger = User::factory()->create();
        $this->actingAs($stranger)
            ->deleteJson("/api/form-reports/{$report->id}")
            ->assertForbidden();
        $this->assertNotSoftDeleted('form_reports', ['id' => $report->id]);
    }
}
