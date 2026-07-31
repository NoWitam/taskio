<?php

namespace App\Modules\Generator\Support;

/**
 * The SHAPE + vocabulary of one in-flight storyboard FRAME (the distributed-frames stage).
 *
 * A storyboard image used to be produced inline, inside the session job's own loop, so a shot entry only
 * ever existed in two states: `ok` or `failed`. Rendering each frame in its OWN queue job introduces a
 * lifetime between those two — the frame is announced, then claimed, then settled — and that lifetime needs
 * a representation. It is deliberately stored IN the shot entry itself rather than in a side table: the
 * `results` map is already the run's state of record, already written under the session row's lock, and
 * already read by everything that asks what a run produced. A separate frames table would have to be kept in
 * sync with it under exactly the concurrency this stage introduces.
 *
 *   pending    the session job listed this beat and queued its job; nothing has been spent.
 *   rendering  a frame job WON the claim (see the token note) and is talking to the provider.
 *   ok/failed  terminal, and BYTE-IDENTICAL to the shape produced before this stage — the two transient
 *              keys are REMOVED on settle, so a settled run's `results` are indistinguishable from a run
 *              rendered inline. That is the contract the FE (untouched here) already reads.
 *
 * THE TOKEN is what makes a redelivered or orphaned job safe. Queues are at-least-once, so the SAME frame
 * job can arrive twice; and a run that was reaped and re-run leaves stragglers from the previous attempt
 * still in flight. A frame job may therefore only act on a shot that still carries the EXACT token it was
 * dispatched for — a duplicate finds `rendering` (or a terminal status) and stops, a straggler from a dead
 * run finds a different token (or no storyboard at all, since a full claim wipes `results`) and stops. This
 * is the same correlated-claim discipline as the workflow resume claim's `waiting_key`.
 *
 * Every method here is PURE — no query, no container, no side effect — precisely so the layers that must
 * agree on this shape (the executor that announces frames, the run manager that dispatches them, the frame
 * manager that settles them, the reaper that gives up on them) can share it without any of them depending
 * on another.
 */
final class StoryboardFrame
{
    /** Announced, queued, unspent. */
    public const PENDING = 'pending';

    /** Claimed by a frame job; the provider call is in flight. */
    public const RENDERING = 'rendering';

    /** The correlation token a frame job must match to act (transient — removed on settle). */
    public const TOKEN_KEY = 'frame_token';

    /** When the claim happened, for the stale-frame reaper (transient — removed on settle). */
    public const CLAIMED_AT_KEY = 'frame_claimed_at';

    /**
     * The shot entry for a frame that has been ANNOUNCED but not yet rendered. Carries the descriptive
     * fields verbatim (the FE shows the beat while its image is still coming) plus the correlation token.
     *
     * @param  array<string, mixed>  $meta  the shot's {index, visual, voiceover, seconds}
     * @return array<string, mixed>
     */
    public static function pending(array $meta, string $partKey, string $token): array
    {
        return $meta + [
            'image_status' => self::PENDING,
            'part_key' => $partKey,
            self::TOKEN_KEY => $token,
        ];
    }

    /**
     * The shot entry after a frame job WINS the claim: `rendering`, stamped with the claim time so the
     * reaper can tell a frame whose worker died from one that is merely slow.
     *
     * @param  array<string, mixed>  $shot
     * @return array<string, mixed>
     */
    public static function claimed(array $shot, string $claimedAt): array
    {
        $shot['image_status'] = self::RENDERING;
        $shot[self::CLAIMED_AT_KEY] = $claimedAt;

        return $shot;
    }

    /**
     * The TERMINAL `ok` shot entry: the produced image + the part_key the chat builds its serve URL from,
     * with both transient keys dropped and any stale `image_error`/`image_error_code` cleared. Mirrors the
     * refine loop's per-shot merge, so a frame settled by a job and a frame settled by a regenerate look the
     * same.
     *
     * @param  array<string, mixed>  $shot
     * @param  array<string, mixed>  $image
     * @return array<string, mixed>
     */
    public static function settledOk(array $shot, array $image, string $partKey): array
    {
        $shot['image_status'] = 'ok';
        $shot['image'] = $image;
        $shot['part_key'] = $partKey;
        unset($shot['image_error'], $shot['image_error_code']);

        return self::withoutTransientKeys($shot);
    }

    /**
     * The TERMINAL `failed` shot entry: a localized, non-secret reason and NO image. `part_key` is dropped
     * together with the transient keys so a failed frame is byte-identical to the inline renderer's failed
     * shot — nothing downstream should be able to build a serve URL for an image that does not exist.
     *
     * $errorCode is the OPTIONAL machine-readable reason ({@see ImageChainException::errorCode}) next to the
     * human message, mirroring the Disk edit's `error_code`. Additive: absent unless the failure actually
     * has one, and always CLEARED when it does not, so a re-render cannot leave a previous attempt's code
     * attached to a different failure.
     *
     * @param  array<string, mixed>  $shot
     * @return array<string, mixed>
     */
    public static function settledFailed(array $shot, string $error, ?string $errorCode = null): array
    {
        $shot['image_status'] = 'failed';
        $shot['image_error'] = $error;
        unset($shot['image'], $shot['part_key'], $shot['image_error_code']);

        if ($errorCode !== null) {
            $shot['image_error_code'] = $errorCode;
        }

        return self::withoutTransientKeys($shot);
    }

    /**
     * $results with every still-OUTSTANDING frame settled `failed` — what a WHOLE-RUN failure has to do
     * before it flips the session terminal.
     *
     * A frame may only be claimed while its session is `generating` ({@see StoryboardFrameManager::claim}),
     * so the moment a run is failed nothing will ever pick its `pending`/`rendering` shots up again. Left
     * alone they are not merely untidy: the FE reads `image_status` per shot and would show them spinning
     * for the lifetime of the row. Closing them here gives the same fail-soft shape a lost frame gets from
     * the reaper, which is what those shots effectively are.
     *
     * PURE and shape-preserving: a $results with no outstanding frame is returned IDENTICAL (===-comparable
     * by the caller), so a run without a storyboard writes exactly the column it always did.
     *
     * @param  array<string, mixed>  $results
     * @return array<string, mixed>
     */
    public static function failOutstandingIn(array $results, string $error): array
    {
        foreach ($results as $baseKey => $result) {
            if (!is_array($result) || ($result['kind'] ?? null) !== 'storyboard' || !is_array($result['shots'] ?? null)) {
                continue;
            }

            $shots = array_values($result['shots']);
            $closed = false;

            foreach ($shots as $i => $shot) {
                if (is_array($shot) && in_array($shot['image_status'] ?? null, [self::PENDING, self::RENDERING], true)) {
                    $shots[$i] = self::settledFailed($shot, $error);
                    $closed = true;
                }
            }

            if ($closed) {
                $results[$baseKey]['shots'] = $shots;
            }
        }

        return $results;
    }

    /**
     * Every frame in $results that is still ANNOUNCED (`pending`) — what the run manager dispatches a job
     * for after the results are persisted.
     *
     * @param  array<string, mixed>  $results
     * @return array<int, array{part_key: string, token: string}>
     */
    public static function pendingIn(array $results): array
    {
        return array_map(
            fn (array $frame): array => ['part_key' => $frame['part_key'], 'token' => $frame['token']],
            self::framesIn($results, [self::PENDING]),
        );
    }

    /**
     * Every frame in $results that has NOT settled (`pending` or `rendering`) — the predicate for "is this
     * run still waiting on anything?". An empty list is what makes a frame job the LAST one and therefore
     * the one that settles the session.
     *
     * @param  array<string, mixed>  $results
     * @return array<int, array{part_key: string, token: string, status: string, claimed_at: ?string}>
     */
    public static function outstandingIn(array $results): array
    {
        return self::framesIn($results, [self::PENDING, self::RENDERING]);
    }

    /**
     * Walk every storyboard-kind result's shots and collect the frames whose `image_status` is in
     * $statuses, addressed by their canonical dotted `<storyboardKey>.<i>` part key. Index-addressed via
     * array_values so the key always matches the store/refiner/executor addressing.
     *
     * @param  array<string, mixed>  $results
     * @param  array<int, string>  $statuses
     * @return array<int, array{part_key: string, token: string, status: string, claimed_at: ?string}>
     */
    private static function framesIn(array $results, array $statuses): array
    {
        $frames = [];

        foreach ($results as $baseKey => $result) {
            if (!is_array($result) || ($result['kind'] ?? null) !== 'storyboard') {
                continue;
            }

            foreach (array_values(is_array($result['shots'] ?? null) ? $result['shots'] : []) as $i => $shot) {
                if (!is_array($shot) || !in_array($shot['image_status'] ?? null, $statuses, true)) {
                    continue;
                }

                $claimedAt = $shot[self::CLAIMED_AT_KEY] ?? null;

                $frames[] = [
                    'part_key' => $baseKey . '.' . $i,
                    'token' => is_string($shot[self::TOKEN_KEY] ?? null) ? $shot[self::TOKEN_KEY] : '',
                    'status' => (string) $shot['image_status'],
                    'claimed_at' => is_string($claimedAt) ? $claimedAt : null,
                ];
            }
        }

        return $frames;
    }

    /**
     * Drop the in-flight bookkeeping so a settled shot carries exactly the keys it carried before this
     * stage existed.
     *
     * @param  array<string, mixed>  $shot
     * @return array<string, mixed>
     */
    private static function withoutTransientKeys(array $shot): array
    {
        unset($shot[self::TOKEN_KEY], $shot[self::CLAIMED_AT_KEY]);

        return $shot;
    }
}
