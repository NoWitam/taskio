<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Models\DiskFileDraft;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Per-user autosave DRAFTS of a Disk file edit: POST /disk/{file}/draft persists an in-progress edit
 * (an opaque manifest + image base blobs) server-side so a refresh/crash never loses work; GET fetches
 * it back, GET .../base/{id} streams a base PNG, DELETE discards it. The MAIN file is never touched.
 *
 * These tests pin the CONTRACT — storage layout, per-user isolation, base GC, size cap, tenancy and
 * the retention reaper — mirroring DiskThumbnailTest's member/foreign/non-member harness.
 */
class DiskDraftTest extends TestCase
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

        // Accept JSON on every request (the real XHR editor does), so a validation failure renders
        // as a 422 payload rather than a 302 redirect.
        $this->actingAs($this->user)
            ->withHeader('X-Workspace-Id', $this->workspace->id)
            ->withHeader('Accept', 'application/json');
        app(TenantContext::class)->set($this->workspace);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- Helpers ---------------------------------------------------------------------

    /** A disk-native file (the draft only needs the row — it never reads the file's own bytes). */
    private function diskFile(): File
    {
        return File::factory()->inFolder(Folder::factory()->create())->create();
    }

    private function draftUrl(File $file): string
    {
        return "/api/disk/{$file->id}/draft";
    }

    private function draftDir(File $file, ?User $user = null): string
    {
        $user ??= $this->user;

        return 'disk-drafts/' . $this->workspace->id . '/' . $user->id . '/' . $file->id;
    }

    /** A minimal valid image manifest referencing $baseIds. */
    private function imageManifest(array $baseIds): array
    {
        return [
            'kind' => 'image',
            'history' => array_map(fn ($id) => ['op' => 'base', 'baseId' => $id], $baseIds),
            'historyIndex' => max(0, count($baseIds) - 1),
            'savedIndex' => 0,
            'baseIds' => $baseIds,
        ];
    }

    // ---- POST (autosave) -------------------------------------------------------------

    public function test_put_stores_an_image_draft_with_its_base_blobs(): void
    {
        $file = $this->diskFile();

        $this->post($this->draftUrl($file), [
            'manifest' => json_encode($this->imageManifest([0, 1])),
            'base_version' => '2026-07-21T10:00:00Z',
            'base_0' => UploadedFile::fake()->image('base_0.png'),
            'base_1' => UploadedFile::fake()->image('base_1.png'),
        ])
            ->assertOk()
            ->assertJsonPath('data.kind', 'image')
            ->assertJsonPath('data.base_ids', [0, 1])
            ->assertJsonPath('data.base_version', '2026-07-21T10:00:00Z');

        Storage::assertExists($this->draftDir($file) . '/manifest.json');
        Storage::assertExists($this->draftDir($file) . '/base-0.png');
        Storage::assertExists($this->draftDir($file) . '/base-1.png');

        $draft = DiskFileDraft::sole();
        $this->assertSame($file->id, $draft->file_id);
        $this->assertSame($this->user->id, $draft->user_id);
        $this->assertSame('image', $draft->kind);
        $this->assertSame($this->workspace->id, $draft->workspace_id);
        $this->assertGreaterThan(0, $draft->byte_size);
    }

    public function test_put_stores_a_text_draft_without_bases(): void
    {
        $file = $this->diskFile();

        $this->post($this->draftUrl($file), [
            'manifest' => json_encode(['kind' => 'text', 'content' => 'Hello draft world']),
        ])
            ->assertOk()
            ->assertJsonPath('data.kind', 'text')
            ->assertJsonPath('data.base_ids', [])
            ->assertJsonPath('data.manifest.content', 'Hello draft world');

        Storage::assertExists($this->draftDir($file) . '/manifest.json');
        $this->assertSame('text', DiskFileDraft::sole()->kind);
    }

    public function test_a_second_put_gcs_dropped_bases_and_stores_new_ones(): void
    {
        $file = $this->diskFile();

        // First autosave: bases 0 and 1 live.
        $this->post($this->draftUrl($file), [
            'manifest' => json_encode($this->imageManifest([0, 1])),
            'base_0' => UploadedFile::fake()->image('base_0.png'),
            'base_1' => UploadedFile::fake()->image('base_1.png'),
        ])->assertOk();
        Storage::assertExists($this->draftDir($file) . '/base-1.png');

        // History advanced: base 1 dropped, base 2 added. The FE sends ONLY the new base_2.
        $this->post($this->draftUrl($file), [
            'manifest' => json_encode($this->imageManifest([0, 2])),
            'base_2' => UploadedFile::fake()->image('base_2.png'),
        ])
            ->assertOk()
            ->assertJsonPath('data.base_ids', [0, 2]);

        Storage::assertExists($this->draftDir($file) . '/base-0.png');   // still referenced
        Storage::assertExists($this->draftDir($file) . '/base-2.png');   // newly stored
        Storage::assertMissing($this->draftDir($file) . '/base-1.png');  // GC'd (no longer referenced)

        // Upsert, not insert.
        $this->assertSame(1, DiskFileDraft::count());
    }

    public function test_an_oversize_autosave_is_rejected(): void
    {
        config()->set('disk.drafts.max_bytes', 10);
        $file = $this->diskFile();

        $this->post($this->draftUrl($file), [
            'manifest' => json_encode(['kind' => 'text', 'content' => 'this is definitely longer than ten bytes']),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('manifest');

        $this->assertSame(0, DiskFileDraft::count());
    }

    public function test_put_validates_the_manifest_kind(): void
    {
        $file = $this->diskFile();

        $this->post($this->draftUrl($file), [
            'manifest' => json_encode(['kind' => 'video']),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('manifest_data.kind');
    }

    // ---- GET (fetch) -----------------------------------------------------------------

    public function test_get_returns_the_draft_manifest_and_base_ids(): void
    {
        $file = $this->diskFile();

        $this->post($this->draftUrl($file), [
            'manifest' => json_encode($this->imageManifest([3])),
            'base_version' => 'ver-1',
            'base_3' => UploadedFile::fake()->image('base_3.png'),
        ])->assertOk();

        $this->getJson($this->draftUrl($file))
            ->assertOk()
            ->assertJsonPath('data.kind', 'image')
            ->assertJsonPath('data.base_ids', [3])
            ->assertJsonPath('data.base_version', 'ver-1')
            ->assertJsonPath('data.manifest.kind', 'image');
    }

    public function test_get_is_not_found_when_no_draft_exists(): void
    {
        $this->getJson($this->draftUrl($this->diskFile()))->assertNotFound();
    }

    // ---- GET base --------------------------------------------------------------------

    public function test_get_base_streams_the_png(): void
    {
        $file = $this->diskFile();

        $this->post($this->draftUrl($file), [
            'manifest' => json_encode($this->imageManifest([7])),
            'base_7' => UploadedFile::fake()->image('base_7.png'),
        ])->assertOk();

        $response = $this->get($this->draftUrl($file) . '/base/7');

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(
            Storage::get($this->draftDir($file) . '/base-7.png'),
            $response->getContent(),
        );
    }

    public function test_get_base_is_not_found_for_a_missing_base(): void
    {
        $file = $this->diskFile();

        $this->post($this->draftUrl($file), [
            'manifest' => json_encode($this->imageManifest([7])),
            'base_7' => UploadedFile::fake()->image('base_7.png'),
        ])->assertOk();

        // A base id the draft does not have.
        $this->get($this->draftUrl($file) . '/base/999')->assertNotFound();
    }

    // ---- DELETE ----------------------------------------------------------------------

    public function test_delete_removes_the_row_and_directory_and_is_idempotent(): void
    {
        $file = $this->diskFile();

        $this->post($this->draftUrl($file), [
            'manifest' => json_encode($this->imageManifest([0])),
            'base_0' => UploadedFile::fake()->image('base_0.png'),
        ])->assertOk();
        Storage::assertExists($this->draftDir($file) . '/manifest.json');

        $this->delete($this->draftUrl($file))->assertNoContent();
        $this->assertSame(0, DiskFileDraft::count());
        Storage::assertMissing($this->draftDir($file) . '/manifest.json');
        Storage::assertMissing($this->draftDir($file) . '/base-0.png');

        // Idempotent: a second discard (or a save that already cleared it) still succeeds.
        $this->delete($this->draftUrl($file))->assertNoContent();
    }

    // ---- Per-user isolation + tenancy ------------------------------------------------

    public function test_a_draft_is_isolated_per_user(): void
    {
        $file = $this->diskFile();

        // User A autosaves a draft.
        $this->post($this->draftUrl($file), [
            'manifest' => json_encode($this->imageManifest([1])),
            'base_1' => UploadedFile::fake()->image('base_1.png'),
        ])->assertOk();

        // User B is a member of the same workspace but has no draft for this file.
        $userB = User::factory()->create();
        $this->workspace->users()->attach($userB->id);
        $this->actingAs($userB)->withHeader('X-Workspace-Id', $this->workspace->id);

        $this->getJson($this->draftUrl($file))->assertNotFound();          // no draft of their own
        $this->get($this->draftUrl($file) . '/base/1')->assertNotFound();  // never A's base blob
    }

    public function test_a_file_in_another_workspace_is_not_found(): void
    {
        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $other->users()->attach($this->user->id);

        app(TenantContext::class)->set($other);
        $foreign = File::factory()->inFolder(Folder::factory()->create())->create();
        app(TenantContext::class)->set($this->workspace);

        // Route-model binding is tenant-scoped, so a foreign id never resolves — 404 at bind.
        $this->getJson($this->draftUrl($foreign))->assertNotFound();
        $this->post($this->draftUrl($foreign), [
            'manifest' => json_encode($this->imageManifest([0])),
        ])->assertNotFound();
    }

    public function test_a_non_member_is_forbidden(): void
    {
        $outsider = User::factory()->create(); // not attached to the workspace
        $file = $this->diskFile();

        $this->actingAs($outsider)
            ->withHeader('X-Workspace-Id', $this->workspace->id)
            ->getJson($this->draftUrl($file))
            ->assertStatus(403); // ResolveWorkspace refuses a non-member before anything binds
    }

    // ---- Reaper ----------------------------------------------------------------------

    public function test_the_reaper_prunes_a_stale_draft_and_leaves_a_fresh_one(): void
    {
        config()->set('disk.drafts.retention', 86400);

        $staleFile = $this->diskFile();
        $freshFile = $this->diskFile();

        $this->post($this->draftUrl($staleFile), [
            'manifest' => json_encode($this->imageManifest([0])),
            'base_0' => UploadedFile::fake()->image('base_0.png'),
        ])->assertOk();
        $this->post($this->draftUrl($freshFile), [
            'manifest' => json_encode(['kind' => 'text', 'content' => 'fresh']),
        ])->assertOk();

        // Age the stale draft past retention (a raw update leaves updated_at exactly as given).
        DiskFileDraft::where('file_id', $staleFile->id)->update(['updated_at' => now()->subDay()->subHour()]);

        $this->artisan('disk:reap-stale-drafts')
            ->expectsOutputToContain('Pruned 1 stale draft(s)')
            ->assertSuccessful();

        // Stale: row + directory gone.
        $this->assertNull(DiskFileDraft::where('file_id', $staleFile->id)->first());
        Storage::assertMissing($this->draftDir($staleFile) . '/manifest.json');
        Storage::assertMissing($this->draftDir($staleFile) . '/base-0.png');

        // Fresh: untouched.
        $this->assertNotNull(DiskFileDraft::where('file_id', $freshFile->id)->first());
        Storage::assertExists($this->draftDir($freshFile) . '/manifest.json');
    }
}
