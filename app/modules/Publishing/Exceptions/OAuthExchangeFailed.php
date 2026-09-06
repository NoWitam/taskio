<?php

namespace App\Modules\Publishing\Exceptions;

use RuntimeException;

/**
 * A TOKEN ENDPOINT DID NOT GIVE US A USABLE CREDENTIAL.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE ENTIRE POINT OF THIS CLASS IS WHAT IT REFUSES TO CARRY
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * A token endpoint's response body is the single most dangerous string this application ever holds. On
 * success it IS the access token. And the reflex when a request fails — attach the response so somebody
 * can debug it — is exactly how that string reaches an exception message, then Laravel's exception log,
 * then a log aggregator, then a support ticket.
 *
 * It is not a hypothetical reflex; it is the framework's default. `Illuminate\Http\Client\Response::throw()`
 * raises a `RequestException` whose message embeds the first 120 characters of the body, and 120
 * characters of `{"access_token":"ya29.…"}` is a working credential. So NOTHING in this module calls
 * `->throw()` on a token endpoint, and every failure is funnelled through this class instead.
 *
 * What it carries:
 *   $failureCode  our own stable string — `token_exchange_failed`, `token_refresh_failed`,
 *                 `token_response_unusable`, `account_lookup_failed`.
 *   $status       the HTTP status. A number cannot be a secret, and it is most of what a diagnosis
 *                 needs (401 = the app's own client secret is wrong; 400 = the grant was; 5xx = wait).
 *   $platformError the platform's MACHINE error code (`invalid_grant`, `invalid_client`), read from a
 *                 single named field and nothing else. That field is a documented enumeration on both
 *                 Google and Meta, it is what actually distinguishes "the user revoked access" from
 *                 "our clock is wrong", and it is not a place a credential appears.
 *
 * What it must never carry: the body, any header, the request that produced it, the code being
 * exchanged, or any token. There is deliberately no constructor parameter it could arrive through.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * IT IS DISTINCT FROM PlatformRefused ON PURPOSE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `PlatformRefused` is about a PUBLICATION and asserts "nothing was created", which is what makes a
 * retry safe. This one is about a CREDENTIAL, creates nothing in the world, and is always safe to
 * retry. Sharing a type would have let the publisher's doubt-handling — the machinery that exists to
 * avoid duplicate posts — receive an exception about an OAuth handshake and classify it as if a post
 * might have been made.
 */
class OAuthExchangeFailed extends RuntimeException
{
    /** An authorization code could not be turned into tokens. */
    public const EXCHANGE = 'token_exchange_failed';

    /** An existing credential could not be renewed. Sends the connection to `needs_reauth`. */
    public const REFRESH = 'token_refresh_failed';

    /** The call succeeded and the body was not something we can store — no access token in it. */
    public const UNUSABLE_RESPONSE = 'token_response_unusable';

    /** We hold a token but the platform would not say whose account it is. */
    public const ACCOUNT_LOOKUP = 'account_lookup_failed';

    /** The platform issues renewable credentials and this connection has none to renew with. */
    public const REFRESH_UNSUPPORTED = 'refresh_unsupported';

    public function __construct(
        public readonly string $failureCode,
        public readonly ?int $status = null,
        /** The platform's own machine error code, from one named field. Never its prose, never a body. */
        public readonly ?string $platformError = null,
    ) {
        parent::__construct(
            'The platform token endpoint failed: ' . $failureCode
            . ($status !== null ? ' (HTTP ' . $status . ')' : '')
            . ($platformError !== null ? ' [' . $platformError . ']' : ''),
        );
    }

    /**
     * The safe half of a failed response, for a log context or a `failure_context`.
     *
     * A named method rather than a habit, so that "what may be recorded about a token failure" has one
     * answer and adding to it is a deliberate edit to this file.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return array_filter([
            'failure_code' => $this->failureCode,
            'status' => $this->status,
            'platform_error' => $this->platformError,
        ], static fn ($value): bool => $value !== null);
    }
}
