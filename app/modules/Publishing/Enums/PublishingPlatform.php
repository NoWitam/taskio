<?php

namespace App\Modules\Publishing\Enums;

/**
 * WHERE a publication goes — the module's CLOSED vocabulary, and the key an adapter registers under.
 *
 * One case, one {@see \App\Modules\Publishing\Contracts\PlatformAdapter}, resolved through
 * {@see \App\Modules\Publishing\Services\PlatformAdapterRegistry}. The enum is what makes the column a
 * vocabulary rather than a free string: a row can only name a destination the product has a name for,
 * and an adapter can only register under a destination that exists.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `dry_run` IS A PLATFORM, NOT A TEST DOUBLE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * It sits in this list beside the real ones on purpose, and it is user-selectable. Two things depend on
 * that being true rather than convenient:
 *
 *   THE REVIEW. Meta's and Google's app reviews want to see the product working before either will
 *   grant the permissions that let it publish. `dry_run` is what the demo runs on — a complete pass
 *   through arming, claiming, both phases, reconciliation and the calendar, with nothing leaving the
 *   building.
 *
 *   THE TESTS. Every B1–B3 test drives this adapter. A double registered only under test conditions
 *   would exercise a code path that does not ship; this one IS the shipped path, so a defect in the
 *   machinery around it fails in the suite rather than on the first real token.
 *
 * What it does not do is publish, and {@see publishesPublicly()} is how every caller asks — never by
 * comparing against the case, which is the comparison that gets missed when a second such destination
 * (a sandbox account, a staging page) is added.
 */
enum PublishingPlatform: string
{
    case YOUTUBE = 'youtube';
    case INSTAGRAM = 'instagram';
    case FACEBOOK = 'facebook';

    /** A complete adapter that puts nothing in the world. See the class docblock. */
    case DRY_RUN = 'dry_run';

    /** The platform's name in the READER's language — the prose a calendar badge carries. */
    public function label(): string
    {
        return __('publishing.platforms.' . $this->value);
    }

    /**
     * Whether publishing here produces an artifact that other people can see.
     *
     * The one question the rest of the module asks about a platform. A `false` here is what lets a UI
     * mark a destination as a rehearsal, and what stops a "you are about to publish publicly" guard
     * from firing on a run that publishes nothing.
     */
    public function publishesPublicly(): bool
    {
        return $this !== self::DRY_RUN;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
