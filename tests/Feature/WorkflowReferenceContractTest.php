<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workflows\Services\WorkflowTriggerPayloadFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\Concerns\FakesBotExecutionAgent;
use Tests\TestCase;

/**
 * EDITOR ↔ ENGINE contract lock.
 *
 * The frontend step editor offers `{{trigger.*}}` reference chips from a static catalog.
 * Those chips are only correct if the BACKEND trigger payload actually exposes the path each
 * chip inserts. This test PINS the payload shape so a backend refactor of
 * WorkflowTriggerPayloadFactory can't silently break a live FE chip.
 *
 * Each assertion mirrors a token in the FE catalog: `{{trigger.<path>}}` ⇒ Arr::has(payload,
 * '<path>'). It intentionally asserts KEY PRESENCE (the contract), not the scalar values
 * (those are covered by WorkflowDispatchTest).
 *
 * NOTE (Etap 5.1 re-scope): only form_submitted and schedule survive as trigger types; the
 * task/approval payload contracts were removed with their triggers.
 */
class WorkflowReferenceContractTest extends TestCase
{
    use FakesBotExecutionAgent, RefreshDatabase;

    private WorkflowTriggerPayloadFactory $payloads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payloads = app(WorkflowTriggerPayloadFactory::class);
    }

    /**
     * Assert every dot-path is present in the payload — the FE inserts `{{trigger.<path>}}`
     * chips for exactly these, so a missing key means a dead reference chip.
     *
     * @param  array<int, string>  $paths
     */
    private function assertPayloadExposes(array $payload, array $paths): void
    {
        foreach ($paths as $path) {
            $this->assertTrue(
                Arr::has($payload, $path),
                "trigger payload must expose `{{trigger.{$path}}}` (FE reference catalog contract)",
            );
        }
    }

    public function test_form_submitted_payload_exposes_the_reshaped_reference_chips(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);
        $task = Task::factory()->create(['creator_id' => $owner->id, 'assigned_id' => $owner->id]);

        // A Task-attached submission so the payload's task snapshot is present and source=task.
        $submission = FormSubmission::factory()->create([
            'form_id' => $form->id,
            'creator_id' => $owner->id,
            'submittable_type' => $task->getMorphClass(),
            'submittable_id' => $task->id,
            'data' => ['field-a' => 'Answer A'],
            'approved_at' => now(),
        ]);

        $payload = $this->payloads->fromFormSubmission($submission);

        // The reshaped form_submitted contract: submission/form ids, the form name +
        // anonymous flag, the normalized source, the approved timestamp, the whitelisted
        // fields map (keyed by field id), and the task snapshot (task-attached only).
        $this->assertPayloadExposes($payload, [
            'submission.id',
            'form.id',
            'form.name',
            'form.is_anonymous',
            'source',
            'submitted_at',
            'fields.field-a',
            'task.id',
        ]);
    }

    public function test_form_submitted_manual_source_carries_no_task(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);

        // A manual (Form) submission: source=manual, task=null.
        $submission = FormSubmission::factory()->create([
            'form_id' => $form->id,
            'creator_id' => $owner->id,
            'submittable_type' => $form->getMorphClass(),
            'submittable_id' => $form->id,
            'data' => ['q1' => 'v1'],
            'approved_at' => now(),
        ]);

        $payload = $this->payloads->fromFormSubmission($submission);

        $this->assertSame('manual', $payload['source']);
        $this->assertNull($payload['task']);
        $this->assertSame('v1', $payload['fields']['q1']);
    }

    public function test_schedule_payload_exposes_top_level_scheduled_at(): void
    {
        // FE schedule chip: {{trigger.scheduled_at}} (the only reference a cadence run exposes).
        $payload = $this->payloads->fromSchedule(now());

        $this->assertPayloadExposes($payload, ['scheduled_at']);
    }

    // ---- Editor ↔ engine: the FORM-LESS catalog side of the same contract --------
    //
    // The pins above lock the ENGINE (trigger payload) side: every FE chip's path is a real
    // payload key. These lock the EDITOR side for a FORM-LESS workflow: the form-independent
    // catalog (GET /workflows/catalog) — the editor's catalog source when no form is selected
    // (e.g. a schedule trigger) — carries a `trigger.<path>` variable for each trigger-SYSTEM
    // payload path. (Field paths `fields.*` are form-dependent, and `form.is_anonymous` is a
    // trigger-FILTER key, not a referenceable variable — neither is a system catalog var.)

    /** The `variables[].path` set of the form-less catalog for a trigger type (authenticated). */
    private function formlessCatalogPaths(string $triggerType): \Illuminate\Support\Collection
    {
        $response = $this->actingAs(User::factory()->create())
            ->getJson("/api/workflows/catalog?trigger_type={$triggerType}")
            ->assertOk();

        return collect($response->json('data.variables'))->pluck('path');
    }

    public function test_formless_schedule_catalog_exposes_the_schedule_reference_chip(): void
    {
        // Engine pins `scheduled_at`; the form-less schedule catalog must carry `trigger.scheduled_at`.
        $this->assertTrue($this->formlessCatalogPaths('schedule')->contains('trigger.scheduled_at'));
    }

    public function test_formless_form_submitted_catalog_exposes_the_trigger_system_reference_chips(): void
    {
        $paths = $this->formlessCatalogPaths('form_submitted');

        // Every trigger-SYSTEM payload path the engine pins, as its `trigger.<path>` catalog var.
        foreach (['submission.id', 'form.id', 'form.name', 'source', 'submitted_at', 'task.id'] as $systemPath) {
            $this->assertTrue(
                $paths->contains("trigger.{$systemPath}"),
                "form-less catalog must expose `trigger.{$systemPath}` (editor↔engine chip contract)",
            );
        }
    }
}
