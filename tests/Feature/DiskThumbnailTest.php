<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Modules\Disk\Services\FileService;
use App\Modules\Disk\Support\PdfRasterizer;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakePdfRasterizer;
use Tests\TestCase;

/**
 * FEATURE A — server-side PDF thumbnails: GET /disk/{file}/thumbnail returns a small first-page PNG
 * raster of a PDF, so the grid can show a real thumbnail instead of a glyph. The rasterizer is faked
 * (bound over the PdfRasterizer interface), so these tests never depend on poppler being installed:
 * they pin the CONTRACT — supported type, caching, invalidation and tenancy — not the raster itself.
 */
class DiskThumbnailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private FakePdfRasterizer $rasterizer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);

        // Swap the real poppler rasterizer for a counting fake, so the feature is env-independent.
        $this->rasterizer = new FakePdfRasterizer('FAKE-PNG-BYTES');
        $this->app->instance(PdfRasterizer::class, $this->rasterizer);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /** A disk-native PDF file WITH bytes on the fake disk (the factory only makes the row). */
    private function pdfFile(array $attributes = []): File
    {
        $file = File::factory()->inFolder(Folder::factory()->create())->create($attributes);
        Storage::put($file->path, '%PDF-1.4 pretend');

        return $file;
    }

    private function thumbUrl(File $file): string
    {
        return "/api/disk/{$file->id}/thumbnail";
    }

    private function cachePath(File $file): string
    {
        return 'disk-thumbs/' . $this->workspace->id . '/' . $file->id . '.png';
    }

    public function test_a_pdf_returns_a_png_thumbnail(): void
    {
        $file = $this->pdfFile();

        $response = $this->get($this->thumbUrl($file));

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('FAKE-PNG-BYTES', $response->getContent());
        $this->assertSame(1, $this->rasterizer->calls);
        Storage::assertExists($this->cachePath($file));
    }

    public function test_a_second_request_is_served_from_cache(): void
    {
        $file = $this->pdfFile();

        $this->get($this->thumbUrl($file))->assertOk();
        $this->get($this->thumbUrl($file))->assertOk();

        // The rasterizer ran exactly once; the second request read the cached PNG.
        $this->assertSame(1, $this->rasterizer->calls);
    }

    public function test_a_non_pdf_file_is_not_found(): void
    {
        $file = File::factory()->image()->inFolder(Folder::factory()->create())->create();
        Storage::put($file->path, 'PNGDATA');

        $this->get($this->thumbUrl($file))->assertNotFound();
        $this->assertSame(0, $this->rasterizer->calls);
    }

    public function test_disabled_thumbnails_are_not_found(): void
    {
        config()->set('disk.thumbnails.enabled', false);
        $file = $this->pdfFile();

        $this->get($this->thumbUrl($file))->assertNotFound();
        $this->assertSame(0, $this->rasterizer->calls);
    }

    public function test_a_rasterization_failure_is_not_found_and_is_not_cached(): void
    {
        $this->rasterizer->png = null; // simulate a pdftoppm failure
        $file = $this->pdfFile();

        $this->get($this->thumbUrl($file))->assertNotFound();
        Storage::assertMissing($this->cachePath($file));
    }

    public function test_replacing_the_content_invalidates_the_cached_thumbnail(): void
    {
        $file = $this->pdfFile();

        $this->get($this->thumbUrl($file))->assertOk();
        $this->assertSame(1, $this->rasterizer->calls);
        Storage::assertExists($this->cachePath($file));

        // Overwriting the bytes must drop the stale thumbnail...
        app(FileService::class)->replaceContent(
            $file->fresh(),
            UploadedFile::fake()->create('new.pdf', 12, 'application/pdf'),
        );
        Storage::assertMissing($this->cachePath($file));

        // ...so the next request re-renders (a second rasterize).
        $this->get($this->thumbUrl($file))->assertOk();
        $this->assertSame(2, $this->rasterizer->calls);
    }

    public function test_force_deleting_a_file_drops_its_cached_thumbnail(): void
    {
        $file = $this->pdfFile();
        $this->get($this->thumbUrl($file))->assertOk();
        Storage::assertExists($this->cachePath($file));

        app(FileService::class)->forceDelete($file->fresh());

        Storage::assertMissing($this->cachePath($file));
    }

    public function test_a_file_in_another_workspace_is_not_found(): void
    {
        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $other->users()->attach($this->user->id);

        app(TenantContext::class)->set($other);
        $foreign = File::factory()->inFolder(Folder::factory()->create())->create();
        Storage::put($foreign->path, '%PDF-1.4 pretend');
        app(TenantContext::class)->set($this->workspace);

        // Route-model binding is tenant-scoped, so a foreign id never resolves — 404 at bind.
        $this->get($this->thumbUrl($foreign))->assertNotFound();
    }

    public function test_a_non_member_is_forbidden(): void
    {
        $outsider = User::factory()->create(); // not attached to the workspace
        $file = $this->pdfFile();

        $this->actingAs($outsider)
            ->withHeader('X-Workspace-Id', $this->workspace->id)
            ->get($this->thumbUrl($file))
            ->assertStatus(403); // ResolveWorkspace refuses a non-member before anything binds
    }
}
