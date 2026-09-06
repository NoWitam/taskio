<?php

namespace App\Modules\Publishing\DTOs;

use LogicException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * WHAT BEGINNING A HANDSHAKE PRODUCED: somewhere to send the browser, how long it is good for, and the
 * binding that says WHICH browser.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THE COOKIE TRAVELS IN A DTO INSTEAD OF BEING QUEUED
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `Cookie::queue()` is the idiomatic way to set one from a service, and it does nothing here: queued
 * cookies are attached by `AddQueuedCookiesToResponse`, which is in the `web` middleware group, and the
 * authorize endpoint is in `api`. A queued cookie would have been silently dropped — and the failure
 * would not have surfaced as a missing cookie but as `oauth_browser_mismatch` on every real connect,
 * two files and one redirect away from its cause.
 *
 * So the cookie is built where the nonce is (in `OAuthStateService`, next to the ledger entry it is the
 * twin of), carried here as data, and attached to the response by the controller — the one layer that
 * has a response to attach it to.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE URL IS NOT A SECRET. THE COOKIE IS.
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The two used to carry the same value — the handshake's nonce id, in the URL in plain sight — and the
 * argument for that was that the pair proves not knowledge but SAMENESS OF BROWSER. It was wrong, and the
 * hole it left is written up on {@see \App\Modules\Publishing\Services\OAuthStateService}: a client
 * holding the URL could read the nonce out of it and SET the cookie itself, because `HttpOnly` governs
 * reading a cookie and never setting one in a client of your own.
 *
 * So the cookie now carries 32 CSPRNG bytes that appear nowhere else, and the `state` carries only their
 * SHA-256. Sameness is still what is being proved, but it is proved by holding something rather than by
 * repeating something public.
 *
 * WHICH IS WHY THIS OBJECT REFUSES SERIALIZATION, like {@see OAuthTokens} and {@see PlatformCredentials}
 * beside it. It did not need to when the cookie was a public id; it does now. The window is short — ten
 * minutes — and the value is not a platform credential, but it is the one thing standing between a leaked
 * authorize URL and a channel somebody else controls appearing in this workspace's destination picker,
 * and that does not belong in a queue payload, a cache entry or a session.
 */
final readonly class StartedHandshake
{
    public function __construct(
        /** Where to send the browser. Carries the signed, single-use `state`. */
        public string $authorizeUrl,
        /** Seconds the invitation stays redeemable, from the state's own signed expiry. */
        public int $expiresIn,
        /** The browser binding — a live secret — for the controller to attach to the response. */
        public Cookie $handshakeCookie,
    ) {}

    /**
     * Refuses serialization outright. See the class docblock.
     *
     * @return array<int, string>
     */
    public function __sleep(): array
    {
        throw new LogicException(
            'A started handshake must not be serialized. It carries the browser-binding secret, which '
            . 'belongs in one response cookie and nowhere else — not a queue payload, cache entry or session.'
        );
    }
}
