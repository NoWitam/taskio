<?php

namespace App\Modules\Publishing\Enums;

/**
 * WHETHER AN AUTHORIZED ACCOUNT IS STILL USABLE.
 *
 *   active ──refresh fails / token unreadable / platform revokes──▶ needs_reauth
 *     ▲                                                                  │
 *     └────────────────────── the person re-connects ────────────────────┘
 *     │
 *     └──the person disconnects──▶ revoked   (and the row is soft-deleted with it)
 *
 * THREE CASES, AND THE LIST IS SHORT ON PURPOSE. Every one of them is reached by a transition that
 * exists today: a failed refresh, a re-connect, a disconnect. There is no `expiring`, no `pending`, no
 * `degraded` — a status nothing writes is a status every reader has to handle and no test ever covers,
 * and this enum will be read by the queue that decides whether a post goes out.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `needs_reauth` IS A HOLD, AND IT IS WHY THE PUBLICATION STATE MACHINE HAS `blocked`
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The two states are one mechanism seen from two tables. A connection entering `needs_reauth` puts
 * every publication waiting on it into `blocked`; the connection returning to `active` puts them back.
 * Without that, a token revoked at 08:00 becomes twelve failures at 09:00 — twelve alerts, twelve retry
 * buttons, twelve rate-limited calls, one cause — and each of those failures is a row a person then has
 * to decide about individually.
 *
 * The coordination lives in {@see \App\Modules\Publishing\Managers\PlatformConnectionManager}, which is
 * the only writer of this column.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `revoked` AND THE SOFT DELETE SAY DIFFERENT THINGS, WHICH IS WHY BOTH EXIST
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `deleted_at` says the connection is GONE from every list and picker. `revoked` says WHY it is gone —
 * a person disconnected it, as opposed to it having broken. The distinction survives into the failure
 * code stamped on the publications that were holding on it, and those two codes want different advice:
 * "reconnect the account" versus "this account was disconnected; pick another destination".
 */
enum PlatformConnectionStatus: string
{
    /** Authorized and believed to work. The only status a publication may go out on. */
    case ACTIVE = 'active';

    /** The credential stopped working and only a person at a consent screen can fix it. */
    case NEEDS_REAUTH = 'needs_reauth';

    /** Disconnected deliberately. The row survives so the history that points at it still resolves. */
    case REVOKED = 'revoked';

    /** The status in the READER's language. */
    public function label(): string
    {
        return __('publishing.connection_status.' . $this->value);
    }

    /**
     * The colour MEANING a badge carries — the app-wide `tone()` vocabulary, so a connection chip and a
     * publication chip cannot drift apart.
     *
     * `needs_reauth` is `danger` rather than `warning`, unlike the `blocked` publications it causes.
     * That asymmetry is deliberate: the publications are FINE and merely waiting, while this row is the
     * one thing anybody can actually act on. Colouring the cause the same as its symptoms would bury it
     * among them.
     */
    public function tone(): string
    {
        return match ($this) {
            self::ACTIVE => 'success',
            self::NEEDS_REAUTH => 'danger',
            self::REVOKED => 'muted',
        };
    }

    /**
     * Whether a publication may be sent out on a connection in this status.
     *
     * The ONE question the publishing path asks, written here so it is asked the same way everywhere —
     * never by comparing against `ACTIVE`, which is the comparison that gets missed when a fourth
     * status is added.
     */
    public function canPublish(): bool
    {
        return $this === self::ACTIVE;
    }

    /** Whether somebody has to go to a consent screen before this works again. */
    public function needsAttention(): bool
    {
        return $this === self::NEEDS_REAUTH;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
