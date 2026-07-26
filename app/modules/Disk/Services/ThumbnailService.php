<?php

namespace App\Modules\Disk\Services;

use App\Modules\Disk\Models\File;
use App\Modules\Disk\Support\PdfRasterizer;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

/**
 * Server-side thumbnails for the file grid. Today PDFs ONLY (first page -> small PNG); anything else
 * returns null so the grid falls back to the type glyph. The shape is kept extensible for video/other
 * formats later — add a branch to {@see isSupported()} + a rasterizer.
 *
 * A generated thumbnail is CACHED on the tenant Storage disk at a stable, per-file derived path
 * ({@see cachePath()}), namespaced per workspace exactly like the blob store ({@see FileService})
 * so tenant GC and any future move to object storage stay possible. Serve-if-exists, else generate
 * and cache. The cache is invalidated by {@see forget()} whenever a file's bytes change or it is
 * force-deleted / GC'd (see FileService::replaceContent/forceDelete/pruneTempFiles).
 */
class ThumbnailService
{
    public function __construct(
        private PdfRasterizer $rasterizer,
        private TenantContext $tenant,
    ) {}

    /**
     * Cached PNG thumbnail bytes for $file — generated and cached on first request. Returns null when
     * thumbnails are disabled, the file is an unsupported type, its blob is missing, or rendering
     * fails; the controller turns that into a 404 (never a 500).
     */
    public function render(File $file): ?string
    {
        if (!$this->enabled() || !$this->isSupported($file)) {
            return null;
        }

        $cachePath = $this->cachePath($file);

        if (Storage::exists($cachePath)) {
            return Storage::get($cachePath);
        }

        // The row can outlive its blob (a legacy / interrupted upload) — no bytes, no thumbnail.
        if (!Storage::exists($file->path)) {
            return null;
        }

        $png = $this->rasterizer->rasterizeFirstPage(Storage::get($file->path));

        if ($png === null) {
            return null;
        }

        Storage::put($cachePath, $png);

        return $png;
    }

    /**
     * Drop a file's cached thumbnail. Called when the bytes change (replaceContent) or the file is
     * removed (forceDelete / temp GC). A no-op when nothing is cached, so it is always safe to call.
     */
    public function forget(File $file): void
    {
        Storage::delete($this->cachePath($file));
    }

    /**
     * Whether $file is a format we can rasterize. PDFs only for now: trust the stored mime, and fall
     * back to a `.pdf` extension only when the mime is absent (a mislabeled upload still fails closed
     * in the rasterizer, so a false positive here is at worst a wasted, harmless render attempt).
     */
    private function isSupported(File $file): bool
    {
        if ($file->mime_type === 'application/pdf') {
            return true;
        }

        return $file->mime_type === null
            && strtolower(pathinfo((string) $file->name, PATHINFO_EXTENSION)) === 'pdf';
    }

    private function enabled(): bool
    {
        return (bool) config('disk.thumbnails.enabled', true);
    }

    /**
     * A STABLE per-file derived cache path, namespaced per workspace for tenant GC (like uploads/).
     * Prefer the file's OWN workspace_id (a real column on shared-DB rows) so the path is correct even
     * when called from a context-cleared pass (temp GC clears the tenant): otherwise `forget()` would
     * compute a `none/…` path and miss the real cached thumbnail. Own-DB rows omit the column (→ null),
     * but their every access carries the tenant context, so `tenant->id()` is the right fallback there.
     */
    private function cachePath(File $file): string
    {
        $workspace = $file->workspace_id ?? $this->tenant->id() ?? 'none';

        return 'disk-thumbs/' . $workspace . '/' . $file->getKey() . '.png';
    }
}
