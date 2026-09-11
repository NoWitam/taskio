<?php

namespace App\Modules\Publishing\Support;

use App\Modules\Publishing\Exceptions\OAuthExchangeFailed;
use App\Modules\Publishing\Exceptions\OAuthStateRejected;

/**
 * EVERY REASON THE OAUTH CALLBACK MAY PUT IN A REDIRECT — one list, in one file.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS, GIVEN THAT EACH CODE WAS ALREADY A PERFECTLY GOOD STRING
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * `PlatformOAuthCallbackController` could report fourteen different reasons and there was no place that
 * knew all fourteen. Six lived on `OAuthStateRejected`, three on `OAuthExchangeFailed`, and five were
 * bare literals in the controller — and the language files knew FOUR of them. The other ten would have
 * rendered as their own raw key in the middle of a Polish interface, which is the exact defect
 * `PublishingConnectionVocabularyTest` was written for on the neighbouring table, arrived at from the
 * other direction: not two spellings of one code, but a code nobody had ever written a sentence for.
 *
 * With the list here, "is every reason translated" becomes a question that can be ASKED, and it is —
 * exact parity in both directions, in both languages, in that same test.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE CODES DEFINED FROM THE EXCEPTIONS ARE THE SAME LITERAL, NOT A COPY
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * `self::STATE_EXPIRED = OAuthStateRejected::EXPIRED` — one string, two names. The alternative, spelling
 * `'oauth_state_expired'` again here, is precisely how `token_refresh_failed` and `refresh_failed` came
 * to be two spellings of one fact in B2, with the tests agreeing with the wrong one. Same technique
 * `PlatformConnectionManager::FAILURE_REFRESH_UNSUPPORTED` uses, for the same reason.
 *
 * ONLY THE THREE EXCHANGE FAILURES A CALLBACK CAN ACTUALLY REACH are listed. `token_refresh_failed` and
 * `refresh_unsupported` belong to the RENEWAL sweep, describe the state of a stored account rather than
 * a handshake, and already have their own sentences under `publishing.connection_failures`. A code with
 * two sentences in two catalogs is a code whose two sentences drift.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT THIS LIST DOES **NOT** COVER, STATED SO NOBODY DISCOVERS IT
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * When a platform declines BEFORE issuing a code it sends its own `error` parameter, and that value is
 * passed through — it is a documented enumeration on both Google and Meta, and reducing every one of
 * them to a single word would throw away the only diagnosis available. `access_denied` is the member
 * that matters (the user pressed "cancel"), so it is in the list and it is translated; the rest travel
 * untranslated and the client falls back to its generic "could not connect" sentence. See
 * {@see forPlatformError()} for the one rule that applies to all of them.
 */
final class OAuthCallbackReason
{
    // ── the controller's own refusals ───────────────────────────────────────────────────────────

    /** The destination in the URL is not one this application knows. Unreachable behind the route constraint. */
    public const UNKNOWN_PLATFORM = 'unknown_platform';

    /** The platform sent us back with no `code` and no `error`. Nothing to exchange. */
    public const MISSING_CODE = 'missing_code';

    /**
     * The workspace named by the state is gone, not READY, or the user is no longer a member.
     *
     * ONE code for three refusals ON PURPOSE — see the controller. Telling a browser which of them
     * happened would answer questions about workspaces the holder of this state may no longer be
     * entitled to ask.
     */
    public const WORKSPACE_UNAVAILABLE = 'workspace_unavailable';

    /** Anything else at all. The catch-all, deliberately saying nothing about what threw. */
    public const CONNECTION_FAILED = 'connection_failed';

    /** The user declined at the consent screen. The platform's own code, and the one worth translating. */
    public const ACCESS_DENIED = 'access_denied';

    // ── the state's refusals: one literal each, owned by OAuthStateRejected ──────────────────────

    public const STATE_MALFORMED = OAuthStateRejected::MALFORMED;

    public const STATE_BAD_SIGNATURE = OAuthStateRejected::BAD_SIGNATURE;

    public const STATE_EXPIRED = OAuthStateRejected::EXPIRED;

    public const STATE_ALREADY_USED = OAuthStateRejected::ALREADY_USED;

    public const STATE_PLATFORM_MISMATCH = OAuthStateRejected::PLATFORM_MISMATCH;

    public const BROWSER_MISMATCH = OAuthStateRejected::BROWSER_MISMATCH;

    // ── the token endpoint's refusals, callback-reachable only ───────────────────────────────────

    public const TOKEN_EXCHANGE_FAILED = OAuthExchangeFailed::EXCHANGE;

    public const TOKEN_RESPONSE_UNUSABLE = OAuthExchangeFailed::UNUSABLE_RESPONSE;

    public const ACCOUNT_LOOKUP_FAILED = OAuthExchangeFailed::ACCOUNT_LOOKUP;

    /**
     * THE WHOLE VOCABULARY. Keys of `publishing.oauth_failures` must equal this, exactly, in both
     * languages — asserted by `PublishingConnectionVocabularyTest`.
     *
     * @var array<int, string>
     */
    public const ALL = [
        self::UNKNOWN_PLATFORM,
        self::MISSING_CODE,
        self::WORKSPACE_UNAVAILABLE,
        self::CONNECTION_FAILED,
        self::ACCESS_DENIED,
        self::STATE_MALFORMED,
        self::STATE_BAD_SIGNATURE,
        self::STATE_EXPIRED,
        self::STATE_ALREADY_USED,
        self::STATE_PLATFORM_MISMATCH,
        self::BROWSER_MISMATCH,
        self::TOKEN_EXCHANGE_FAILED,
        self::TOKEN_RESPONSE_UNUSABLE,
        self::ACCOUNT_LOOKUP_FAILED,
    ];

    /**
     * A token-endpoint failure, as a reason this catalog can answer for.
     *
     * Today every code a callback can reach is already in {@see ALL}, so this returns its argument
     * unchanged and changes no behaviour. It exists for the day somebody adds a fourth: without it the
     * new code would travel to the browser, find no sentence, and render as a raw key — the failure
     * being fixed here, re-created by the natural next edit. With it, the worst case is the generic
     * sentence, which is a poorer answer rather than a broken screen.
     */
    public static function forExchange(string $failureCode): string
    {
        return in_array($failureCode, self::ALL, true) ? $failureCode : self::CONNECTION_FAILED;
    }

    /**
     * A platform's OWN error code, passed through.
     *
     * BOUNDED IN LENGTH, and that is the whole rule: the value comes from outside and is going into a
     * URL. It is deliberately not restricted to {@see ALL} — see the class docblock for why the
     * platform's enumeration is worth carrying even when we have no sentence for it.
     */
    public static function forPlatformError(string $error): string
    {
        return substr($error, 0, 64);
    }
}
