<?php

namespace App\Modules\Generator\Services;

use App\Modules\Generator\Models\GenerationSession;

/**
 * Housekeeping for generation SESSIONS (R2 sub-stage 2d) — the reaper surface, SEPARATE from the run
 * state machine ({@see GenerationSessionRunManager}) and the CRUD service. Five windows, each run on the
 * CURRENTLY ACTIVE connection; the scheduled {@see \App\Modules\Generator\Console\ReapGenerationSessionsCommand}
 * sweeps the shared DB and every own-database tenant (per-tenant isolated). Mirrors the Disk-AI reaper
 * ({@see \App\Modules\Disk\Services\ImageAiService::reapStale}/pruneTerminal) — a floor of 60s on every window
 * so a misconfig can never reap/purge instantly.
 *
 * Archive is a blanket FREEZE: an archived session (archived_at set) is exempt from EVERY step below.
 *
 *   reapStaleFrames() a storyboard FRAME claimed but never settled past
 *                   config('generator.frame_stale_after') → that frame `failed`, and its run settles if it
 *                   was the last one outstanding. The FINER-GRAINED recovery that has to run first: it saves
 *                   a nearly-complete run that reapStale() below would otherwise discard whole.
 *   reapStale()     a session stuck in `generating` past config('generator.session_stale_after') → `failed`.
 *                   A worker SIGKILL/OOM never fires the job's failed() hook and nothing else recovers it.
 *                   Delegates to the run manager's TERMINAL-SAFE fail() (a session that finished between the
 *                   query and the write is never clobbered). The stale window EXCEEDS the run job's whole
 *                   retry/lock budget (config note), so a slow-but-alive run is never reaped.
 *   trashStale()    a NON-archived session idle past config('generator.session_trash_after') → SOFT-DELETE.
 *                   Archived sessions are exempt (archive disables cleanup).
 *   purgeTrashed()  a soft-deleted, NON-archived session past config('generator.session_purge_after') →
 *                   FORCE-DELETE + GC its produced-image blobs AND its frozen character reference (closes
 *                   the 2c/2d GC TODO). Archived-then-trashed is exempt.
 *   purgeArchivedIdentityImages()
 *                   the ONE thing the archive freeze may not keep forever: a trashed-past-the-purge-window
 *                   ARCHIVED session gives up its frozen character LIKENESS (a copy of a real person's face)
 *                   while keeping its row and its produced content.
 */
class GenerationSessionLifecycleService
{
    public function __construct(
        private GenerationSessionRunManager $runManager,
        private GeneratedImageStore $images,
        private StoryboardFrameManager $frames,
        private SessionIdentityImageStore $identityImages,
    ) {}

    /**
     * Fail storyboard FRAMES that were claimed but never settled, and settle any run that was only waiting
     * on them. A frame job killed between its claim and its write-back (SIGKILL/OOM) never fires its own
     * failed() hook, and — unlike a lost whole-run job — leaves a run whose OTHER frames are finished and
     * paid for hanging on one dead beat. The whole-session stale window is still the backstop underneath
     * this, but it is 30 minutes and would discard a run that is 7/8 complete; failing just the lost frame
     * lets the run finish `ready` with a per-frame failure, which is the same fail-soft outcome the frame
     * would have produced had its worker lived.
     *
     * Runs BEFORE {@see reapStale} in the sweep so a run rescued here is not then reaped as a whole. Returns
     * the number of frames failed.
     */
    public function reapStaleFrames(): int
    {
        $cutoff = now()->subSeconds(max(60, (int) config('generator.frame_stale_after', 900)));

        ['failed' => $failed, 'settled' => $settled] = $this->frames->reapStaleFrames($cutoff);

        // A run the sweep just finished is a REAL terminal transition, so it gets the same single broadcast
        // any other settle does — otherwise the chat would sit waiting on a run that is already done.
        foreach ($settled as $sessionId) {
            $session = GenerationSession::find($sessionId);

            if ($session !== null) {
                $this->runManager->announceSettled($session, 'frame_reaper');
            }
        }

        return $failed;
    }

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
     * Force-delete soft-deleted, NON-archived sessions past the purge window AND garbage-collect BOTH of the
     * session's byte stores: its produced-image blobs and its FROZEN CHARACTER reference. The two live under
     * separate roots on purpose — a full re-run wipes the produced images and must NOT touch the frozen
     * character — so the purge is the one place that has to name both, or the identity bytes of every
     * delegated session would outlive the row forever.
     *
     * Each prefix is derived from the ROW's own workspace_id (null on own-DB, where the stores fall back to
     * the active tenant) so the shared-DB pass — which runs with the context cleared — still targets the real
     * prefix. Returns the number purged.
     */
    public function purgeTrashed(): int
    {
        $cutoff = now()->subSeconds(max(60, (int) config('generator.session_purge_after', 2592000)));

        $purgable = GenerationSession::query()->purgable($cutoff)->get();

        foreach ($purgable as $session) {
            $workspaceId = $session->getAttribute('workspace_id');

            $this->images->clearSessionForWorkspace($workspaceId, $session->id);
            $this->identityImages->clearSessionForWorkspace($workspaceId, $session->id);
            $session->forceDelete();
        }

        return $purgable->count();
    }

    /**
     * Reclaim the FROZEN CHARACTER references of trashed sessions the row purge deliberately never reaches —
     * the ARCHIVED ones.
     *
     * Archive is a blanket freeze, and for the row and its produced content that is exactly right: the user
     * asked to keep them. A frozen character reference is a different kind of object. It is a COPY of a real
     * person's likeness, taken from a bot's approved image so the session would keep drawing the same face,
     * and it is only ever readable while the session can still render. A session that has been in the trash
     * past the purge window can not: there is no restore route, so the row is unreachable through the API for
     * good. Keeping a face on disk indefinitely for a run that can never happen again is retention nobody
     * asked for, and the archive exemption made it permanent.
     *
     * Deliberately NARROW: identity bytes only, and only for rows the row-purge already declined. The
     * produced images are the archived CONTENT and stay; the row stays; nothing that a non-archived session
     * relies on is touched (those are handled by {@see purgeTrashed}, which runs first and takes both stores
     * with the row). Returns how many sessions were swept.
     */
    public function purgeArchivedIdentityImages(): int
    {
        $cutoff = now()->subSeconds(max(60, (int) config('generator.session_purge_after', 2592000)));

        $sessions = GenerationSession::query()->archivedPurgableIdentity($cutoff)->get();

        foreach ($sessions as $session) {
            $this->identityImages->clearSessionForWorkspace($session->getAttribute('workspace_id'), $session->id);
        }

        return $sessions->count();
    }
}
