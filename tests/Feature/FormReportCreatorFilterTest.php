<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormReport;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase 4 of the polymorphic `creator` refactor: the report list's "created by me" filter
 * (FormReportService::indexByForm) means a HUMAN creator only. A report authored by a workflow
 * run is a system record (creator_type='workflow_run') owned by nobody, so it must NEVER surface
 * under a `creator_id` filter. The service guards that filter with `where('creator_type', 'user')`,
 * so even an id that happens to equal the run's creator_id cannot leak the system report — the
 * observable behaviour these tests pin through the list endpoint.
 */
class FormReportCreatorFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // FormReport::created dispatches CreateFormReport; it must not run in tests.
        Queue::fake();
    }

    /**
     * A genuine user-created report: HasCreator stamps creator_type='user' from the explicit
     * creator_id, exactly as an HTTP-authored report would be.
     */
    private function userReport(Form $form, User $author): FormReport
    {
        return FormReport::create([
            'form_id' => $form->id,
            'name' => 'Manual report',
            'sources' => ['form'],
            'submissions_from' => now()->subWeek(),
            'submissions_to' => now(),
            'creator_id' => $author->id,
        ]);
    }

    /**
     * A run-created ("system") report. FormReport's fillable exposes creator_id but NOT
     * creator_type, so — exactly as the engine stamps it — the workflow_run creator is written at
     * the DB level after create.
     *
     * @return array{0: FormReport, 1: WorkflowRun}
     */
    private function runReport(Form $form, User $workflowAuthor): array
    {
        $workflow = Workflow::factory()->create(['creator_id' => $workflowAuthor->id]);
        $run = WorkflowRun::factory()->create(['workflow_id' => $workflow->id]);

        $report = FormReport::create([
            'form_id' => $form->id,
            'name' => 'Automated report',
            'sources' => ['form'],
            'submissions_from' => now()->subWeek(),
            'submissions_to' => now(),
            // A placeholder to satisfy the NOT NULL creator_id at insert; overwritten with the run
            // below so the stored creator is the workflow_run, as the engine would stamp it.
            'creator_id' => $workflowAuthor->id,
        ]);

        DB::table('form_reports')->where('id', $report->id)->update([
            'creator_type' => 'workflow_run',
            'creator_id' => $run->id,
        ]);

        return [$report->refresh(), $run];
    }

    /**
     * The report ids the list endpoint returns for $actor, optionally under a query filter.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, string>
     */
    private function listedIds(User $actor, Form $form, array $query = []): array
    {
        return collect(
            $this->actingAs($actor)
                ->getJson('/api/forms/' . $form->id . '/reports?' . http_build_query($query))
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();
    }

    public function test_unfiltered_list_returns_both_user_and_run_created_reports(): void
    {
        $author = User::factory()->create();
        $form = Form::factory()->enabled()->create(['creator_id' => $author->id]);

        $userReport = $this->userReport($form, $author);
        [$runReport] = $this->runReport($form, $author);

        // Baseline: with no creator filter the system report is perfectly visible, so its later
        // absence under a filter is the filter's doing rather than some unrelated scoping.
        $ids = $this->listedIds($author, $form);

        $this->assertContains($userReport->id, $ids);
        $this->assertContains($runReport->id, $ids);
    }

    public function test_human_creator_filter_excludes_a_run_created_report(): void
    {
        $author = User::factory()->create();
        $form = Form::factory()->enabled()->create(['creator_id' => $author->id]);

        $userReport = $this->userReport($form, $author);
        [$runReport] = $this->runReport($form, $author);

        // "Created by me" for the workflow's human author returns only the report they authored by
        // hand; the run-created report — a system record — is filtered out.
        $ids = $this->listedIds($author, $form, ['creator_id' => [$author->id]]);

        $this->assertContains($userReport->id, $ids);
        $this->assertNotContains($runReport->id, $ids);
    }

    public function test_creator_type_guard_hides_a_run_report_even_when_the_filter_matches_its_id(): void
    {
        $author = User::factory()->create();
        $form = Form::factory()->enabled()->create(['creator_id' => $author->id]);

        $userReport = $this->userReport($form, $author);
        [$runReport, $run] = $this->runReport($form, $author);

        // Filtering by the RUN's own creator_id is the only case that isolates the
        // creator_type='user' guard: the id matches the row, so ONLY the type gate can exclude it.
        // The system report must still be hidden.
        $ids = $this->listedIds($author, $form, ['creator_id' => [$run->id]]);

        $this->assertNotContains($runReport->id, $ids);
        $this->assertNotContains($userReport->id, $ids); // different id — also absent
    }
}
