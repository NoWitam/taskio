<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowTriggerPayloadFactory;
use App\Modules\Workflows\Steps\CreateTaskStep;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesBotExecutionAgent;
use Tests\TestCase;

/**
 * R1-B4c — file as a first-class workflow value. Two seams:
 *   - the trigger payload enriches a raw file-uuid answer into the snapshot list the FILE
 *     variable speaks ({id, name, mime_type, size}), so `{{trigger.fields.<id>}}` and the file
 *     condition operators see a real file;
 *   - the create_task step attaches a file by COPY (copy-on-attach), never by rebinding — the
 *     source (a submission's file, or a Disk pick) keeps its owner.
 */
class WorkflowFileAttachmentTest extends TestCase
{
    use FakesBotExecutionAgent, RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /** A file that lives on the disk with real bytes (so a copy assertion is not vacuous). */
    private function diskFile(array $attributes = []): File
    {
        $file = File::factory()->inFolder(Folder::factory()->create())->create($attributes + [
            'uploader_id' => $this->user->id,
        ]);
        Storage::put($file->path, 'the-bytes');

        return $file;
    }

    private function runRow(): WorkflowRun
    {
        return WorkflowRun::factory()->running()->create([
            'workflow_id' => Workflow::factory()->create([
                'creator_id' => $this->user->id,
                'workspace_id' => $this->workspace->id,
            ])->id,
        ]);
    }

    // ---- payload enrichment ---------------------------------------------------

    public function test_a_file_answer_is_enriched_into_a_snapshot_list(): void
    {
        $form = Form::factory()->enabled()->create([
            'creator_id' => $this->user->id,
            'workspace_id' => $this->workspace->id,
            'content' => [['id' => 'attachment', 'type' => 'image', 'config' => ['label' => 'Attachment']]],
        ]);
        $file = $this->diskFile(['name' => 'raport.pdf', 'mime_type' => 'application/pdf', 'size' => 1234]);

        $submission = FormSubmission::factory()->create([
            'form_id' => $form->id,
            'workspace_id' => $this->workspace->id,
            'data' => ['attachment' => $file->id],
            'approved_at' => now(),
        ]);

        $payload = app(WorkflowTriggerPayloadFactory::class)->fromFormSubmission($submission);

        $this->assertSame([[
            'id' => $file->id,
            'name' => 'raport.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1234,
        ]], $payload['fields']['attachment']);
    }

    public function test_an_empty_file_answer_enriches_to_an_empty_list(): void
    {
        $form = Form::factory()->enabled()->create([
            'creator_id' => $this->user->id,
            'workspace_id' => $this->workspace->id,
            'content' => [['id' => 'attachment', 'type' => 'image', 'config' => ['label' => 'Attachment']]],
        ]);

        $submission = FormSubmission::factory()->create([
            'form_id' => $form->id,
            'workspace_id' => $this->workspace->id,
            'data' => ['attachment' => ''],
            'approved_at' => now(),
        ]);

        $payload = app(WorkflowTriggerPayloadFactory::class)->fromFormSubmission($submission);

        $this->assertSame([], $payload['fields']['attachment']);
    }

    // ---- copy-on-attach -------------------------------------------------------

    public function test_a_literal_file_attachment_is_copied_onto_the_task(): void
    {
        $source = $this->diskFile(['name' => 'brief.pdf', 'mime_type' => 'application/pdf', 'size' => 9]);

        $output = app(CreateTaskStep::class)->run([
            'title' => 'With attachment',
            'attachments' => ['kind' => 'literal', 'value' => $source->id],
        ], $this->runRow(), []);

        $task = Task::findOrFail($output['task_id']);
        $copies = $task->files()->get();

        $this->assertCount(1, $copies);
        $copy = $copies->first();

        // A COPY: new row + new blob, but the same content and name.
        $this->assertNotSame($source->id, $copy->id);
        $this->assertNotSame($source->path, $copy->path);
        $this->assertSame('brief.pdf', $copy->name);
        $this->assertSame('the-bytes', Storage::get($copy->path));

        // The source is untouched — still a DISK file (its container is a folder), not stolen
        // into the task (copy-on-attach duplicates rather than rebinding).
        $source->refresh();
        $this->assertFalse($source->isOwnedByResource());
        $this->assertTrue(Storage::exists($source->path));
    }

    public function test_a_file_variable_attachment_is_copied_onto_the_task(): void
    {
        $source = $this->diskFile(['name' => 'from-var.png', 'mime_type' => 'image/png', 'size' => 9]);

        $context = [
            'trigger' => [
                'fields' => [
                    'attachment' => [[
                        'id' => $source->id,
                        'name' => $source->name,
                        'mime_type' => $source->mime_type,
                        'size' => $source->size,
                    ]],
                ],
            ],
            'steps' => [],
        ];

        $output = app(CreateTaskStep::class)->run([
            'title' => 'From a file variable',
            'attachments' => ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.attachment', 'type' => 'file']],
        ], $this->runRow(), $context);

        $task = Task::findOrFail($output['task_id']);
        $copy = $task->files()->first();

        $this->assertNotNull($copy);
        $this->assertNotSame($source->id, $copy->id);
        $this->assertSame('from-var.png', $copy->name);
    }

    public function test_no_attachments_config_creates_a_task_with_no_files(): void
    {
        $output = app(CreateTaskStep::class)->run([
            'title' => 'No attachment',
        ], $this->runRow(), []);

        $task = Task::findOrFail($output['task_id']);
        $this->assertCount(0, $task->files()->get());
    }

    public function test_an_unresolvable_attachment_id_is_skipped(): void
    {
        // A well-formed uuid that resolves to no file — the task is still created, no copy made.
        $output = app(CreateTaskStep::class)->run([
            'title' => 'Ghost attachment',
            'attachments' => ['kind' => 'literal', 'value' => '019f70c4-9961-7297-a0af-5dfd18146322'],
        ], $this->runRow(), []);

        $task = Task::findOrFail($output['task_id']);
        $this->assertCount(0, $task->files()->get());
    }
}
