<?php

namespace App\Modules\Disk\Http\Controllers;

use App\Modules\Disk\Http\Requests\UploadTempFileRequest;
use App\Modules\Disk\Http\Resources\FileResource;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Services\FileService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FilesController
{
    /**
     * Mime types a browser may RENDER on our own origin (?inline=1). Everything else is
     * forced to download, so an uploaded .html can never execute as a same-origin document
     * (a stored-XSS shape). Keep these lists minimal and additive.
     */
    private const INLINE_SAFE_MIME_PREFIXES = ['image/'];

    private const INLINE_SAFE_MIME_TYPES = ['application/pdf'];

    /**
     * Carve-outs that the prefixes above would otherwise wave through. SVG is an XML
     * DOCUMENT, not a bitmap: rendered top-level it executes its own <script> same-origin,
     * and `nosniff` cannot help because the declared type is honest. It downloads instead.
     * (An <img src> embed stays safe and is unaffected — browsers never run scripts there.)
     */
    private const INLINE_UNSAFE_MIME_TYPES = ['image/svg+xml', 'image/svg'];

    public function __construct(
        private FileService $service
    ) {}

    /**
     * Stream a file's bytes. `?inline=1` asks the browser to render it in place (honoured
     * only for {@see INLINE_SAFE_MIME_PREFIXES}/{@see INLINE_SAFE_MIME_TYPES}); otherwise
     * it downloads under its original name.
     *
     * Streamed, never buffered: reading a 100MB upload into a string (the old
     * `Storage::get()` path) put the whole binary in PHP's memory per request.
     *
     * The workspace gate + tenant-scoped binding happen upstream in middleware, so a file
     * that binds here is already this workspace's.
     */
    public function show(File $file): StreamedResponse
    {
        // The row can outlive its blob (legacy/interrupted uploads). Without this guard
        // Storage::response() throws on the Content-Length lookup -> a 500 instead of a 404.
        abort_unless(Storage::exists($file->path), Response::HTTP_NOT_FOUND);

        $mime = $file->mime_type ?: 'application/octet-stream';

        return Storage::response(
            $file->path,
            // makeDisposition() throws on a filename containing a slash. Uploads can't carry
            // one (Symfony basenames the client name), but the rename feature lands next —
            // a user-supplied "a/b.pdf" would otherwise 500 every download of that file.
            basename($file->name),
            [
                'Content-Type' => $mime,
                // Serve exactly the type we declare: never let a browser sniff an upload
                // into something executable.
                'X-Content-Type-Options' => 'nosniff',
            ],
            request()->boolean('inline') && $this->rendersSafelyInline($mime) ? 'inline' : 'attachment',
        );
    }

    public function uploadTemp(UploadTempFileRequest $request): FileResource
    {
        return FileResource::make(
            $this->service->upload($request->file('file'), null)
        );
    }

    /** Whether $mime is safe for a browser to render on our origin. */
    private function rendersSafelyInline(string $mime): bool
    {
        // Normalize before matching: compare the bare type, never a cased or
        // parameterised variant (`IMAGE/SVG+XML`, `image/svg+xml; charset=utf-8`).
        $mime = strtolower(trim(explode(';', $mime, 2)[0]));

        // Deny wins over allow — the prefixes below are broad on purpose.
        if (in_array($mime, self::INLINE_UNSAFE_MIME_TYPES, true)) {
            return false;
        }

        if (in_array($mime, self::INLINE_SAFE_MIME_TYPES, true)) {
            return true;
        }

        foreach (self::INLINE_SAFE_MIME_PREFIXES as $prefix) {
            if (str_starts_with($mime, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
