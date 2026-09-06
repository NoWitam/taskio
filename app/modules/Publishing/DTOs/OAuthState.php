<?php

namespace App\Modules\Publishing\DTOs;

use App\Modules\Publishing\Enums\PublishingPlatform;
use Carbon\CarbonImmutable;

/**
 * WHO ASKED, FOR WHICH WORKSPACE, AND FOR WHICH PLATFORM — the contents of an OAuth `state`, verified.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THIS OBJECT EXISTS BECAUSE THE CALLBACK HAS NOTHING ELSE TO GO ON
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The SPA authenticates with a bearer token out of `localStorage` and names its workspace in an
 * `X-Workspace-Id` header (see `resources/js/next/app/lib/token.ts`). A redirect arriving from Google or
 * Meta is a plain browser navigation: no Authorization header, no workspace header, no XHR — the
 * frontend is not even running yet. Every fact the callback needs about the request that started the
 * handshake has to come back with the redirect, and the only field the OAuth spec gives us for that is
 * `state`.
 *
 * So `state` is not a CSRF nonce that happens to carry data. It is the identity of the request, and it
 * is trusted for exactly as much as the signature over it proves.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IS IN IT, AND WHAT DELIBERATELY IS NOT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *   jti          A random identifier, and the SINGLE-USE key. The signature alone cannot make a state
 *                one-shot: a signed string stays valid until it expires, and a back-button press, a
 *                prefetching browser or a shoulder-surfed URL would replay it. The ledger entry keyed
 *                by this is what "already used" means.
 *   browserHash  The SHA-256 of the secret in the handshake cookie. The digest travels; the secret does
 *                not. That asymmetry is the whole of the browser binding — see below.
 *   userId       Who is being attributed as the connection's creator. Read from the SIGNED payload and
 *                never from `$request->user()`, which on this route is nobody at best — and was, until
 *                B2's review removed the line, whoever a hardcoded `Auth::login()` in `LogMiddleware`
 *                had logged in ahead of every guard.
 *   workspaceId  Which tenant the connection belongs to. This is the one that must not be forgeable:
 *                the callback uses it to CHOOSE A DATABASE.
 *   platform     Cross-checked against the URL segment, so a state minted for one destination cannot
 *                be redeemed at another's callback.
 *   expiresAt    Carried IN the signed payload rather than left to the storage layer's TTL, so expiry
 *                survives a cache that was flushed, misconfigured, or is a different store than the one
 *                that issued it.
 *
 * NOT in it: anything secret. A `state` travels in a URL, through the platform's servers, into their
 * access logs and into the browser's history. Everything above is an identifier, a timestamp or a
 * one-way digest, and the signature is what makes them mean something rather than what hides them.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `browserSecret` IS THE ONE FIELD THAT NEVER TRAVELS, AND IT EXISTS ON ONLY HALF THE OBJECTS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A freshly ISSUED state carries it, because the cookie has to be minted from something and this object
 * is what `issue()` hands back. A state DECODED from the wire never can — the wire carries the digest
 * and nothing else, which is precisely the property that makes the binding worth having. So the field is
 * nullable, and null means "this state came back from a browser" rather than "no binding".
 *
 * Nothing reads it but {@see \App\Modules\Publishing\Services\OAuthStateService::handshakeCookie()},
 * which refuses a state that does not have one rather than minting a cookie nobody can redeem.
 */
final readonly class OAuthState
{
    public function __construct(
        public string $jti,
        public string $userId,
        public string $workspaceId,
        public PublishingPlatform $platform,
        public CarbonImmutable $issuedAt,
        public CarbonImmutable $expiresAt,
        /** SHA-256 of the browser secret, in hex. Signed into the payload; safe in a URL. */
        public string $browserHash,
        /** The secret itself — PRESENT ONLY ON A FRESHLY MINTED STATE. See the class docblock. */
        public ?string $browserSecret = null,
    ) {}

    public function hasExpired(?CarbonImmutable $now = null): bool
    {
        return ($now ?? CarbonImmutable::now())->greaterThan($this->expiresAt);
    }

    /**
     * How many seconds a client may still expect this handshake to be redeemable.
     *
     * Cast explicitly: Carbon 3 answers a FLOAT, and returning one from an `int` signature is an
     * implicit lossy conversion — a deprecation notice in PHP 8.1+, which on a path that runs on every
     * authorize call would be a steady trickle into the log for nothing.
     */
    public function secondsRemaining(?CarbonImmutable $now = null): int
    {
        return (int) max(0, ($now ?? CarbonImmutable::now())->diffInSeconds($this->expiresAt, false));
    }
}
