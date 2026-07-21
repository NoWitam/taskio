<?php

namespace App\Modules\Disk\Services;

use App\Models\User;
use App\Modules\Disk\Models\DiskFileDraft;
use App\Modules\Disk\Models\File;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Per-user autosave DRAFTS of a Disk file edit. The preview editor autosaves its in-progress state
 * ({@see put()}) so a refresh/crash never loses work; the MAIN file is overwritten only on explicit
 * Save (FileService::replaceContent), and the FE deletes its OWN draft after saving.
 *
 * A draft is SELF-CONTAINED — full pixels (image bases) or full content (text) — so it never
 * depends on the file's current bytes. That is why replacing a file's content does NOT clear other
 * users' drafts: staleness is surfaced to the FE via {@see DiskFileDraft::base_version}, not by
 * deletion.
 *
 * Layout on the tenant Storage disk, per draft ({@see draftDir()}):
 *   disk-drafts/{workspaceId}/{userId}/{fileId}/manifest.json   (opaque validated JSON blob)
 *   disk-drafts/.../{fileId}/base-{baseId}.png                  (image only; one per live history base)
 *
 * The backend treats manifest.json as opaque EXCEPT it reads `manifest.baseIds` (image) to know
 * which base blobs are live, so it can GC the ones history truncation dropped.
 */
class DraftService
{
    public function __construct(
        private TenantContext $tenant,
    ) {}

    /**
     * Upsert the draft for ($file, $user): write the manifest, write each provided base blob, and GC
     * any stored base whose id is no longer referenced by manifest.baseIds (history truncated). The
     * FE sends ONLY bases not yet stored, so a normal autosave writes little. Row-first (so the reaper
     * / {@see forget()} can always clean the dir), then storage, then byte_size from the final footprint.
     *
     * @param  array<string, mixed>  $manifest  the decoded, validated manifest (kind + FE-owned state)
     * @param  array<int, UploadedFile>  $baseFiles  new base blobs keyed by baseId (image only)
     */
    public function put(File $file, User $user, array $manifest, ?string $baseVersion, array $baseFiles): DiskFileDraft
    {
        $draft = DiskFileDraft::updateOrCreate(
            ['file_id' => $file->getKey(), 'user_id' => $user->getKey()],
            ['kind' => $manifest['kind'], 'base_version' => $baseVersion],
        );

        $dir = $this->draftDir($file, $user);

        Storage::put($dir . '/manifest.json', $this->encodeManifest($manifest));

        foreach ($baseFiles as $baseId => $upload) {
            Storage::put($dir . '/base-' . $baseId . '.png', $upload->get());
        }

        // Drop base blobs the manifest no longer references. A text draft references none, so this
        // also cleans up if a draft ever flipped image -> text.
        $this->gcBases($dir, $this->liveBaseIds($manifest));

        $draft->update(['byte_size' => $this->dirBytes($dir)]);

        // Attach the just-written manifest for the response resource (transient, never persisted).
        $draft->manifest_data = $manifest;

        return $draft;
    }

    /**
     * This user's draft of $file with its manifest loaded for the resource, or null when none exists.
     * Scoped to the user — never resolves another user's draft.
     */
    public function get(File $file, User $user): ?DiskFileDraft
    {
        $draft = DiskFileDraft::forUser($user)->where('file_id', $file->getKey())->first();

        if ($draft === null) {
            return null;
        }

        $draft->manifest_data = $this->readManifest($file, $user);

        return $draft;
    }

    /**
     * Raw PNG bytes of one base blob for THIS user's draft, or null when the user has no draft or the
     * base is not stored. The path is derived from the AUTH user's id, so another user's request can
     * never resolve this user's base.
     */
    public function baseBytes(File $file, User $user, int $baseId): ?string
    {
        if ($this->get($file, $user) === null) {
            return null;
        }

        $path = $this->draftDir($file, $user) . '/base-' . $baseId . '.png';

        return Storage::exists($path) ? Storage::get($path) : null;
    }

    /**
     * Delete this user's draft — the row AND the whole draft directory. Idempotent: a missing row /
     * directory is a no-op, so a second DELETE (or a save that already cleared it) still succeeds.
     */
    public function forget(File $file, User $user): void
    {
        DiskFileDraft::forUser($user)->where('file_id', $file->getKey())->delete();

        Storage::deleteDirectory($this->draftDir($file, $user));
    }

    /**
     * Prune drafts past the retention window (row + storage dir). Called once on the shared connection
     * and once per own-database workspace by {@see \App\Modules\Disk\Console\ReapStaleDraftsCommand},
     * mirroring the AI-edit reaper. The directory is derived from the ROW (its workspace_id on shared,
     * the active tenant on own-DB), so no File load is needed. Returns the number pruned.
     */
    public function reapStale(): int
    {
        $cutoff = now()->subSeconds($this->retention());

        $stale = DiskFileDraft::query()->where('updated_at', '<', $cutoff)->get();

        foreach ($stale as $draft) {
            Storage::deleteDirectory($this->draftDirFor(
                $draft->getAttribute('workspace_id'),
                $draft->user_id,
                $draft->file_id,
            ));
            $draft->delete();
        }

        return $stale->count();
    }

    // ---- Internals -------------------------------------------------------------------

    /** The retention window in seconds (floor of 60s so a misconfig can't prune everything instantly). */
    private function retention(): int
    {
        return max(60, (int) config('disk.drafts.retention', 86400));
    }

    /** Live base ids referenced by the manifest — image only; a text draft references none. */
    private function liveBaseIds(array $manifest): array
    {
        if (($manifest['kind'] ?? null) !== 'image') {
            return [];
        }

        return array_map('intval', $manifest['baseIds'] ?? []);
    }

    /** Delete stored base blobs whose id is not in $liveIds (history truncation GC). */
    private function gcBases(string $dir, array $liveIds): void
    {
        foreach (Storage::files($dir) as $path) {
            if (preg_match('/^base-(\d+)\.png$/', basename($path), $m) && !in_array((int) $m[1], $liveIds, true)) {
                Storage::delete($path);
            }
        }
    }

    /** Total bytes stored under a draft directory (manifest + live bases). */
    private function dirBytes(string $dir): int
    {
        $total = 0;

        foreach (Storage::files($dir) as $path) {
            $total += Storage::size($path);
        }

        return $total;
    }

    /** Canonical JSON for the manifest — stable, unescaped, so the FE reads back exactly what it sent. */
    private function encodeManifest(array $manifest): string
    {
        return (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Read + decode a draft's manifest, or [] when it is missing / unreadable. */
    private function readManifest(File $file, User $user): array
    {
        $path = $this->draftDir($file, $user) . '/manifest.json';

        if (!Storage::exists($path)) {
            return [];
        }

        $decoded = json_decode((string) Storage::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Storage prefix for one draft, namespaced per workspace exactly like the blob store
     * ({@see FileService}) / thumbnails ({@see ThumbnailService}). Prefer the file's OWN workspace_id
     * (a real column on shared-DB rows) so the path is correct even from a context-cleared pass;
     * own-DB rows omit the column (→ null) but always carry the tenant context, so fall back to it.
     */
    private function draftDir(File $file, User $user): string
    {
        return $this->draftDirFor($file->workspace_id, $user->getKey(), $file->getKey());
    }

    /** The draft prefix from raw ids — used by both {@see draftDir()} and the row-driven reaper. */
    private function draftDirFor(?string $workspaceId, string $userId, string $fileId): string
    {
        $workspace = $workspaceId ?? $this->tenant->id() ?? 'none';

        return 'disk-drafts/' . $workspace . '/' . $userId . '/' . $fileId;
    }
}
