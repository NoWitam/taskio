<?php

namespace App\Modules\Knowledge\Enums;

/**
 * Where one drafting session stands.
 *
 *   idle        created, or between runs; nothing in flight.
 *   generating  a worker holds the CLAIM. Taken by a guarded UPDATE, so two clicks cannot start two
 *               runs against one session — and so the reaper can tell a live run from a dead one.
 *   ready       drafts are on the table, waiting for a human.
 *   failed      the last run produced nothing usable; `failure_reason` says what a person can do
 *               about it.
 *
 * `failed` is deliberately a RESTING state, not a dead end: the session keeps its source text and its
 * instruction history, so retrying is the same action as refining. A model that returned prose instead
 * of JSON once will usually not do it twice, and making the user re-paste their material to find that
 * out would be punishing them for the model's mistake.
 */
enum KnowledgeDraftSessionStatus: string
{
    /**
     * THE COLUMN DEFAULT, NOT A STATE ANY CLIENT WILL SEE.
     *
     * `start()` writes GENERATING and takes the claim in the same insert, so a session is never
     * persisted as idle and no response has ever carried it. The case stays because it is the schema
     * default in both migration trees (and the factory's starting point), and because `isClaimable()`
     * has to have an answer for a row read straight from that default.
     *
     * Do not build a UI branch on it — an "idle" tab would be a screen nobody can reach.
     */
    case IDLE = 'idle';
    case GENERATING = 'generating';
    case READY = 'ready';
    case FAILED = 'failed';

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether a new run may be started from here. Everything except a claim already held — see the
     * class docblock for why `failed` is included.
     */
    public function isClaimable(): bool
    {
        return $this !== self::GENERATING;
    }
}
