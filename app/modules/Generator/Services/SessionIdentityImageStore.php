<?php

namespace App\Modules\Generator\Services;

use App\Modules\Generator\Models\GenerationSession;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

/**
 * Stores a generation session's FROZEN CHARACTER REFERENCE bytes — the image half of the delegation
 * snapshot, kept next to (never inside) the session's produced images.
 *
 * WHY A COPY AT ALL. The delegated session could hold the source file's id and read it at render time, and
 * that would be wrong for exactly the reason the VOICE is snapshotted: the human may re-approve a different
 * likeness, or delete the file, between delegating and generating — and a run that silently changes the
 * person it draws (or fails halfway through a storyboard because the reference vanished) is worse than one
 * that keeps drawing who it was told to. The bytes are therefore COPIED at delegation time and the session
 * never reads the source again. `source_file_id` survives on the overlay as PROVENANCE only.
 *
 * KEYED PER CHARACTER, not per session:
 *   `generation-identity/<workspaceId>/<sessionId>/<characterKey>.png`
 * v1 freezes exactly one character (the delegated author), but a frame that has to show TWO characters is
 * the obvious next ask, and it must not require re-shaping stored sessions. The key is reduced to a single
 * safe path segment, so a crafted key can never traverse out of the session's own prefix.
 *
 * DELIBERATELY OUTSIDE `generator-sessions/…`. That prefix is wiped WHOLESALE by
 * {@see GeneratedImageStore::clearSession} on every full (re)claim, so a re-run of a delegated session would
 * destroy its own character reference and then draw the rest of the run without it — a silent, expensive,
 * hard-to-explain drift. A separate root makes that impossible by construction (pinned by a test), and the
 * lifecycle reaper purges BOTH prefixes when a session is finally deleted.
 *
 * The bytes are content: never logged, never returned to a client by this class.
 */
class SessionIdentityImageStore
{
    /** The storage root — deliberately NOT under the produced-image root (see the class note). */
    private const ROOT = 'generation-identity';

    public function __construct(
        private TenantContext $tenant,
    ) {}

    /** Freeze one character's reference bytes for $sessionId. Overwrites a prior freeze (re-delegation). */
    public function put(string $sessionId, string $characterKey, string $bytes): void
    {
        Storage::put($this->path($sessionId, $characterKey), $bytes);
    }

    /** One character's frozen bytes, or null when none was frozen (an undelegated / imageless session). */
    public function get(string $sessionId, string $characterKey): ?string
    {
        $path = $this->path($sessionId, $characterKey);

        return Storage::exists($path) ? Storage::get($path) : null;
    }

    /** Whether $sessionId holds frozen bytes for $characterKey. */
    public function exists(string $sessionId, string $characterKey): bool
    {
        return Storage::exists($this->path($sessionId, $characterKey));
    }

    /**
     * The frozen reference for a SESSION's current character, or null when it has none — the read the image
     * pipeline actually makes. Keeps the "which character" question in ONE place (the session row's own
     * {@see GenerationSession::characterImageKey}) instead of at every call site.
     */
    public function forSession(GenerationSession $session): ?string
    {
        $key = $session->characterImageKey();

        return $key === null ? null : $this->get($session->id, $key);
    }

    /**
     * Drop EVERY frozen character of $sessionId — undoing a delegation (the character stops authoring, so
     * its likeness must stop being drawn and must not linger as orphaned bytes) and the lifecycle purge.
     * No-ops when nothing was frozen.
     */
    public function clearSession(string $sessionId): void
    {
        Storage::deleteDirectory($this->sessionDirectoryFor($this->tenant->id(), $sessionId));
    }

    /**
     * Drop every frozen character of $sessionId EXCEPT $keepKey — how a RE-DELEGATION reclaims the previous
     * author's bytes AFTER the new overlay has committed.
     *
     * It exists so that "let go of the old" and "hold on to the new" are not the same operation. Wiping the
     * whole directory first would work only because the write that follows re-creates one file, and any
     * failure in between leaves the session naming a likeness that is gone. Sweeping afterwards, with the
     * surviving file named, is ordered so that every intermediate state is safe: at worst an unreferenced
     * file lingers until the next delegation or the purge, which is a benign orphan rather than a dangling
     * reference.
     *
     * A null $keepKey keeps nothing (the new author froze no likeness) and is then exactly clearSession().
     */
    public function clearSessionExcept(string $sessionId, ?string $keepKey): void
    {
        $keep = $keepKey === null ? null : $this->path($sessionId, $keepKey);

        foreach (Storage::files($this->sessionDirectoryFor($this->tenant->id(), $sessionId)) as $file) {
            if ($file !== $keep) {
                Storage::delete($file);
            }
        }
    }

    /**
     * Drop every frozen character of $sessionId under an EXPLICIT workspace id — the lifecycle PURGE's GC.
     * The shared-DB purge sweep runs with the tenant context CLEARED, so it must derive the workspace from
     * the row rather than from the (absent) active tenant; an own-database row omits the column and falls
     * back to the active tenant of that pass. Mirrors {@see GeneratedImageStore::clearSessionForWorkspace}
     * exactly, so the two prefixes are always purged the same way.
     */
    public function clearSessionForWorkspace(?string $workspaceId, string $sessionId): void
    {
        Storage::deleteDirectory($this->sessionDirectoryFor($workspaceId ?? $this->tenant->id(), $sessionId));
    }

    /** The workspace-namespaced, session- + character-scoped path for one frozen reference. */
    public function path(string $sessionId, string $characterKey): string
    {
        $safeKey = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $characterKey);

        return $this->sessionDirectoryFor($this->tenant->id(), $sessionId) . '/' . $safeKey . '.png';
    }

    /** The directory holding every frozen character of one session (null workspace → 'none', as elsewhere). */
    private function sessionDirectoryFor(?string $workspaceId, string $sessionId): string
    {
        return self::ROOT . '/' . ($workspaceId ?? $this->tenant->id() ?? 'none') . '/' . $sessionId;
    }
}
