<?php

namespace App\Modules\Generator\Services;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

/**
 * Stores + reads a generation session's PRODUCED images. A run stores each ok image part's bytes in a
 * session-scoped area; the chat previews them via the serve endpoint and the user promotes one to their Disk
 * with "Zapisz na Dysk". The bytes live OUTSIDE the session row (which stays lean — the `results` JSON carries
 * only `{mime,width,height,version}`, never bytes).
 *
 * VERSIONED (R2 sub-stage 2d): each part's images are kept under a PER-PART directory, one file per version, so
 * the refine loop's undo can restore an earlier version's bytes. Layout mirrors the Disk-AI `inputDirectory`
 * pattern — namespaced per workspace for tenant GC:
 *   `generator-sessions/<workspaceId>/<sessionId>/<partKey>/<version>.png`
 * The partKey is sanitized to a safe path segment (a scene image uses `scene_plan.<i>`) and the version is an
 * int, so a crafted key/version can never traverse out of the session's own prefix.
 *
 * Version allocation is the store's own authority: {@see storeVersion} picks the next int above every version
 * currently on disk for that part. Because every LIVE version (the current result + each history entry) keeps
 * its blob until it leaves the stack (undo-discard or history-cap GC via {@see deleteVersion}), the on-disk set
 * equals the live set, so a freshly allocated version is always strictly greater than any still-referenced one
 * — it never collides with a version an undo could restore to.
 *
 * The session lifecycle reaper (R2 sub-stage 2d — the scheduled `generator:reap-sessions` command) deletes this
 * whole `generator-sessions/<workspaceId>/<sessionId>/` prefix on PURGE via {@see clearSessionForWorkspace},
 * which derives the workspace from the row so the context-cleared shared-DB sweep still targets the right prefix.
 */
class GeneratedImageStore
{
    public function __construct(
        private TenantContext $tenant,
    ) {}

    /**
     * Persist a produced image's PNG bytes as the NEXT version for ($sessionId, $partKey) and return that
     * version. Allocates the version above every version already on disk for the part, so the current + all
     * history blobs are preserved and the new one never overwrites a version an undo might restore to.
     */
    public function storeVersion(string $sessionId, string $partKey, string $bytes): int
    {
        $version = $this->nextVersion($sessionId, $partKey);

        Storage::put($this->path($sessionId, $partKey, $version), $bytes);

        return $version;
    }

    /** Read a specific version's bytes, or null when none was stored (a failed / never-run / undone part). */
    public function get(string $sessionId, string $partKey, int $version): ?string
    {
        $path = $this->path($sessionId, $partKey, $version);

        return Storage::exists($path) ? Storage::get($path) : null;
    }

    /** Whether a specific version's produced image exists for ($sessionId, $partKey). */
    public function exists(string $sessionId, string $partKey, int $version): bool
    {
        return Storage::exists($this->path($sessionId, $partKey, $version));
    }

    /**
     * Delete ONE version's blob — for an undo's discard of the just-undone version and for the history-cap GC
     * of the oldest dropped prior. No-ops when the blob is already gone. Never touches other versions, so the
     * remaining live set (current + history) keeps serving.
     */
    public function deleteVersion(string $sessionId, string $partKey, int $version): void
    {
        Storage::delete($this->path($sessionId, $partKey, $version));
    }

    /**
     * Delete EVERY produced-image blob for $sessionId — the whole session directory (all parts, all versions).
     * Called when a WHOLE-session run is (re)claimed (`mode:full`) so a re-run starts CLEAN; a per-part op
     * (regenerate/refine) NEVER calls this (it must not destroy other parts' versions). No-ops on a first run.
     */
    public function clearSession(string $sessionId): void
    {
        Storage::deleteDirectory($this->sessionDirectory($sessionId));
    }

    /**
     * Delete every produced-image blob for $sessionId under an EXPLICIT workspace id — the lifecycle
     * PURGE's blob GC (closes the 2c/2d GC TODO). The purge sweep runs the shared-DB pass with the
     * tenant context CLEARED, so it MUST derive the workspace from the row's own `workspace_id` rather
     * than the (absent) active tenant — otherwise it would target `none/…` and miss the real blobs
     * (mirrors DraftService::reapStale). An own-database row omits the column (→ null): its blobs live
     * under the tenant connection with the context active during the own-DB pass, so a null id falls
     * back to the store's active tenant.
     */
    public function clearSessionForWorkspace(?string $workspaceId, string $sessionId): void
    {
        Storage::deleteDirectory($this->sessionDirectoryFor($workspaceId ?? $this->tenant->id(), $sessionId));
    }

    /**
     * The workspace-namespaced, session- + part- + version-scoped storage path for a produced image. The
     * partKey is reduced to `[A-Za-z0-9._-]` so it is always a single safe path segment (no slash → no
     * traversal out of the session prefix); the version is an int, likewise safe.
     */
    public function path(string $sessionId, string $partKey, int $version): string
    {
        return $this->partDirectory($sessionId, $partKey) . '/' . $version . '.png';
    }

    /**
     * The next version int for a part: one above the highest numeric blob currently on disk under the part
     * directory (0 when none). Because the on-disk set is exactly the live set (see the class note), this is
     * always greater than any version the current result or history still references.
     */
    private function nextVersion(string $sessionId, string $partKey): int
    {
        $max = 0;

        foreach (Storage::files($this->partDirectory($sessionId, $partKey)) as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);

            if (ctype_digit($name)) {
                $max = max($max, (int) $name);
            }
        }

        return $max + 1;
    }

    /** The workspace-namespaced directory holding all of ($sessionId, $partKey)'s versioned blobs. */
    private function partDirectory(string $sessionId, string $partKey): string
    {
        $safeKey = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $partKey);

        return $this->sessionDirectory($sessionId) . '/' . $safeKey;
    }

    /** The workspace-namespaced directory holding all of $sessionId's produced-image blobs. */
    private function sessionDirectory(string $sessionId): string
    {
        return $this->sessionDirectoryFor($this->tenant->id(), $sessionId);
    }

    /**
     * The session directory for an EXPLICIT workspace id (null → the store's active tenant, else 'none').
     * Shared by the active-context {@see sessionDirectory} and the row-driven
     * {@see clearSessionForWorkspace} so both compute the identical prefix.
     */
    private function sessionDirectoryFor(?string $workspaceId, string $sessionId): string
    {
        $workspace = $workspaceId ?? $this->tenant->id() ?? 'none';

        return 'generator-sessions/' . $workspace . '/' . $sessionId;
    }
}
