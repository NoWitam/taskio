<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Approvals\Enums\ApproverType;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Approvals\Models\ApprovalProcess;
use App\Modules\Bot\Models\Bot;
use App\Modules\Disk\Enums\FileType;
use App\Modules\Disk\Models\File;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SECURITY REGRESSION MATRIX for workspace-scoped route-model binding.
 *
 * ResolveWorkspace now runs BEFORE SubstituteBindings (bootstrap/app.php priority reorder), so a
 * shared-DB {model} id that belongs to ANOTHER workspace is filtered out by WorkspaceScope AT BIND
 * and 404s before any controller/policy runs — app-wide. This proves that guarantee per module for
 * a Sanctum-authenticated member of workspace A (sending `X-Workspace-Id: A`) hitting a workspace-B
 * id, and — the other half of the guarantee — that the scope does NOT over-block a member reaching
 * their OWN workspace-A records.
 *
 * Workspace-B models are built with B set as the ACTIVE shared tenant (see {@see within()}), which
 * is exactly what ResolveWorkspace does for a real request: the TenantAware `creating` hook stamps
 * workspace_id = B. The acting user is a member of A only.
 */
class CrossWorkspaceBindingTest extends TestCase
{
    use RefreshDatabase;

    /** A shared workspace owned by (and with) $user as a member. */
    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    /**
     * Run $build with $workspace as the ACTIVE shared tenant so every model created inside is
     * stamped workspace_id = $workspace->id (TenantAware::creating) and any nested query is
     * WorkspaceScope-filtered — the same state ResolveWorkspace installs for a live request,
     * without going through HTTP.
     *
     * @template T
     *
     * @param  Closure():T  $build
     * @return T
     */
    private function within(Workspace $workspace, Closure $build)
    {
        $context = app(TenantContext::class);
        $context->set($workspace);

        try {
            return $build();
        } finally {
            $context->clear();
        }
    }

    // ---- Workflows ------------------------------------------------------------

    public function test_workflow_and_run_endpoints_404_a_cross_workspace_id(): void
    {
        Queue::fake();

        $member = User::factory()->create();
        $workspaceA = $this->workspaceFor($member);

        $ownerB = User::factory()->create();
        $workspaceB = $this->workspaceFor($ownerB);

        [$workflowB, $runB] = $this->within($workspaceB, function () use ($ownerB) {
            $workflow = Workflow::factory()->create(['creator_id' => $ownerB->id]);
            $run = WorkflowRun::factory()->failed()->create(['workflow_id' => $workflow->id]);

            return [$workflow, $run];
        });

        // Guard the setup: the B models really carry workspace B (not null, not A).
        $this->assertSame($workspaceB->id, $workflowB->workspace_id);
        $this->assertSame($workspaceB->id, $runB->workspace_id);

        $this->actingAs($member)->withHeader('X-Workspace-Id', $workspaceA->id);

        $this->getJson("/api/workflows/{$workflowB->id}")->assertNotFound();
        $this->postJson("/api/workflows/{$workflowB->id}/run")->assertNotFound();
        $this->getJson("/api/workflows/{$workflowB->id}/runs")->assertNotFound();
        $this->getJson("/api/workflows/{$workflowB->id}/runs/{$runB->id}")->assertNotFound();
        $this->postJson("/api/workflows/{$workflowB->id}/runs/{$runB->id}/retry")->assertNotFound();
    }

    // ---- Disk (was an arbitrary cross-tenant download) ------------------------

    public function test_disk_download_404s_a_cross_workspace_file(): void
    {
        $member = User::factory()->create();
        $workspaceA = $this->workspaceFor($member);

        $ownerB = User::factory()->create();
        $workspaceB = $this->workspaceFor($ownerB);

        $fileB = $this->within($workspaceB, fn () => File::create([
            'name' => 'secret.pdf',
            'path' => 'uploads/' . Str::uuid() . '.pdf',
            'type' => FileType::DOCUMENT,
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'uploader_id' => $ownerB->id,
        ]));

        $this->assertSame($workspaceB->id, $fileB->workspace_id);

        $this->actingAs($member)->withHeader('X-Workspace-Id', $workspaceA->id)
            ->getJson("/api/disk/{$fileB->id}")
            ->assertNotFound();
    }

    // ---- Forms ----------------------------------------------------------------

    public function test_form_endpoints_404_a_cross_workspace_form(): void
    {
        $member = User::factory()->create();
        $workspaceA = $this->workspaceFor($member);

        $ownerB = User::factory()->create();
        $workspaceB = $this->workspaceFor($ownerB);

        $formB = $this->within($workspaceB, fn () => Form::factory()->enabled()->create([
            'creator_id' => $ownerB->id,
        ]));

        $this->assertSame($workspaceB->id, $formB->workspace_id);

        $this->actingAs($member)->withHeader('X-Workspace-Id', $workspaceA->id);

        $this->getJson("/api/forms/{$formB->id}/preview")->assertNotFound();
        $this->deleteJson("/api/forms/{$formB->id}")->assertNotFound();
        $this->deleteJson("/api/forms/{$formB->id}/force")->assertNotFound();
    }

    public function test_form_submission_endpoints_404_a_cross_workspace_submission(): void
    {
        $member = User::factory()->create();
        $workspaceA = $this->workspaceFor($member);

        $ownerB = User::factory()->create();
        $workspaceB = $this->workspaceFor($ownerB);

        $submissionB = $this->within($workspaceB, fn () => FormSubmission::factory()->create([
            'creator_id' => $ownerB->id,
        ]));

        $this->assertSame($workspaceB->id, $submissionB->workspace_id);

        $this->actingAs($member)->withHeader('X-Workspace-Id', $workspaceA->id);

        $this->putJson("/api/form-submissions/{$submissionB->id}", ['data' => ['field' => 'x']])
            ->assertNotFound();
        $this->deleteJson("/api/form-submissions/{$submissionB->id}")->assertNotFound();
    }

    // ---- Bot ------------------------------------------------------------------

    public function test_bot_endpoints_404_a_cross_workspace_bot(): void
    {
        $member = User::factory()->create();
        $workspaceA = $this->workspaceFor($member);

        $ownerB = User::factory()->create();
        $workspaceB = $this->workspaceFor($ownerB);

        $botB = $this->within($workspaceB, fn () => Bot::factory()->create(['creator_id' => $ownerB->id]));

        $this->assertSame($workspaceB->id, $botB->workspace_id);

        $this->actingAs($member)->withHeader('X-Workspace-Id', $workspaceA->id);

        $this->getJson("/api/bots/{$botB->id}")->assertNotFound();
        $this->getJson("/api/bots/{$botB->id}/inbox")->assertNotFound();
        $this->getJson("/api/bots/{$botB->id}/actions")->assertNotFound();
    }

    // ---- Approvals ------------------------------------------------------------

    public function test_approval_endpoints_404_a_cross_workspace_pipeline_and_process(): void
    {
        $member = User::factory()->create();
        $workspaceA = $this->workspaceFor($member);

        $ownerB = User::factory()->create();
        $workspaceB = $this->workspaceFor($ownerB);

        [$pipelineB, $processB] = $this->within($workspaceB, function () use ($ownerB) {
            $pipeline = ApprovalPipeline::factory()->create(['creator_id' => $ownerB->id]);

            // approvable is never dereferenced (the request 404s at bind); a valid morph string +
            // uuid is all the row needs. approval_stage_id is nullable since a later migration.
            $process = ApprovalProcess::create([
                'run_id' => (string) Str::uuid(),
                'approval_pipeline_id' => $pipeline->id,
                'approval_stage_id' => null,
                'approvable_type' => 'task',
                'approvable_id' => (string) Str::uuid(),
                'approver_type' => ApproverType::User->value,
                'approver_id' => $ownerB->id,
                'status' => ApprovalProcessStatus::Pending->value,
                'creator_id' => $ownerB->id,
            ]);

            return [$pipeline, $process];
        });

        $this->assertSame($workspaceB->id, $pipelineB->workspace_id);
        $this->assertSame($workspaceB->id, $processB->workspace_id);

        $this->actingAs($member)->withHeader('X-Workspace-Id', $workspaceA->id);

        $this->getJson("/api/approval-pipelines/{$pipelineB->id}")->assertNotFound();
        $this->getJson("/api/approvals/processes/{$processB->id}")->assertNotFound();
    }

    // ---- Happy path: no over-scoping of the member's OWN workspace ------------

    public function test_member_reaches_their_own_workspace_a_records(): void
    {
        Queue::fake();

        $member = User::factory()->create();
        $workspaceA = $this->workspaceFor($member);

        [$workflow, $scheduled, $run, $form, $bot, $pipeline] = $this->within($workspaceA, function () use ($member) {
            $workflow = Workflow::factory()->create(['creator_id' => $member->id]);
            $scheduled = Workflow::factory()->scheduled()->create(['creator_id' => $member->id]);
            $run = WorkflowRun::factory()->completed()->create(['workflow_id' => $workflow->id]);
            $form = Form::factory()->enabled()->create(['creator_id' => $member->id]);
            $bot = Bot::factory()->create(['creator_id' => $member->id]);
            $pipeline = ApprovalPipeline::factory()->create(['creator_id' => $member->id]);

            return [$workflow, $scheduled, $run, $form, $bot, $pipeline];
        });

        $this->actingAs($member)->withHeader('X-Workspace-Id', $workspaceA->id);

        // Reads bind and return the record.
        $this->getJson("/api/workflows/{$workflow->id}")->assertOk();
        $this->getJson("/api/workflows/{$workflow->id}/runs")->assertOk();
        $this->getJson("/api/workflows/{$workflow->id}/runs/{$run->id}")->assertOk();
        $this->getJson("/api/forms/{$form->id}/preview")->assertOk();
        $this->getJson("/api/bots/{$bot->id}")->assertOk();
        $this->getJson("/api/approval-pipelines/{$pipeline->id}")->assertOk();

        // A WRITE also binds correctly (proves the scope doesn't block a legitimate POST): a manual
        // run of the member's own scheduled workflow is accepted (202) and its job is queued.
        $this->postJson("/api/workflows/{$scheduled->id}/run")->assertAccepted();
    }
}
