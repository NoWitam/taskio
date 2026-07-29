<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Enums\FileType;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\GeneratedImageStore;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The produced-image SERVE + SAVE-TO-DISK endpoints (R2 sub-stage 2c). Serve is read-gated + tenant-scoped +
 * inline-safe; save promotes the produced bytes into the user's Disk through the Disk create path (workspace-
 * scoped, creator = user), double-authorized (session update + Disk create).
 */
class GeneratedImageServeAndSaveTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);

        Storage::fake();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function store(): GeneratedImageStore
    {
        return app(GeneratedImageStore::class);
    }

    /** A ready session with an ok image part whose produced bytes are already in the store (version 1). */
    private function sessionWithImage(string $bytes = 'PNGBYTES', string $partKey = 'image'): GenerationSession
    {
        $session = GenerationSession::factory()->status(GenerationSessionStatus::Ready)->create(['creator_id' => $this->user->id]);
        $version = $this->store()->storeVersion($session->id, $partKey, $bytes);
        $session->update(['results' => [$partKey => [
            'kind' => 'image_plan',
            'status' => 'ok',
            'image' => ['mime' => 'image/png', 'width' => 1, 'height' => 1, 'version' => $version],
            'version' => $version,
        ]]]);

        return $session;
    }

    // ---- serve -----------------------------------------------------------------

    public function test_it_streams_a_produced_image_inline(): void
    {
        $session = $this->sessionWithImage('THE-PNG-BYTES');

        $response = $this->get("/api/generator/sessions/{$session->id}/parts/image/image");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('THE-PNG-BYTES', $response->streamedContent());
    }

    public function test_serving_an_absent_part_is_404(): void
    {
        $session = GenerationSession::factory()->create(['creator_id' => $this->user->id]);

        $this->get("/api/generator/sessions/{$session->id}/parts/image/image")->assertNotFound();
    }

    public function test_serving_cannot_reach_another_workspaces_session(): void
    {
        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $other->users()->attach($this->user->id);

        app(TenantContext::class)->set($other);
        $foreign = GenerationSession::factory()->create(['creator_id' => $this->user->id]);
        $this->store()->storeVersion($foreign->id, 'image', 'SECRET');
        app(TenantContext::class)->set($this->workspace);

        // The tenant-scoped {session} binding never resolves a foreign session.
        $this->get("/api/generator/sessions/{$foreign->id}/parts/image/image")->assertNotFound();
    }

    // ---- save to disk ----------------------------------------------------------

    public function test_it_saves_a_produced_image_to_the_disk_root(): void
    {
        $session = $this->sessionWithImage('SAVED-BYTES');

        $response = $this->postJson("/api/generator/sessions/{$session->id}/parts/image/save-to-disk", [
            'name' => 'My render.png',
        ])->assertCreated();

        $fileId = $response->json('data.id');
        $file = File::findOrFail($fileId);

        $this->assertSame('My render.png', $file->name);
        $this->assertSame(FileType::IMAGE, $file->type);
        $this->assertSame('image/png', $file->mime_type);
        // A disk-native file at the workspace root: fileable_type 'folder', fileable_id null.
        $this->assertSame(File::FOLDER_TYPE, $file->fileable_type);
        $this->assertNull($file->fileable_id);
        // Workspace-scoped + creator = the acting user.
        $this->assertSame($this->workspace->id, $file->workspace_id);
        $this->assertSame($this->user->id, $file->uploader_id);
        // The produced bytes were written under the file's blob path.
        Storage::assertExists($file->path);
        $this->assertSame('SAVED-BYTES', Storage::get($file->path));
    }

    public function test_it_saves_into_a_chosen_folder(): void
    {
        $folder = Folder::factory()->create();
        $session = $this->sessionWithImage();

        $response = $this->postJson("/api/generator/sessions/{$session->id}/parts/image/save-to-disk", [
            'folder_id' => $folder->id,
        ])->assertCreated();

        $file = File::findOrFail($response->json('data.id'));
        $this->assertSame(File::FOLDER_TYPE, $file->fileable_type);
        $this->assertSame($folder->id, $file->fileable_id);
    }

    public function test_it_defaults_the_name_when_none_is_given(): void
    {
        $session = $this->sessionWithImage();

        $response = $this->postJson("/api/generator/sessions/{$session->id}/parts/image/save-to-disk", [])
            ->assertCreated();

        $this->assertSame(__('generator.sessions.saved_image_name') . '.png', $response->json('data.name'));
    }

    public function test_saving_an_absent_part_is_404(): void
    {
        $session = GenerationSession::factory()->create(['creator_id' => $this->user->id]);

        $this->postJson("/api/generator/sessions/{$session->id}/parts/image/save-to-disk", [])->assertNotFound();
    }

    public function test_a_non_owner_cannot_save_the_sessions_image(): void
    {
        // A session owned by ANOTHER member of the same workspace: readable, but the save requires session
        // `update` (creator-only), so the double-authorized request is refused 403.
        $other = User::factory()->create();
        $this->workspace->users()->attach($other->id);

        $session = GenerationSession::factory()->create(['creator_id' => $other->id]);
        $this->store()->storeVersion($session->id, 'image', 'BYTES');

        $this->postJson("/api/generator/sessions/{$session->id}/parts/image/save-to-disk", [])->assertForbidden();
    }

    public function test_saving_cannot_reach_another_workspaces_session(): void
    {
        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $other->users()->attach($this->user->id);

        app(TenantContext::class)->set($other);
        $foreign = GenerationSession::factory()->create(['creator_id' => $this->user->id]);
        $this->store()->storeVersion($foreign->id, 'image', 'SECRET');
        app(TenantContext::class)->set($this->workspace);

        $this->postJson("/api/generator/sessions/{$foreign->id}/parts/image/save-to-disk", [])->assertNotFound();
    }

    public function test_saving_into_another_workspaces_folder_is_404(): void
    {
        // A folder that lives in ANOTHER workspace.
        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $other->users()->attach($this->user->id);

        app(TenantContext::class)->set($other);
        $foreignFolder = Folder::factory()->create();
        app(TenantContext::class)->set($this->workspace);

        $session = $this->sessionWithImage('BYTES');

        // The produced image exists, but the tenant-scoped findOrFail in FileService::storeDiskContent refuses
        // a foreign folder — a produced image can never be planted into another workspace's tree.
        $this->postJson("/api/generator/sessions/{$session->id}/parts/image/save-to-disk", [
            'folder_id' => $foreignFolder->id,
        ])->assertNotFound();
    }
}
