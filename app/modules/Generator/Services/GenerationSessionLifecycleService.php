<?php

namespace App\Modules\Generator\Services;

use App\Modules\Generator\Models\GenerationSession;

/**
 * Housekeeping for generation SESSIONS (R2 sub-stage 2d) — the reaper surface, SEPARATE from the run
 * state machine ({@see GenerationSessionRunManager}) and the CRUD service. Three windows, each run on the
 * CURRENTLY ACTIVE connection; the scheduled {@see \App\Modules\Generator\Console\ReapGenerationSessionsCommand}
 * sweeps the shared DB and every own-database tenant (per-tenant isolated). Mirrors the Disk-AI reaper
 * ({@see \App\Modules\Disk\Services\ImageAiService::reapStale}/pruneTerminal) — a floor of 60s on every window
 * so a misconfig can never reap/purge instantly.
 *
 * Archive is a blanket FREEZE: an archived session (archived_at set) is exempt from EVERY step below.
 *
 *   reapStale()     a session stuck in `generating` past config('generator.session_stale_after') → `failed`.
 *                   A worker SIGKILL/OOM never fires the job's failed() hook and nothing else recovers it.
 *                   Delegates to the run manager's TERMINAL-SAFE fail() (a session that finished between the
 *                   query and the write is never clobbered). The stale window EXCEEDS the run job's whole
 *                   retry/lock budget (config note), so a slow-but-alive run is never reaped.
 *   trashStale()    a NON-archived session idle past config('generator.session_trash_after') → SOFT-DELETE.
 *                   Archived sessions are exempt (archive disables cleanup).
 *   purgeTrashed()  a soft-deleted, NON-archived session past config('generator.session_purge_after') →
 *                   FORCE-DELETE + GC its produced-image blobs (closes the 2c/2d GC TODO). Archived-then-
 *                   trashed is exempt.
 */
class GenerationSessionLifecycleService
{
    public function __construct(
        private GenerationSessionRunManager $runManager,
        private GeneratedImageStore $images,
    ) {}

    /**
     * Fail sessions stranded in `generating` past the stale window. Reuses the run manager's terminal-safe
     * fail() (re-reads the row + isTerminal guard) so a run that completed in the reap race is never
     * clobbered. Returns the number reaped.
     */
    public function reapStale(): int
    {
        $cutoff = now()->subSeconds(max(60, (int) config('generator.session_stale_after', 1800)));

        $stale = GenerationSession::query()->staleGenerating($cutoff)->get();

        foreach ($stale as $session) {
            $this->runManager->fail($session->id);
        }

        return $stale->count();
    }

    /**
     * Soft-delete (trash) NON-archived sessions idle past the trash window. Returns the number trashed.
     * The purge step later force-deletes these + GCs their blobs; archived sessions never reach here.
     */
    public function trashStale(): int
    {
        $cutoff = now()->subSeconds(max(60, (int) config('generator.session_trash_after', 604800)));

        $stale = GenerationSession::query()->trashable($cutoff)->get();

        foreach ($stale as $session) {
            $session->delete();
        }

        return $stale->count();
    }

    /**
     * Force-delete soft-deleted, NON-archived sessions past the purge window AND garbage-collect their
     * produced-image blobs. The blob prefix is derived from the ROW's own workspace_id (null on own-DB, where
     * the store falls back to the active tenant) so the shared-DB pass — which runs with the context cleared —
     * still targets the real prefix. Returns the number purged.
     */
    public function purgeTrashed(): int
    {
        $cutoff = now()->subSeconds(max(60, (int) config('generator.session_purge_after', 2592000)));

        $purgable = GenerationSession::query()->purgable($cutoff)->get();

        foreach ($purgable as $session) {
            $this->images->clearSessionForWorkspace($session->getAttribute('workspace_id'), $session->id);
            $session->forceDelete();
        }

        return $purgable->count();
    }
}
