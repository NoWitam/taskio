<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Enums\FileType;
use App\Modules\Disk\Models\DiskFileDraft;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Modules\Disk\Services\FileService;
use App\Modules\Labels\Models\Label;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * R1-B2 — the files API: browsing, uploading, metadata, and the disk's own trash.
 *
 * The subtle half is the TRASH. Detaching a task attachment already soft-deletes its file, so
 * the disk needs a marker of its own to answer "what did I throw away HERE" — otherwise the
 * trash would fill with every attachment anyone ever removed. And a restore always severs the
 * file from its old parent, which is why a detached file must be given somewhere to land.
 */
class FileApiTest extends TestCase
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

    /**
     * A file that lives on the disk (as opposed to a temp upload or an attachment), WITH its
     * bytes — the factory only makes the row, so a storage assertion against a factory-made
     * file would otherwise pass vacuously.
     */
    private function diskFile(array $attributes = []): File
    {
        $file = File::factory()->inFolder(Folder::factory()->create())->create($attributes);
        Storage::put($file->path, 'bytes');

        return $file;
    }

    /**
     * A per-user autosave draft row for $file. There is no DiskFileDraft factory — the flag only
     * needs the row (workspace_id is stamped by TenantAware from the active tenant context).
     */
    private function draftFor(File $file, User $user): DiskFileDraft
    {
        return DiskFileDraft::create([
            'file_id' => $file->id,
            'user_id' => $user->id,
            'kind' => 'image',
            'byte_size' => 10,
        ]);
    }

    // ---- Browsing ---------------------------------------------------------------

    public function test_the_index_lists_disk_files_and_hides_temp_uploads(): void
    {
        $placed = $this->diskFile(['name' => 'plakat.pdf']);
        $attachment = File::factory()->attachedTo(Task::factory()->create([
            'creator_id' => $this->user->id,
            'assigned_id' => $this->user->id,
        ]))->create();
        // Uploaded but never attached or placed — an implementation detail of the 2-step
        // upload, not a file anyone owns yet.
        File::factory()->create(['name' => 'limbo.pdf']);

        $response = $this->getJson('/api/disk');

        $response->assertOk()->assertJsonCount(2, 'data');
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($placed->id, $ids);
        $this->assertContains($attachment->id, $ids, 'Files owned by other resources are visible on the disk.');
    }

    public function test_a_files_origin_is_reported(): void
    {
        $task = Task::factory()->create(['creator_id' => $this->user->id, 'assigned_id' => $this->user->id]);
        File::factory()->attachedTo($task)->create();
        $this->diskFile();

        $sources = collect($this->getJson('/api/disk')->json('data'))->pluck('source')->sort()->values()->all();

        // The browser shows where a file came from, and filters on it.
        $this->assertSame(['disk', 'task'], $sources);
    }

    public function test_the_index_filters_by_source_folder_type_and_labels(): void
    {
        $folder = Folder::factory()->create();
        $inFolder = File::factory()->image()->inFolder($folder)->create();
        // A disk file at the ROOT — not in $folder, so the folder filter must exclude it.
        File::factory()->atRoot()->create();

        $label = Label::create(['name' => 'wrzesień']);
        $inFolder->labels()->attach($label->id);

        $this->assertSame(
            [$inFolder->id],
            collect($this->getJson('/api/disk?folder_id=' . $folder->id)->json('data'))->pluck('id')->all(),
        );

        $this->assertSame(
            [$inFolder->id],
            collect($this->getJson('/api/disk?type=' . FileType::IMAGE->value)->json('data'))->pluck('id')->all(),
        );

        $this->assertSame(
            [$inFolder->id],
            collect($this->getJson('/api/disk?labels[]=' . $label->id)->json('data'))->pluck('id')->all(),
        );

        $this->assertSame(
            [$inFolder->id],
            collect($this->getJson('/api/disk?search=' . $inFolder->name)->json('data'))->pluck('id')->all(),
        );
    }

    public function test_the_index_flags_files_that_have_the_current_users_draft(): void
    {
        // (a) my own in-progress draft → flagged. A second, foreign draft on the SAME file must
        // not change that: the flag is strictly per user.
        $withMyDraft = $this->diskFile(['name' => 'z-moim-szkicem.png']);
        $other = User::factory()->create();
        $this->workspace->users()->attach($other->id);
        $this->draftFor($withMyDraft, $this->user);
        $this->draftFor($withMyDraft, $other);

        // (b) only ANOTHER user's draft → not flagged for me (per-user isolation).
        $withOthersDraft = $this->diskFile(['name' => 'cudzy-szkic.png']);
        $this->draftFor($withOthersDraft, $other);

        // (c) no draft at all → false.
        $clean = $this->diskFile(['name' => 'bez-szkicu.png']);

        $byId = collect($this->getJson('/api/disk')->assertOk()->json('data'))->keyBy('id');

        $this->assertTrue($byId[$withMyDraft->id]['has_draft'], 'A file with my draft is flagged.');
        $this->assertFalse($byId[$withOthersDraft->id]['has_draft'], "Another user's draft never flags the file for me.");
        $this->assertFalse($byId[$clean->id]['has_draft'], 'A file with no draft is not flagged.');
    }

    // ---- Uploading --------------------------------------------------------------

    public function test_a_file_can_be_uploaded_into_a_folder(): void
    {
        $folder = Folder::factory()->create();

        $response = $this->postJson('/api/disk', [
            'file' => UploadedFile::fake()->image('grafika.png'),
            'folder_id' => $folder->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.folder_id', $folder->id);
        $response->assertJsonPath('data.source', 'disk');

        $file = File::query()->findOrFail($response->json('data.id'));
        Storage::assertExists($file->path);
        // Blobs are namespaced per workspace — a flat bucket makes quotas and per-tenant
        // cleanup impossible later.
        $this->assertStringStartsWith('uploads/' . $this->workspace->id . '/', $file->path);
    }

    public function test_a_file_uploaded_to_the_roo_t_is_placed_and_visible_there(): void
    {
        // A root upload has fileable_type 'folder' with fileable_id NULL — a temp has NO fileable,
        // so the root disk view shows the placed file while a real temp stays hidden.
        $response = $this->postJson('/api/disk', [
            'file' => UploadedFile::fake()->image('root.png'),
        ]);
        $response->assertCreated()->assertJsonPath('data.folder_id', null)->assertJsonPath('data.source', 'disk');
        $id = $response->json('data.id');

        // A genuine temp upload (no fileable yet).
        $temp = File::factory()->create();

        $ids = collect($this->getJson('/api/disk?folder_id=&source=disk')->json('data'))->pluck('id')->all();
        $this->assertContains($id, $ids);
        $this->assertNotContains($temp->id, $ids);
    }

    // ---- Metadata ---------------------------------------------------------------

    public function test_metadata_can_be_edited_and_absent_fields_are_left_alone(): void
    {
        $file = $this->diskFile(['name' => 'stara.pdf', 'description' => 'opis']);
        $label = Label::create(['name' => 'raporty']);

        $this->patchJson('/api/disk/' . $file->id, ['name' => 'nowa.pdf'])
            ->assertOk()
            ->assertJsonPath('data.name', 'nowa.pdf')
            // Not sent → untouched, rather than nulled.
            ->assertJsonPath('data.description', 'opis');

        $this->patchJson('/api/disk/' . $file->id, ['labels' => [$label->id]])
            ->assertOk()
            ->assertJsonPath('data.labels.0.id', $label->id);

        // Explicit null clears.
        $this->patchJson('/api/disk/' . $file->id, ['description' => null])
            ->assertOk()
            ->assertJsonPath('data.description', null);
    }

    public function test_enforced_labels_are_locked_and_survive_a_metadata_edit(): void
    {
        $folder = Folder::factory()->create();
        $file = File::factory()->inFolder($folder)->create();
        $enforced = Label::create(['name' => 'Marka']);
        $manual = Label::create(['name' => 'Wrzesień']);

        // Governance enforces a label on the folder → materialized onto the file.
        $this->patchJson('/api/disk/folders/' . $folder->id, [
            'labels' => [['id' => $enforced->id, 'mode' => 'enforced']],
        ])->assertOk();

        // A metadata edit that sets only a MANUAL label must not strip the enforced one.
        $res = $this->patchJson('/api/disk/' . $file->id, ['labels' => [$manual->id]])->assertOk();

        $labels = collect($res->json('data.labels'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$enforced->id, $manual->id], $labels->keys()->all());
        $this->assertTrue($labels[$enforced->id]['locked'], 'The folder-enforced label is locked.');
        $this->assertFalse($labels[$manual->id]['locked'], 'A manual label is not locked.');
    }

    public function test_a_file_can_be_moved_between_folders_and_to_the_root(): void
    {
        $file = $this->diskFile();
        $target = Folder::factory()->create();

        $this->patchJson('/api/disk/' . $file->id, ['folder_id' => $target->id])
            ->assertOk()
            ->assertJsonPath('data.folder_id', $target->id);

        $this->patchJson('/api/disk/' . $file->id, ['folder_id' => null])
            ->assertOk()
            ->assertJsonPath('data.folder_id', null);
    }

    public function test_an_attachment_can_be_described_but_not_moved_or_trashed_from_the_disk(): void
    {
        $task = Task::factory()->create(['creator_id' => $this->user->id, 'assigned_id' => $this->user->id]);
        $attachment = File::factory()->attachedTo($task)->create(['uploader_id' => $this->user->id]);
        $folder = Folder::factory()->create();

        // Tagging/describing is the point of showing them on the disk (decision Q1)...
        $this->patchJson('/api/disk/' . $attachment->id, ['description' => 'z zadania'])->assertOk();

        // ...but their lifecycle stays with the owning module.
        $this->patchJson('/api/disk/' . $attachment->id, ['folder_id' => $folder->id])->assertStatus(422);
        $this->deleteJson('/api/disk/' . $attachment->id)->assertForbidden();
    }

    // ---- Trash ------------------------------------------------------------------

    public function test_trashing_from_the_disk_is_recoverable_and_keeps_the_bytes(): void
    {
        $file = $this->diskFile();

        $this->deleteJson('/api/disk/' . $file->id)->assertNoContent();

        $this->assertSoftDeleted('files', ['id' => $file->id]);
        $this->assertNotNull(File::withTrashed()->find($file->id)->disk_trashed_at);
        // Trash must stay reversible: the bytes survive until an explicit force-delete.
        // (assertExists' 2nd argument is the expected CONTENT, not a message.)
        Storage::assertExists($file->path, 'bytes');

        $this->getJson('/api/disk')->assertJsonCount(0, 'data');
        $this->getJson('/api/disk?trashed=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $file->id);
    }

    public function test_a_detached_attachment_never_shows_up_in_the_disk_trash(): void
    {
        $task = Task::factory()->create(['creator_id' => $this->user->id, 'assigned_id' => $this->user->id]);
        $attachment = File::factory()->attachedTo($task)->create();

        // This is what removing an attachment from a task does — a soft-delete, no disk marker.
        app(FileService::class)->detach($task, $attachment);

        // Otherwise the disk trash would fill up with every attachment anyone ever removed.
        $this->getJson('/api/disk?trashed=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_force_delete_purges_the_bytes(): void
    {
        $file = $this->diskFile();
        $path = $file->path;
        $this->deleteJson('/api/disk/' . $file->id)->assertNoContent();

        $this->deleteJson('/api/disk/' . $file->id . '/force')->assertNoContent();

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        // The app's only path that removes a blob — pinned, because the disk is configured
        // with 'throw' => false, so a wrong path would delete nothing and still look fine.
        Storage::assertMissing($path);
    }

    // ---- Restore (the P5 matrix) --------------------------------------------------

    public function test_a_file_restores_into_its_original_folder(): void
    {
        $folder = Folder::factory()->create();
        $file = File::factory()->inFolder($folder)->create();
        $this->deleteJson('/api/disk/' . $file->id);

        $preview = $this->getJson('/api/disk/' . $file->id . '/restore-preview');
        $preview->assertOk()
            ->assertJsonPath('data.can_restore_in_place', true)
            ->assertJsonPath('data.original_folder.id', $folder->id);

        $this->postJson('/api/disk/' . $file->id . '/restore')
            ->assertOk()
            ->assertJsonPath('data.folder_id', $folder->id);

        $this->assertNotSoftDeleted('files', ['id' => $file->id]);
        $this->assertNull(File::query()->find($file->id)->disk_trashed_at);
    }

    public function test_restoring_a_file_whose_folder_is_gone_requires_a_target(): void
    {
        $folder = Folder::factory()->create();
        $file = File::factory()->inFolder($folder)->create();
        $this->deleteJson('/api/disk/' . $file->id);
        $folder->forceDelete();

        $this->getJson('/api/disk/' . $file->id . '/restore-preview')
            ->assertOk()
            ->assertJsonPath('data.can_restore_in_place', false)
            ->assertJsonPath('data.reason', 'folder_missing');

        // Restoring into thin air would hide the file; make the user choose.
        $this->postJson('/api/disk/' . $file->id . '/restore')
            ->assertStatus(422)
            ->assertJsonValidationErrors('target_folder_id');

        $target = Folder::factory()->create();
        $this->postJson('/api/disk/' . $file->id . '/restore', ['target_folder_id' => $target->id])
            ->assertOk()
            ->assertJsonPath('data.folder_id', $target->id);
    }

    public function test_restoring_a_detached_attachment_severs_it_and_requires_a_target(): void
    {
        $task = Task::factory()->create(['creator_id' => $this->user->id, 'assigned_id' => $this->user->id]);
        $attachment = File::factory()->attachedTo($task)->create(['uploader_id' => $this->user->id]);
        $attachment->forceFill(['disk_trashed_at' => now()])->save();
        $attachment->delete();

        $this->getJson('/api/disk/' . $attachment->id . '/restore-preview')
            ->assertOk()
            ->assertJsonPath('data.can_restore_in_place', false)
            ->assertJsonPath('data.reason', 'detached');

        $target = Folder::factory()->create();
        $this->postJson('/api/disk/' . $attachment->id . '/restore', ['target_folder_id' => $target->id])
            ->assertOk()
            ->assertJsonPath('data.folder_id', $target->id)
            // A restored file must never reappear inside a task that believes it removed it.
            ->assertJsonPath('data.source', 'disk');

        // It comes back as a disk file (its container is now the target folder), no longer the task's.
        $restored = File::query()->find($attachment->id);
        $this->assertSame(File::FOLDER_TYPE, $restored->fileable_type);
        $this->assertSame($target->id, $restored->fileable_id);
        $this->assertSame(0, $task->files()->count());
    }

    // ---- Pick from Disk (copy-to-temp) ----------------------------------------------

    public function test_picking_a_disk_file_copies_it_into_the_callers_temp(): void
    {
        // "Pick from Disk" in a form/task file field must NOT reference the existing file (that
        // would share one blob between the disk and the submission, and the claim rules would
        // refuse a file in a folder anyway) — it duplicates into a fresh temp the caller owns.
        $source = $this->diskFile(['name' => 'brief.pdf', 'description' => 'oryginał']);

        $response = $this->postJson('/api/disk/' . $source->id . '/copy-to-temp');

        $response->assertCreated()
            ->assertJsonPath('data.name', 'brief.pdf')
            ->assertJsonPath('data.source', 'disk');

        $copy = File::query()->findOrFail($response->json('data.id'));
        $this->assertNotSame($source->id, $copy->id);

        // The copy is a genuine TEMP owned by the actor: no fileable yet — so it binds to a
        // submission exactly like an upload, and the actor passes the claim rules.
        $this->assertNull($copy->fileable_type);
        $this->assertNull($copy->folder_id);
        $this->assertSame($this->user->id, $copy->uploader_id);

        // A distinct blob under the workspace prefix; the source is left untouched.
        $this->assertNotSame($source->path, $copy->path);
        $this->assertStringStartsWith('uploads/' . $this->workspace->id . '/', $copy->path);
        Storage::assertExists($copy->path);
        Storage::assertExists($source->path);
    }

    public function test_picking_a_resource_attachment_copies_it_as_a_standalone_temp(): void
    {
        // A resource-owned file (a task attachment, a past report) is a legitimate pick; the copy
        // must come back severed from that resource so it can be freely re-attached elsewhere.
        $task = Task::factory()->create(['creator_id' => $this->user->id, 'assigned_id' => $this->user->id]);
        $attachment = File::factory()->attachedTo($task)->create(['name' => 'raport.md']);
        Storage::put($attachment->path, 'bytes');

        $response = $this->postJson('/api/disk/' . $attachment->id . '/copy-to-temp')->assertCreated();

        $copy = File::query()->findOrFail($response->json('data.id'));
        $this->assertNull($copy->fileable_type, 'The copy is standalone, not still owned by the task.');
        $this->assertSame($this->user->id, $copy->uploader_id);
        // The original attachment is unchanged.
        $this->assertSame($task->getMorphClass(), File::query()->find($attachment->id)->fileable_type);
    }

    public function test_picking_a_trashed_file_404s(): void
    {
        // The {file} binding resolves live rows only, so a disk-trashed (soft-deleted) source is
        // never copyable — no reviving bytes through the picker.
        $file = $this->diskFile();
        $this->deleteJson('/api/disk/' . $file->id)->assertNoContent();

        $this->postJson('/api/disk/' . $file->id . '/copy-to-temp')->assertNotFound();
    }

    // ---- Copy (duplicate into the disk) ---------------------------------------------

    public function test_copying_a_file_duplicates_it_into_the_disk_with_a_new_name_and_folder(): void
    {
        $source = $this->diskFile(['name' => 'brief.pdf', 'description' => 'oryginał']);
        $target = Folder::factory()->create();

        $response = $this->postJson('/api/disk/' . $source->id . '/copy', [
            'name' => 'Kopia brief.pdf',
            'folder_id' => $target->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Kopia brief.pdf')
            ->assertJsonPath('data.folder_id', $target->id)
            ->assertJsonPath('data.source', 'disk');

        $copy = File::query()->findOrFail($response->json('data.id'));
        $this->assertNotSame($source->id, $copy->id);
        // A real DISK file: its container is the target folder (fileable), owned by the actor.
        $this->assertSame(File::FOLDER_TYPE, $copy->fileable_type);
        $this->assertSame($target->id, $copy->fileable_id);
        $this->assertSame($this->user->id, $copy->uploader_id);
        // A distinct blob; the source is untouched.
        $this->assertNotSame($source->path, $copy->path);
        Storage::assertExists($copy->path);
        Storage::assertExists($source->path);
    }

    public function test_copying_to_the_root_omits_the_folder_and_is_visible_there(): void
    {
        $source = $this->diskFile(['name' => 'poster.png']);

        $id = $this->postJson('/api/disk/' . $source->id . '/copy', ['name' => 'Kopia poster.png'])
            ->assertCreated()
            ->assertJsonPath('data.folder_id', null)
            ->json('data.id');

        // Placed at the root → shows in the root disk view (not mistaken for a temp).
        $ids = collect($this->getJson('/api/disk?folder_id=&source=disk')->json('data'))->pluck('id')->all();
        $this->assertContains($id, $ids);
    }

    public function test_copying_requires_a_name(): void
    {
        $source = $this->diskFile();

        $this->postJson('/api/disk/' . $source->id . '/copy', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    // ---- Isolation ------------------------------------------------------------------

    public function test_a_foreign_file_404s(): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $otherOwner->id]);

        $context = app(TenantContext::class);
        $context->set($otherWorkspace);
        $foreign = File::factory()->create();
        $context->set($this->workspace);

        $this->patchJson('/api/disk/' . $foreign->id, ['name' => 'x'])->assertNotFound();
        $this->deleteJson('/api/disk/' . $foreign->id)->assertNotFound();
        $this->postJson('/api/disk/' . $foreign->id . '/restore')->assertNotFound();
        $this->deleteJson('/api/disk/' . $foreign->id . '/force')->assertNotFound();
        $this->postJson('/api/disk/' . $foreign->id . '/copy-to-temp')->assertNotFound();
        $this->postJson('/api/disk/' . $foreign->id . '/copy', ['name' => 'x'])->assertNotFound();
    }

    // ---- Temp GC ----------------------------------------------------------------------

    public function test_the_sweep_prunes_abandoned_uploads_but_spares_fresh_and_used_ones(): void
    {
        $abandoned = File::factory()->create(['created_at' => now()->subHours(FileService::TEMP_RETENTION_HOURS + 1)]);
        Storage::put($abandoned->path, 'x');

        $fresh = File::factory()->create(['created_at' => now()->subMinutes(5)]);
        $placed = $this->diskFile(['created_at' => now()->subWeek()]);

        $this->artisan('disk:prune-temp-files')->assertSuccessful();

        // Rows AND bytes: an abandoned dropzone used to leak both forever.
        $this->assertDatabaseMissing('files', ['id' => $abandoned->id]);
        Storage::assertMissing($abandoned->path);
        $this->assertDatabaseHas('files', ['id' => $fresh->id]);
        $this->assertDatabaseHas('files', ['id' => $placed->id]);
    }
}
