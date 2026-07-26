<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Changelog\Models\Changelog;
use App\Modules\Disk\Enums\FileType;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Preview P1 — the file INFO endpoint and content REPLACEMENT ("Zapisz" = overwrite).
 *
 * The invariants worth pinning: /info is JSON (the bare GET /{file} serves the binary and can't
 * double as metadata); replace swaps the blob + content-derived columns while the row keeps its
 * IDENTITY (name/placement/labels/uploader); the old blob is gone, the new one serves; the swap
 * is audited as a content_replaced changelog entry; and a resource-owned file can never be
 * overwritten from the disk (its bytes belong to the owning module — "Zapisz jako" instead).
 */
class DiskFileContentTest extends TestCase
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

    /** A disk file with real bytes (the factory only makes the row). */
    private function diskFile(array $attributes = []): File
    {
        $file = File::factory()->inFolder(Folder::factory()->create())->create($attributes);
        Storage::put($file->path, 'original-bytes');

        return $file;
    }

    /** Build models as another workspace's tenant (mirrors FolderApiTest). */
    private function within(Workspace $workspace, Closure $build)
    {
        $context = app(TenantContext::class);
        $context->set($workspace);

        try {
            return $build();
        } finally {
            $context->set($this->workspace);
        }
    }

    // ---- GET /disk/{file}/info --------------------------------------------------

    public function test_info_returns_the_file_metadata_as_json(): void
    {
        $file = $this->diskFile(['name' => 'raport.pdf', 'description' => 'opis']);

        $this->getJson('/api/disk/' . $file->id . '/info')
            ->assertOk()
            ->assertJsonPath('data.id', $file->id)
            ->assertJsonPath('data.name', 'raport.pdf')
            ->assertJsonPath('data.description', 'opis')
            ->assertJsonPath('data.folder_id', $file->fileable_id)
            // Eager-loaded relations + capability flags ride along (the preview's contract).
            ->assertJsonPath('data.can_be_updated', true)
            ->assertJsonStructure(['data' => ['labels', 'folder', 'source', 'size_human']]);
    }

    public function test_info_404s_for_trashed_and_foreign_files(): void
    {
        $trashed = $this->diskFile();
        $trashed->disk_trashed_at = now();
        $trashed->save();
        $trashed->delete();

        $this->getJson('/api/disk/' . $trashed->id . '/info')->assertNotFound();

        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $otherOwner->id]);
        $foreign = $this->within($otherWorkspace, fn () => File::factory()->atRoot()->create());

        $this->getJson('/api/disk/' . $foreign->id . '/info')->assertNotFound();
    }

    // ---- POST /disk/{file}/content ----------------------------------------------

    public function test_replacing_content_swaps_the_blob_and_keeps_the_files_identity(): void
    {
        $file = $this->diskFile(['name' => 'notatka.txt', 'description' => 'moja notatka']);
        $oldPath = $file->path;
        $folderId = $file->fileable_id;
        $uploaderId = $file->uploader_id;

        $response = $this->post('/api/disk/' . $file->id . '/content', [
            'file' => UploadedFile::fake()->createWithContent('ignored-name.txt', 'NEW CONTENT'),
        ]);

        $response->assertOk()
            // Identity untouched: the row is still "notatka.txt" in the same folder.
            ->assertJsonPath('data.name', 'notatka.txt')
            ->assertJsonPath('data.description', 'moja notatka')
            ->assertJsonPath('data.folder_id', $folderId);

        $file->refresh();
        $this->assertNotSame($oldPath, $file->path, 'The blob must move to a fresh uuid path.');
        $this->assertSame($uploaderId, $file->uploader_id, 'The uploader must not be restamped.');
        $this->assertSame(strlen('NEW CONTENT'), $file->size);

        // Old blob gone, new blob present and SERVED by the binary endpoint.
        $this->assertFalse(Storage::exists($oldPath));
        $this->assertTrue(Storage::exists($file->path));
        $this->assertSame('NEW CONTENT', Storage::get($file->path));
        $this->get('/api/disk/' . $file->id)->assertOk();
    }

    public function test_replacing_content_recomputes_the_content_derived_columns(): void
    {
        // A pdf DOCUMENT overwritten with png bytes becomes an IMAGE — mime/type follow the
        // ACTUAL bytes, never the old row or the client filename.
        $file = $this->diskFile();
        $this->assertSame(FileType::DOCUMENT, $file->type);

        $this->post('/api/disk/' . $file->id . '/content', [
            'file' => UploadedFile::fake()->image('edited.png'),
        ])->assertOk();

        $file->refresh();
        $this->assertSame(FileType::IMAGE, $file->type);
        $this->assertSame('image/png', $file->mime_type);
    }

    public function test_replacing_content_is_audited_as_a_content_replaced_entry(): void
    {
        $file = $this->diskFile();

        $this->post('/api/disk/' . $file->id . '/content', [
            'file' => UploadedFile::fake()->createWithContent('x.txt', 'abc'),
        ])->assertOk();

        $entry = Changelog::query()
            ->where('subject_type', 'file')
            ->where('subject_id', $file->id)
            ->where('event', 'content_replaced')
            ->first();

        $this->assertNotNull($entry, 'A content replacement must land in the changelog.');
        $this->assertSame(3, $entry->details['content']['size']);
        $this->assertArrayHasKey('previous_size', $entry->details['content']);

        // And the generic history endpoint surfaces it with its description key.
        $history = $this->getJson('/api/file/' . $file->id . '/changelog')->assertOk();
        $this->assertContains(
            'changelog.contentReplaced',
            collect($history->json('data'))->pluck('event_description')->all(),
        );
    }

    public function test_only_the_owner_may_replace_content(): void
    {
        $file = $this->diskFile();

        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id);

        $this->actingAs($member)->withHeader('X-Workspace-Id', $this->workspace->id)
            ->post('/api/disk/' . $file->id . '/content', [
                'file' => UploadedFile::fake()->createWithContent('x.txt', 'abc'),
            ])
            ->assertForbidden();
    }

    public function test_a_resource_owned_file_cannot_be_overwritten(): void
    {
        // A task attachment's bytes belong to the task — the disk may only "Zapisz jako" (copy).
        $task = Task::factory()->create();
        $attachment = File::factory()->attachedTo($task)->create();
        Storage::put($attachment->path, 'attachment-bytes');

        $this->postJson('/api/disk/' . $attachment->id . '/content', [
            'file' => UploadedFile::fake()->createWithContent('x.txt', 'abc'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame('attachment-bytes', Storage::get($attachment->fresh()->path));
    }

    public function test_replace_validates_the_upload(): void
    {
        $file = $this->diskFile();

        $this->postJson('/api/disk/' . $file->id . '/content', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->postJson('/api/disk/' . $file->id . '/content', [
            'file' => UploadedFile::fake()->create('big.bin', 102401),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }
}
