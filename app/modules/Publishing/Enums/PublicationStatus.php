<?php

namespace App\Modules\Publishing\Enums;

/**
 * WHERE ONE PUBLICATION IS IN ITS LIFE — the vocabulary the whole module is arranged around.
 *
 *                    ┌──────────────────────────────────────────────┐
 *                    ▼                                              │
 *   draft ──arm──▶ scheduled ──claim──▶ publishing ──▶ published (terminal)
 *     ▲               │  ▲                   │
 *     │               │  │                   ├──▶ failed ──retry──▶ publishing
 *     │          block│  │re-arm             │       │
 *     │               ▼  │                   └──▶ needs_reconcile
 *     └───────────── blocked                          │
 *                                                     └── reconcile ──▶ published | failed
 *
 * The transitions themselves are NOT here. They live in {@see \App\Modules\Publishing\Managers\PublicationManager},
 * which is their only owner — an enum that also knew which move was legal would be a second place for the
 * answer, and the second place is the one that stops being maintained.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE TWO STATES THAT EXIST BECAUSE PUBLISHING IS IRREVERSIBLE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Most of these read like any job queue's. Two do not, and they are the whole reason this is a state
 * machine rather than a boolean:
 *
 *   needs_reconcile  We started publishing and we do NOT KNOW whether a post exists in the world. The
 *                    worker died between the two phases, the platform timed out after accepting, the
 *                    connection dropped mid-call. This is not "failed" — failed means we know nothing
 *                    was created — and treating it as failed is how a retry produces a SECOND public
 *                    post that nobody can un-publish. There is no automatic exit: the module asks the
 *                    platform (`PlatformAdapter::findExisting`) and only then decides.
 *
 *   blocked          The connection this publication needs is not usable — revoked token, a platform
 *                    permission withdrawn, an account disconnected. It exists so a broken connection
 *                    HOLDS its queue instead of turning every scheduled item into a failure at 9:00,
 *                    burning the platform's rate limit and filling somebody's morning with twelve
 *                    identical alerts about one cause.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `draft` HAS NO TONE, AND THAT IS THE CALENDAR CONTRACT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Every other case answers {@see tone()} with one of the colour meanings the grid already speaks, so
 * the publication source colours a square by STATE without inventing a palette. `draft` answers null,
 * because a draft has no `scheduled_at` — no instant, so no place on an axis of time. It is filtered
 * out of the calendar query by {@see projected()} rather than colour-defaulted onto a day it does not
 * have; a null tone here is the second, structural statement of the same fact, so a source that
 * somehow reached a draft could not paint it either.
 */
enum PublicationStatus: string
{
    /** Being written. No time, no place on the calendar, nothing armed. */
    case DRAFT = 'draft';

    /** Armed for an instant. The due-sweep will claim it when that instant arrives. */
    case SCHEDULED = 'scheduled';

    /** Claimed and in flight. Exactly one worker may hold a publication here. */
    case PUBLISHING = 'publishing';

    /** It is out in the world. TERMINAL — see {@see isTerminal()} for why nothing leaves. */
    case PUBLISHED = 'published';

    /** The platform refused, DEFINITELY, and nothing was created. A retry is safe. */
    case FAILED = 'failed';

    /** We do not know whether a post exists. A retry is NOT safe. See the class docblock. */
    case NEEDS_RECONCILE = 'needs_reconcile';

    /** The connection is unusable, so the queue is held rather than run into a wall. */
    case BLOCKED = 'blocked';

    /** The status in the READER's language. Prose is the server's to own — see {@see tone()}. */
    public function label(): string
    {
        return __('publishing.status.' . $this->value);
    }

    /**
     * The colour MEANING a calendar square carries for this status, or null for a status that never
     * reaches a square.
     *
     * The vocabulary is the app-wide `tone()` one (`CalendarColor::fromTone`), so the grid, a badge and
     * a list chip cannot drift apart. Two choices in it are worth stating:
     *
     *   publishing → warning   In flight, not yet out. Not `info` (which would read as "scheduled, all
     *                          is well") and not `success`: a publication sitting in `publishing` for
     *                          an hour is the single most interesting row on the screen.
     *   blocked → warning      A hold, not a failure. Nothing is broken about the publication itself,
     *                          and colouring it `danger` beside genuinely failed rows would bury the
     *                          ones that need a decision under the ones that need a fixed connection.
     *
     * `needs_reconcile` shares `danger` with `failed` deliberately: to a reader they are the same
     * urgency ("this needs me"), and the difference between them is what the ACTIONS differ by, which
     * is a job for the badge and the detail screen, not for a colour.
     */
    public function tone(): ?string
    {
        return match ($this) {
            self::DRAFT => null,
            self::SCHEDULED => 'info',
            self::PUBLISHING => 'warning',
            self::PUBLISHED => 'success',
            self::FAILED, self::NEEDS_RECONCILE => 'danger',
            self::BLOCKED => 'warning',
        };
    }

    /**
     * Whether a square for this status belongs on the calendar at all.
     *
     * Equivalent to "has a tone", and deliberately written as its own `match` rather than as
     * `tone() !== null`: the two questions happen to have the same answer today, and a future status
     * that appears on the grid without a colour (or the reverse) must be a decision somebody makes here
     * rather than a coincidence that quietly stops holding.
     */
    public function isProjectedOnCalendar(): bool
    {
        return match ($this) {
            self::DRAFT => false,
            default => true,
        };
    }

    /**
     * Every status a calendar query may select, as raw column values.
     *
     * @return array<int, string>
     */
    public static function projected(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isProjectedOnCalendar()),
        ));
    }

    /**
     * Nothing leaves this status.
     *
     * `published` is terminal in the strong sense, not the convenient one: the row is the RECORD of a
     * public artifact that exists outside this application and cannot be recalled by anything written
     * here. Letting it move back to `draft` for an edit would make the record disagree with the world
     * while looking authoritative.
     */
    public function isTerminal(): bool
    {
        return $this === self::PUBLISHED;
    }

    /**
     * Whether the CONTENT may still be rewritten.
     *
     * The three refusals each have their own reason and none is about permission:
     *   publishing        a worker is holding this row and reading its content right now;
     *   published         see {@see isTerminal()};
     *   needs_reconcile   the row may correspond to a live post. Editing it would silently make our
     *                     record of that post wrong, and the reconciliation that follows would compare
     *                     the platform against something nobody ever sent.
     *
     * Read by {@see \App\Modules\Publishing\Policies\PublicationPolicy}, which composes it with WHO is
     * asking. One definition, two consumers — the policy and the `can_be_edited` flag on the resource
     * cannot disagree.
     */
    public function isEditable(): bool
    {
        return match ($this) {
            self::PUBLISHING, self::PUBLISHED, self::NEEDS_RECONCILE => false,
            default => true,
        };
    }

    /**
     * Whether the row may be moved to the trash.
     *
     * `published` IS deletable, which is worth stating because it looks inconsistent beside
     * {@see isEditable()}: deleting hides our record and changes nothing in the world, while editing
     * would leave a record that actively lies. The two states refused here are the ones where the row
     * is the only handle onto something in flight or unknown — delete either and the artifact, if it
     * exists, becomes permanently unattributable.
     */
    public function isDeletable(): bool
    {
        return match ($this) {
            self::PUBLISHING, self::NEEDS_RECONCILE => false,
            default => true,
        };
    }

    /**
     * Whether ASKING THE PLATFORM about this row is a meaningful thing to do.
     *
     * Exactly one status, and the narrowness is the point. A reconciliation concludes in `published` or
     * `failed`, and both of those edges start at `needs_reconcile` — so from anywhere else the probe
     * would either be answering a question nobody has (a `scheduled` row was never sent) or arriving at
     * a conclusion the machine refuses to write anyway.
     *
     * It lives here rather than in the policy for the same reason {@see isEditable()} does: the write
     * path and the `can_be_reconciled` flag on the resource have to be the SAME computation, or the UI
     * offers a button whose request 403s.
     */
    public function isReconcilable(): bool
    {
        return $this === self::NEEDS_RECONCILE;
    }

    /**
     * Whether a human has to do something about this row.
     *
     * Computed here rather than by a client listing statuses, for the same reason a calendar badge is
     * prose: the moment the frontend owns the list, a status added on the server is silently absent from
     * the badge that was supposed to surface it.
     */
    public function needsAttention(): bool
    {
        return match ($this) {
            self::FAILED, self::NEEDS_RECONCILE, self::BLOCKED => true,
            default => false,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
