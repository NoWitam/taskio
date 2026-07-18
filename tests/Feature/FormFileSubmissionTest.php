<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Services\ResourceFolderRegistry;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * R1-B4 — the form file input (wire type 'image', now a real single-file upload). A file answer
 * is a Disk File uuid: it must resolve to a live, workspace-scoped file the submitter is allowed
 * to reference, and on success it is promoted from a temp upload to being owned by the submission
 * (so it survives the temp sweep and appears under the disk's read-only "Zasoby" tree).
 */
class FormFileSubmissionTest extends TestCase
{
    use RefreshDatabase;

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

    private function fileForm(): Form
    {
        return Form::factory()->enabled()->create([
            'creator_id' => $this->user->id,
            'workspace_id' => $this->workspace->id,
            'content' => [
                ['id' => 'attachment', 'type' => 'image', 'config' => ['label' => 'Attachment']],
            ],
        ]);
    }

    /** A temp upload (fileable_* null) owned by the given user, with bytes on the fake disk. */
    private function tempFile(User $uploader): File
    {
        $file = File::factory()->create([
            'uploader_id' => $uploader->id,
            'fileable_id' => null,
            'fileable_type' => null,
            'folder_id' => null,
        ]);
        Storage::put($file->path, 'bytes');

        return $file;
    }

    public function test_a_valid_own_file_answer_is_bound_to_the_submission(): void
    {
        $form = $this->fileForm();
        $file = $this->tempFile($this->user);

        $response = $this->postJson('/api/form-submissions', [
            'form_id' => $form->id,
            'data' => ['attachment' => $file->id],
        ]);

        $response->assertCreated();

        $submission = FormSubmission::query()->firstOrFail();
        $file->refresh();

        // The temp is now owned by the submission (its morph alias is 'form_submission').
        $this->assertSame($submission->getMorphClass(), $file->fileable_type);
        $this->assertSame($submission->id, $file->fileable_id);
    }

    public function test_a_bound_file_surfaces_under_the_resources_tree(): void
    {
        $form = $this->fileForm();
        $file = $this->tempFile($this->user);

        $this->postJson('/api/form-submissions', [
            'form_id' => $form->id,
            'data' => ['attachment' => $file->id],
        ])->assertCreated();

        // form_submission is a registered virtual-folder type, so its files show on the disk.
        $this->assertTrue(ResourceFolderRegistry::isRegistered('form_submission'));

        $tree = app(\App\Modules\Disk\Services\FileService::class)->resourceTree();
        $types = array_column($tree, 'type');
        $this->assertContains('form_submission', $types);
    }

    public function test_another_users_temp_file_is_rejected(): void
    {
        $form = $this->fileForm();
        $stranger = User::factory()->create();
        $foreignTemp = $this->tempFile($stranger);

        $response = $this->postJson('/api/form-submissions', [
            'form_id' => $form->id,
            'data' => ['attachment' => $foreignTemp->id],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['data.attachment']);

        // The foreign temp was NOT stolen into a submission.
        $foreignTemp->refresh();
        $this->assertNull($foreignTemp->fileable_type);
        $this->assertSame(0, FormSubmission::query()->count());
    }

    public function test_a_non_uuid_file_answer_is_rejected(): void
    {
        $form = $this->fileForm();

        $this->postJson('/api/form-submissions', [
            'form_id' => $form->id,
            'data' => ['attachment' => 'not-a-uuid'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['data.attachment']);
    }

    public function test_a_missing_file_id_is_rejected(): void
    {
        $form = $this->fileForm();

        // A random uuid that resolves to no file in the workspace.
        $this->postJson('/api/form-submissions', [
            'form_id' => $form->id,
            'data' => ['attachment' => '019f70c4-9961-7297-a0af-5dfd18146322'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['data.attachment']);
    }

    public function test_an_empty_file_answer_is_allowed(): void
    {
        $form = $this->fileForm();

        // An unanswered file field is simply absent/empty; requiredness is a content concern.
        $this->postJson('/api/form-submissions', [
            'form_id' => $form->id,
            'data' => ['attachment' => ''],
        ])->assertCreated();

        $this->assertSame(1, FormSubmission::query()->count());
    }
}
