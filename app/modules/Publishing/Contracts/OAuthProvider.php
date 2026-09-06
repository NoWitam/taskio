<?php

namespace App\Modules\Publishing\Contracts;

use App\Modules\Publishing\DTOs\OAuthTokens;
use App\Modules\Publishing\DTOs\PlatformCredentials;
use App\Modules\Publishing\DTOs\RemoteAccount;
use App\Modules\Publishing\Enums\PublishingPlatform;

/**
 * HOW ONE PLATFORM FAMILY DOES OAUTH.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A SECOND CONTRACT BESIDE PlatformAdapter, NOT A SECTION OF IT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `PlatformAdapter` is about putting a POST out: two phases, a reconciliation, and a doctrine about
 * duplicate artifacts. This is about obtaining and keeping a CREDENTIAL. The two have different
 * lifetimes (a connection outlives thousands of publications), different failure meanings (a refused
 * publish may have created something; a refused token exchange never has), and different implementors —
 * Instagram and Facebook are two adapters and ONE OAuth provider, because they are one Meta app with one
 * consent screen and one token endpoint.
 *
 * Folding them together would have forced that last fact into a copy: the Meta dialect written twice,
 * with the second copy free to drift.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT VARIES BETWEEN FAMILIES, AND WHERE THIS CONTRACT PUTS IT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *   GOOGLE  One call to exchange. Issues a refresh token, but ONLY on the first consent and only when
 *           the authorize URL carried `access_type=offline` and `prompt=consent`. A refresh returns a
 *           new access token and no new refresh token.
 *   META    TWO calls to exchange: a code becomes a short-lived token, which is exchanged again for a
 *           long-lived one. No refresh token exists at any point; renewal is that second call made with
 *           the CURRENT long-lived access token.
 *
 * Those differences are absorbed by {@see exchangeCode()} and {@see refresh()} rather than surfacing as
 * branches in a service. Two consequences shaped the signatures:
 *
 *   `refresh()` takes {@see PlatformCredentials}, not a refresh-token string, because the two families
 *   renew with different halves of the pair. A string parameter would have made the caller decide which
 *   half to pass — which is the platform knowledge this interface exists to contain.
 *
 *   {@see canRefresh()} is a question, not an assumption. Google can renew only when a refresh token was
 *   actually issued (a consent screen that skipped `prompt=consent` leaves none); Meta can always renew
 *   because it renews with the access token. A caller that guessed would either give up on renewable
 *   connections or hammer a token endpoint for connections that have nothing to send it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE ONE RULE EVERY IMPLEMENTATION INHERITS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * NO RESPONSE BODY FROM A TOKEN ENDPOINT MAY LEAVE THE METHOD THAT READ IT — not into an exception, not
 * into a log, not into a `failure_context`. On success that body IS an access token. Failures are raised
 * as {@see \App\Modules\Publishing\Exceptions\OAuthExchangeFailed}, which has no parameter a body could
 * arrive through. `AbstractOAuthProvider` implements the discipline so an implementor inherits it rather
 * than remembering it.
 */
interface OAuthProvider
{
    /**
     * The destination this provider serves. Must equal the key it was registered under — the registry
     * refuses the pair when they disagree, because a mismatch would send a user to the wrong consent
     * screen and store the resulting token against the wrong destination.
     */
    public function platform(): PublishingPlatform;

    /**
     * Whether this installation is configured to talk to the platform at all.
     *
     * False when the client id or secret is absent, which is the SHIPPED state: the applications do not
     * exist yet. Asked before an authorize URL is built, so an unconfigured destination is refused here
     * with something a person can read — rather than sending them to a consent screen that answers with
     * the platform's own error page.
     */
    public function isConfigured(): bool;

    /**
     * The URL to send the browser to, with `$state` embedded.
     *
     * The redirect URI it names must match, byte for byte, one registered with the platform. That is not
     * our rule and there is no lenient mode: a trailing slash is a rejected handshake.
     */
    public function authorizeUrl(string $state): string;

    /**
     * Turn an authorization code into stored credentials. One call for Google, two for Meta.
     *
     * @throws \App\Modules\Publishing\Exceptions\OAuthExchangeFailed
     */
    public function exchangeCode(string $code): OAuthTokens;

    /**
     * Whether these credentials contain what this platform needs in order to renew.
     *
     * @see refresh() for what "renew" means per family
     */
    public function canRefresh(PlatformCredentials $credentials): bool;

    /**
     * Renew a credential that is approaching expiry.
     *
     * The returned tokens are MERGED over the stored ones, never written over them: a Google renewal
     * carries no refresh token, and treating its absence as "there is none" would discard the one field
     * that makes the connection renewable at all.
     *
     * @throws \App\Modules\Publishing\Exceptions\OAuthExchangeFailed
     */
    public function refresh(PlatformCredentials $credentials): OAuthTokens;

    /**
     * WHOSE account this token speaks for, asked of the platform.
     *
     * Not optional and not inferable. `platform_connections.external_account_id` is what makes a
     * workspace's connections unique per account and what a publication's artifact uniqueness keys on;
     * a connection stored without it would let the same channel be authorized twice with two live
     * tokens.
     *
     * @throws \App\Modules\Publishing\Exceptions\OAuthExchangeFailed
     */
    public function fetchAccount(OAuthTokens $tokens): RemoteAccount;
}
