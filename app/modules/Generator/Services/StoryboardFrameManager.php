<?php

namespace App\Modules\Generator\Services;

use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Support\StoryboardFrame;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The lifecycle of ONE storyboard FRAME — the state machine that turns an announced beat into a stored image
 * (the distributed-frames stage). The session-level state machine stays in
 * {@see GenerationSessionRunManager}; this owns only what happens BELOW it, per frame:
 *
 *   claim()    correlated, atomic `pending → rendering`. A frame job may act only on a shot that still
 *              carries the token it was dispatched for, so a redelivered duplicate and a straggler from a
 *              previous (reaped) attempt are both clean no-ops rather than a second billed image.
 *   settle()   write the outcome back into the shot and, when it was the LAST frame outstanding, flip the
 *              session terminal — in ONE transaction, under the row lock.
 *   reapStale()a frame claimed but never settled (worker SIGKILL/OOM between the claim and the write-back)
 *              is failed so its run can finish, instead of hanging until the whole-session backstop.
 *
 * WHY THE ROW LOCK. Frames render CONCURRENTLY and all write into the SAME `results` json. Read-modify-write
 * on that column loses whichever frame commits first — the classic lost update, and here it would silently
 * discard an image the workspace already paid for. So every mutation re-reads the row under
 * `lockForUpdate()` inside a transaction: the second frame blocks until the first commits and then merges
 * into results that already contain it. The lock is held only for the merge, never across the provider call,
 * so frames still run in parallel where it matters.
 *
 * WHY THE LAST FRAME SETTLES. The FE waits for exactly ONE terminal broadcast per run. "Outstanding frames"
 * is derived from the same locked read, so precisely one frame job can observe an empty outstanding set while
 * holding the lock — that one settles, and it is not possible for two to do so.
 *
 * A frame that finds its session no longer `generating` (reaped, or re-claimed by a newer run) still STORES
 * its work if the shot is still its own, but never re-opens a terminal session: the spend already happened,
 * so discarding the image would waste it, while resurrecting a finished run would break the settle contract.
 */
class StoryboardFrameManager
{
    public function __construct(
        private GenerationSessionExecutor $executor,
        private GeneratedImageStore $images,
    ) {}

    /**
     * Claim frame $partKey for $token: `pending → rendering`, stamped with the claim time. Returns the
     * CLAIMED session (a fresh instance the caller renders from) or null when the claim was lost — a
     * duplicate delivery, a straggler from a previous attempt, a session that is no longer generating, or a
     * shot that has since been rewritten. A lost claim is a SILENT, clean no-op by design.
     */
    public function claim(string $sessionId, string $partKey, string $token): ?GenerationSession
    {
        return DB::transaction(function () use ($sessionId, $partKey, $token): ?GenerationSession {
            $locked = $this->lock($sessionId);

            // Only a still-generating run may start a frame: a reaped or re-claimed session must never have
            // a stale job start spending on it.
            if ($locked === null || $locked->status !== GenerationSessionStatus::Generating) {
                return null;
            }

            $results = is_array($locked->results) ? $locked->results : [];
            $shot = $this->shotAt($results, $partKey);

            if (!$this->isOwnedBy($shot, $token, StoryboardFrame::PENDING)) {
                return null;
            }

            $locked->update([
                'results' => $this->withShotAt($results, $partKey, StoryboardFrame::claimed((array) $shot, now()->toIso8601String())),
            ]);

            return $locked;
        });
    }

    /**
     * Render the claimed frame and write its outcome back. The render REUSES the executor's per-shot path
     * ({@see GenerationSessionExecutor::renderPartFromSnapshot} on the `storyboard.<i>` key) — the same path
     * the per-shot regenerate op uses — so a frame is composed, metered, voiced and direction-anchored
     * exactly like every other per-shot render, with no second implementation to keep in step.
     *
     * Returns true when THIS frame settled the run (it was the last one outstanding), so the caller can push
     * the single terminal notification.
     *
     * A REJECTED write-back takes its image with it. The frame may lose its claim WHILE the provider call is
     * in flight (the stale-frame reaper gave up on it; a re-claim superseded the run), and by then the bytes
     * are already stored as a new version. That version is then referenced by nothing at all — not the shot,
     * not the history, not the serve endpoint — so it is deleted here rather than left for the purge to find
     * weeks later. Only a REJECTED settle triggers it: an accepted frame that merely was not the last one
     * keeps its image, obviously.
     */
    public function render(GenerationSession $session, string $partKey, string $token): bool
    {
        // Fail-soft is the executor's own contract: it returns a `failed` result rather than throwing for
        // every domain/provider/budget failure, so a broken frame costs its own job and nothing else.
        $result = $this->executor->renderPartFromSnapshot($session, $partKey);
        $result = is_array($result) ? $result : null;

        ['accepted' => $accepted, 'settled' => $settled] = $this->settleFrame($session->getKey(), $partKey, $token, $result);

        if (!$accepted) {
            $this->discardUnclaimedImage($session->getKey(), $partKey, $result);
        }

        return $settled;
    }

    /**
     * Write a frame's terminal outcome into its shot and, when nothing is left outstanding, flip the session
     * to its terminal status — atomically, under the row lock. $result is the executor's per-shot sub-result
     * (`{status:'ok', image}` / a failed one); null or non-ok settles the frame `failed` fail-soft, leaving
     * every other frame untouched, which is the pre-existing per-shot semantics.
     *
     * Returns true only for the frame that actually performed the terminal flip.
     *
     * @param  array<string, mixed>|null  $result
     */
    public function settle(string $sessionId, string $partKey, string $token, ?array $result): bool
    {
        return $this->settleFrame($sessionId, $partKey, $token, $result)['settled'];
    }

    /**
     * {@see settle()} with both of its outcomes told apart: `accepted` is whether the write-back was MERGED
     * at all (the shot was still ours), `settled` whether it was also the last outstanding frame and
     * therefore flipped the run terminal. The public settle() only needs the second — a caller that stored
     * BYTES needs the first as well, or it cannot know that its image just became unreachable.
     *
     * @param  array<string, mixed>|null  $result
     * @return array{accepted: bool, settled: bool}
     */
    private function settleFrame(string $sessionId, string $partKey, string $token, ?array $result): array
    {
        return DB::transaction(function () use ($sessionId, $partKey, $token, $result): array {
            $locked = $this->lock($sessionId);

            if ($locked === null) {
                return ['accepted' => false, 'settled' => false];
            }

            $results = is_array($locked->results) ? $locked->results : [];
            $shot = $this->shotAt($results, $partKey);

            // The shot must still be OURS: a full re-claim wipes `results` and a newer attempt re-announces
            // frames with new tokens, so a late write-back from a superseded run is dropped, not merged.
            if (!$this->isOwnedBy($shot, $token, StoryboardFrame::RENDERING)) {
                return ['accepted' => false, 'settled' => false];
            }

            $results = $this->withShotAt($results, $partKey, $this->settledShot((array) $shot, $partKey, $result));

            // Still-generating + nothing outstanding = this frame is the last one, so the RUN is over.
            $settles = $locked->status === GenerationSessionStatus::Generating
                && StoryboardFrame::outstandingIn($results) === [];

            $locked->update(['results' => $results] + ($settles ? ['status' => GenerationSessionStatus::Ready] : []));

            return ['accepted' => true, 'settled' => $settles];
        });
    }

    /**
     * Reclaim the produced image of a write-back that was REFUSED. The version deleted is THIS delivery's
     * own allocation, and the store hands out a version strictly above everything on disk for the part, so
     * it is not the number any concurrent writer is holding — the frame's live image (the winner's) always
     * carries a different one.
     *
     * Deliberately AFTER the merge transaction, never inside it: a file-store round trip must not be held
     * across the row lock every sibling frame serializes on. Same posture as the refiner's history-cap GC,
     * for the same reason — a crash in the gap leaves a benign orphan, which is exactly what this method
     * exists to clean up anyway.
     *
     * Logged as a WARNING with the fact only (session, frame, version — never bytes, prompt or shot text):
     * it means the workspace paid for an image it will not receive, which is worth seeing in a trail.
     *
     * @param  array<string, mixed>|null  $result
     */
    private function discardUnclaimedImage(string $sessionId, string $partKey, ?array $result): void
    {
        $version = is_array($result) && ($result['status'] ?? null) === 'ok'
            ? ($result['image']['version'] ?? null)
            : null;

        if (!is_int($version) || $version < 1) {
            return;
        }

        Log::warning('Storyboard frame write-back rejected; discarding its orphaned image.', [
            'session_id' => $sessionId,
            'part_key' => $partKey,
            'version' => $version,
        ]);

        $this->images->deleteVersion($sessionId, $partKey, $version);
    }

    /**
     * Recover frames whose worker died between the claim and the write-back: a `rendering` shot older than
     * $cutoff is failed, and if that clears the last outstanding frame its session settles. Without this a
     * single lost worker would hold a run `generating` until the (much longer) whole-session backstop, with
     * every other frame already done and paid for.
     *
     * Returns how many frames were failed plus the ids of the sessions this pass SETTLED, so the caller can
     * push their terminal notification — a reaper-settled run is a real terminal transition and the FE must
     * hear about it like any other.
     *
     * CHUNKED BY ID, not loaded whole. The candidate set is `generating` sessions carrying a `results` JSON
     * that includes every shot's text — transient and normally tiny, but "normally tiny" is not a memory
     * bound, and a queue outage leaves exactly as many of them as the outage lasted. The keyset cursor is
     * also the correct one here specifically because this sweep MUTATES the column its own scope filters on:
     * an offset-based chunk() would silently SKIP sessions as settled ones drop out of the result set,
     * whereas `id >` never revisits — or misses — what it has already passed.
     *
     * @return array{failed: int, settled: array<int, string>}
     */
    public function reapStaleFrames(DateTimeInterface $cutoff): array
    {
        $failed = 0;
        $settled = [];

        GenerationSession::query()->generatingWithFrames()->chunkById(100, function ($sessions) use ($cutoff, &$failed, &$settled): void {
            foreach ($sessions as $session) {
                foreach ($this->staleFrames($session, $cutoff) as $frame) {
                    Log::warning('Generation session storyboard frame lost (claimed but never settled); failing it.', [
                        'session_id' => $session->getKey(),
                        'part_key' => $frame['part_key'],
                        'claimed_at' => $frame['claimed_at'],
                    ]);

                    $wasLast = $this->settle($session->getKey(), $frame['part_key'], $frame['token'], null);
                    $failed++;

                    if ($wasLast) {
                        $settled[] = (string) $session->getKey();
                    }
                }
            }
        });

        return ['failed' => $failed, 'settled' => $settled];
    }

    /**
     * The frames of $session that are `rendering` and were claimed before $cutoff. A frame with NO claim
     * stamp is treated as stale too — it cannot be aged, and the only way to hold `rendering` without one is
     * a hand-edited or truncated row, which must still be recoverable rather than permanently stuck.
     *
     * @return array<int, array{part_key: string, token: string, status: string, claimed_at: ?string}>
     */
    private function staleFrames(GenerationSession $session, DateTimeInterface $cutoff): array
    {
        $frames = StoryboardFrame::outstandingIn(is_array($session->results) ? $session->results : []);

        return array_values(array_filter(
            $frames,
            fn (array $frame): bool => $frame['status'] === StoryboardFrame::RENDERING
                && ($frame['claimed_at'] === null || strtotime($frame['claimed_at']) < $cutoff->getTimestamp()),
        ));
    }

    /**
     * The terminal shot entry for a frame outcome: the executor's `ok` sub-result becomes the stored image
     * (mirroring the refine loop's per-shot merge), anything else becomes a localized, NON-SECRET failure —
     * the executor's own reason when it gave one, else the lost-frame message the reaper's null stands for.
     * The executor's optional MACHINE-readable `error_code` rides along as the shot's `image_error_code`,
     * which is the same additive contract a Disk edit's `error_code` has.
     *
     * @param  array<string, mixed>  $shot
     * @param  array<string, mixed>|null  $result
     * @return array<string, mixed>
     */
    private function settledShot(array $shot, string $partKey, ?array $result): array
    {
        if (is_array($result) && ($result['status'] ?? null) === 'ok') {
            return StoryboardFrame::settledOk($shot, is_array($result['image'] ?? null) ? $result['image'] : [], $partKey);
        }

        $error = is_array($result) && is_string($result['error'] ?? null)
            ? $result['error']
            : __('generator.sessions.frame_lost');

        $code = is_array($result) && is_string($result['error_code'] ?? null) ? $result['error_code'] : null;

        return StoryboardFrame::settledFailed($shot, $error, $code);
    }

    /**
     * Whether $shot is the frame this job owns: it exists, sits in the expected state, and carries the EXACT
     * correlation token the job was dispatched for. An empty stored token never matches (a frame without a
     * token cannot be claimed by anyone), so a malformed entry fails closed.
     */
    private function isOwnedBy(mixed $shot, string $token, string $expectedStatus): bool
    {
        return is_array($shot)
            && ($shot['image_status'] ?? null) === $expectedStatus
            && $token !== ''
            && ($shot[StoryboardFrame::TOKEN_KEY] ?? null) === $token;
    }

    /** The session row locked FOR UPDATE (the serialization point for every concurrent frame write). */
    private function lock(string $sessionId): ?GenerationSession
    {
        return GenerationSession::query()->whereKey($sessionId)->lockForUpdate()->first();
    }

    /**
     * The shot addressed by a `<storyboardKey>.<i>` frame key inside $results, or null.
     *
     * @param  array<string, mixed>  $results
     * @return array<string, mixed>|null
     */
    private function shotAt(array $results, string $partKey): ?array
    {
        [$baseKey, $index] = $this->splitFrameKey($partKey);

        if ($baseKey === null) {
            return null;
        }

        $base = $results[$baseKey] ?? null;
        $shots = is_array($base['shots'] ?? null) ? array_values($base['shots']) : [];

        return is_array($shots[$index] ?? null) ? $shots[$index] : null;
    }

    /**
     * $results with the shot at a `<storyboardKey>.<i>` frame key REPLACED — the nested write-back, always
     * performed on a freshly locked read so it cannot clobber a concurrent frame.
     *
     * @param  array<string, mixed>  $results
     * @param  array<string, mixed>  $shot
     * @return array<string, mixed>
     */
    private function withShotAt(array $results, string $partKey, array $shot): array
    {
        [$baseKey, $index] = $this->splitFrameKey($partKey);

        if ($baseKey === null || !is_array($results[$baseKey] ?? null)) {
            return $results;
        }

        $base = $results[$baseKey];
        $shots = is_array($base['shots'] ?? null) ? array_values($base['shots']) : [];
        $shots[$index] = $shot;
        $base['shots'] = $shots;
        $results[$baseKey] = $base;

        return $results;
    }

    /**
     * Split a frame key into `[storyboardKey, index]`, or `[null, 0]` when it is not a dotted frame address.
     *
     * @return array{0: ?string, 1: int}
     */
    private function splitFrameKey(string $partKey): array
    {
        return preg_match('/^(.+)\.(\d+)$/', $partKey, $m) === 1 ? [$m[1], (int) $m[2]] : [null, 0];
    }
}
