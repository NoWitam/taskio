<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Enums\FileType;
use App\Modules\Disk\Models\File;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * SERVING CONTRACT + HARDENING for the Disk module (R1-B0).
 *
 * Two halves, deliberately in one file so they are read together:
 *
 * 1. CONSUMER PINS — the two live readers of `GET /api/disk/{file}` are fragile and were
 *    unpinned: the report markdown preview (axios `responseType: 'text'` over the plain
 *    download URL) and the task-attachment image preview (`?inline=1`, `responseType:
 *    'blob'`). Both only care about BYTES + Content-Type; they cannot observe whether the
 *    body arrives as a plain or streamed response, which is why {@see bodyOf()} tolerates
 *    both. These tests must hold before AND after the hardening.
 *
 * 2. HARDENING — serving is fail-closed on workspace context, never sniffable, and only
 *    renders inline for mime types that are safe to render on the app origin.
 */
class DiskServingTest extends TestCase
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
     * Run $build with $workspace as the ACTIVE shared tenant, so models created inside are
     * stamped workspace_id = $workspace->id (TenantAware::creating) — the same state
     * ResolveWorkspace installs for a live request (mirrors CrossWorkspaceBindingTest).
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

    /** A stored file (row + real bytes on the fake disk) owned by $workspace. */
    private function storedFile(
        Workspace $workspace,
        User $uploader,
        string $name,
        string $mime,
        string $contents,
        FileType $type = FileType::DOCUMENT,
    ): File {
        $file = $this->within($workspace, fn () => File::create([
            'name' => $name,
            'path' => 'uploads/' . Str::uuid() . '.' . pathinfo($name, PATHINFO_EXTENSION),
            'type' => $type,
            'mime_type' => $mime,
            'size' => strlen($contents),
            'uploader_id' => $uploader->id,
        ]));

        Storage::put($file->path, $contents);

        return $file;
    }

    /**
     * The response body as the HTTP client sees it. Streamed and buffered responses are
     * indistinguishable on the wire, but PHPUnit reads them differently — so a pin that
     * asserts the CONTRACT (bytes) must accept either.
     */
    private function bodyOf(TestResponse $response): string
    {
        return $response->baseResponse instanceof StreamedResponse
            ? $response->streamedContent()
            : (string) $response->baseResponse->getContent();
    }

    // ---- Consumer pins (must hold before AND after the hardening) --------------

    public function test_report_markdown_download_returns_its_body_verbatim(): void
    {
        Storage::fake();
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $markdown = "# Raport\n\nTreść raportu.";
        // CreateFormReport stores generated reports as text/plain (mime is inaccurate for
        // a .md, fixed in a later batch) — pin the shape that actually exists today.
        $file = $this->storedFile($workspace, $user, 'raport.md', 'text/plain', $markdown);

        $response = $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->get("/api/disk/{$file->id}");

        $response->assertOk();
        // The FE reads this with axios `responseType: 'text'` (forms store fetchReportFile).
        $this->assertSame($markdown, $this->bodyOf($response));
    }

    public function test_inline_image_preview_returns_bytes_with_its_image_content_type(): void
    {
        Storage::fake();
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $bytes = UploadedFile::fake()->image('shot.png')->getContent();
        $file = $this->storedFile($workspace, $user, 'shot.png', 'image/png', $bytes, FileType::IMAGE);

        $response = $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->get("/api/disk/{$file->id}?inline=1");

        $response->assertOk();
        // The FE reads this with `responseType: 'blob'` and URL.createObjectURL's it, so the
        // Content-Type is load-bearing (it becomes the Blob type).
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame($bytes, $this->bodyOf($response));
    }

    // ---- Hardening: fail-closed workspace context ------------------------------

    public function test_download_fails_closed_when_no_workspace_header_is_sent(): void
    {
        Storage::fake();

        $member = User::factory()->create();
        $this->workspaceFor($member);

        $ownerB = User::factory()->create();
        $workspaceB = $this->workspaceFor($ownerB);
        $fileB = $this->storedFile($workspaceB, $ownerB, 'secret.pdf', 'application/pdf', 'top-secret');

        // Without the header ResolveWorkspace clears the context, WorkspaceScope goes inert
        // and the binding would resolve ANY workspace's file by id — every authenticated
        // user could read every workspace's bytes. The route must refuse instead.
        // (getJson mirrors the api client, which always sends Accept: application/json.)
        $response = $this->actingAs($member)->getJson("/api/disk/{$fileB->id}");

        $response->assertStatus(400);
        $this->assertStringNotContainsString('top-secret', $this->bodyOf($response));
    }

    public function test_temp_upload_fails_closed_when_no_workspace_header_is_sent(): void
    {
        Storage::fake();
        $user = User::factory()->create();
        $this->workspaceFor($user);

        $this->actingAs($user)
            ->postJson('/api/disk/temp', ['file' => UploadedFile::fake()->create('doc.pdf', 10)])
            ->assertStatus(400);
    }

    // ---- Hardening: disposition + sniffing -------------------------------------

    public function test_downloads_are_attachments_and_never_sniffable(): void
    {
        Storage::fake();
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);
        $file = $this->storedFile($workspace, $user, 'doc.pdf', 'application/pdf', 'body');

        $response = $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->get("/api/disk/{$file->id}");

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_inline_html_is_forced_to_download_instead_of_rendering_on_our_origin(): void
    {
        Storage::fake();
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        // An uploaded .html/.svg rendered inline on the app origin is a stored-XSS shape:
        // only an allowlist of safe-to-render types may be inline, everything else downloads.
        $file = $this->storedFile($workspace, $user, 'evil.html', 'text/html', '<script>alert(1)</script>');

        $response = $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->get("/api/disk/{$file->id}?inline=1");

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_inline_svg_is_forced_to_download_although_it_is_an_image(): void
    {
        Storage::fake();
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        // SVG is the trap in an `image/*` allowlist: it is an XML DOCUMENT that runs its own
        // <script> same-origin when rendered top-level, and nosniff cannot help because the
        // declared type is truthful. finfo reports image/svg+xml for a real .svg upload, so
        // this reaches the allowlist honestly — no spoofing needed.
        $file = $this->storedFile(
            $workspace,
            $user,
            'evil.svg',
            'image/svg+xml',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            FileType::IMAGE,
        );

        $response = $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->get("/api/disk/{$file->id}?inline=1");

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith(
            'attachment;',
            (string) $response->headers->get('Content-Disposition'),
            'An SVG must never be served inline — it would execute on our origin.',
        );
    }

    public function test_inline_pdf_is_allowed_to_render(): void
    {
        Storage::fake();
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);
        $file = $this->storedFile($workspace, $user, 'doc.pdf', 'application/pdf', 'body');

        $response = $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->get("/api/disk/{$file->id}?inline=1");

        $response->assertOk();
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_a_missing_binary_404s_instead_of_serving_an_empty_body(): void
    {
        Storage::fake();
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        // Row exists, blob does not (a legacy/interrupted upload).
        $file = $this->within($workspace, fn () => File::create([
            'name' => 'ghost.pdf',
            'path' => 'uploads/' . Str::uuid() . '.pdf',
            'type' => FileType::DOCUMENT,
            'mime_type' => 'application/pdf',
            'size' => 10,
            'uploader_id' => $user->id,
        ]));

        $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->get("/api/disk/{$file->id}")
            ->assertNotFound();
    }

    // ---- Temp upload -----------------------------------------------------------

    public function test_temp_upload_stores_the_file_scoped_to_the_active_workspace(): void
    {
        Storage::fake();
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $response = $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/disk/temp', ['file' => UploadedFile::fake()->image('avatar.png')]);

        $response->assertCreated();

        $file = File::query()->withoutGlobalScopes()->sole();
        $this->assertSame($workspace->id, $file->workspace_id, 'The upload must be stamped with the active workspace.');
        $this->assertNull($file->fileable_type, 'A fresh upload is a temp file until it is attached.');
        $this->assertSame($user->id, $file->uploader_id);
        Storage::assertExists($file->path);
    }

    public function test_temp_upload_rejects_a_file_over_the_size_cap(): void
    {
        Storage::fake();
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/disk/temp', ['file' => UploadedFile::fake()->create('huge.zip', 102401)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_temp_upload_requires_a_file(): void
    {
        Storage::fake();
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/disk/temp', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    // ---- Throttling ------------------------------------------------------------

    public function test_reading_files_does_not_consume_the_upload_budget(): void
    {
        Storage::fake();
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);
        $file = $this->storedFile($workspace, $user, 'doc.pdf', 'application/pdf', 'body');

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id);

        foreach (range(1, 5) as $ignored) {
            $this->get("/api/disk/{$file->id}")->assertOk();
        }

        $upload = $this->postJson('/api/disk/temp', ['file' => UploadedFile::fake()->image('a.png')]);

        $upload->assertCreated();
        // ThrottleRequests keys on the USER unless a prefix is given, so a shared counter
        // would show the reads already eaten out of the upload budget here (and would 429
        // other modules' throttled routes for the same user).
        $this->assertSame('60', $upload->headers->get('X-RateLimit-Limit'));
        $this->assertSame(
            '59',
            $upload->headers->get('X-RateLimit-Remaining'),
            'The upload limiter must have its own bucket: 5 reads may not spend upload attempts.',
        );
    }

    // ---- Factory ---------------------------------------------------------------

    public function test_the_file_factory_resolves_and_produces_a_usable_row(): void
    {
        // Module models live outside the factory resolver's expected namespace, so a missing
        // newFactory() override makes File::factory() throw at the first call — this pins that
        // the factory every later R1 batch builds on actually works.
        $file = File::factory()->image()->create();

        $this->assertSame('image/png', $file->mime_type);
        $this->assertSame(FileType::IMAGE, $file->type);
        $this->assertNull($file->fileable_type, 'The default state is a temp upload.');
        $this->assertNotNull($file->uploader_id);
        $this->assertDatabaseHas('files', ['id' => $file->id]);
    }
}
