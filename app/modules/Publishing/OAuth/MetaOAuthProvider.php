<?php

namespace App\Modules\Publishing\OAuth;

use App\Modules\Publishing\DTOs\OAuthTokens;
use App\Modules\Publishing\DTOs\PlatformCredentials;
use App\Modules\Publishing\DTOs\RemoteAccount;
use App\Modules\Publishing\Exceptions\OAuthExchangeFailed;

/**
 * META'S DIALECT — no refresh token, ever, and an exchange that takes two calls instead of one.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE TWO-STEP EXCHANGE IS NOT AN OPTIMIZATION. THE FIRST TOKEN IS USELESS.
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * A code exchanged at Meta's token endpoint yields a SHORT-LIVED token — on the order of an hour or two.
 * The long-lived one (roughly sixty days) is obtained by exchanging that token again, with
 * `grant_type=fb_exchange_token`.
 *
 * Both calls therefore live inside {@see exchangeCode()}, because the first result is not something this
 * module may store. A provider that returned after step one would produce a connection that works
 * beautifully during the manual test somebody does right after connecting it and is dead before the
 * first scheduled post — the worst available failure shape, since every check performed at the moment
 * of connecting passes.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * "REFRESH" HERE CONSUMES THE ACCESS TOKEN, NOT A REFRESH TOKEN
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * Meta issues no refresh token at any point. Renewal is the SAME `fb_exchange_token` call made with the
 * CURRENT long-lived token, which yields a fresh sixty-day one. So:
 *
 *   {@see canRefresh()} is always true — there is nothing extra a connection needs in order to renew.
 *   {@see refresh()} reads `accessToken` where Google's reads `refreshToken`.
 *
 * That single fact is why {@see \App\Modules\Publishing\Contracts\OAuthProvider::refresh()} takes the
 * whole {@see PlatformCredentials} pair. A signature admitting only a refresh-token string would have
 * left this class unable to express its own renewal, and the caller would have grown the `if` that this
 * arrangement exists to avoid.
 *
 * THE CONSEQUENCE FOR THE SWEEP, which is not obvious: a Meta connection renews only while it is still
 * ALIVE. Once the sixty days lapse there is nothing left to exchange and only a human at a consent
 * screen can repair it. The refresh lead (`publishing.tokens.refresh_lead`, a day) exists mostly for
 * this platform — a token that lapses between two passes cannot be recovered by a later pass.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * ONE PROVIDER, TWO DESTINATIONS
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * Instagram and Facebook are two `PublishingPlatform` cases and two publishing adapters, but one Meta
 * application with one consent screen and one token endpoint. They differ only in the scopes they ask
 * for, which is configuration. The provider is constructed per platform (`$this->platform`) and reads
 * its own section, so the two register separately and share this dialect rather than a copy of it.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT ONLY A REAL CONNECT CAN PROVE
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * `/me` answers for the USER, and publishing to a Page or to an Instagram professional account needs the
 * PAGE's id and a page access token obtained from `/me/accounts`. That step is deliberately not here:
 * which page a workspace publishes to is a CHOICE somebody makes, not something to guess from the first
 * element of a list, and building a picker for it is B3's work with a screen attached. What is stored
 * now identifies the authorizing account, which is what uniqueness needs. The first real connect is what
 * will show whether the granted scopes actually return a usable `/me` for a business login.
 */
class MetaOAuthProvider extends AbstractOAuthProvider
{
    public function authorizeUrl(string $state): string
    {
        // The core parameters are the LEFT operand of `+`, so configured extras can only add and never
        // replace `state`, `redirect_uri` or `client_id`. See GoogleOAuthProvider for why that direction
        // matters.
        $query = [
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            // Meta wants a COMMA-separated list where Google wants spaces. One of the small, silent
            // differences that a shared "OAuth service" would have got wrong for one of the two.
            'scope' => implode(',', $this->scopes()),
            'state' => $state,
        ] + $this->authorizeParams();

        return $this->endpoint('authorize_url') . '?' . http_build_query($query);
    }

    /**
     * Code → SHORT-lived token → LONG-lived token. Two calls, and only the second result is storable.
     *
     * @throws OAuthExchangeFailed
     */
    public function exchangeCode(string $code): OAuthTokens
    {
        // Step 1. Meta's token endpoint is a GET with the parameters in the query string. Note that the
        // client secret is in that URL — which is why AbstractOAuthProvider logs a transport failure's
        // exception CLASS and never its message.
        $short = $this->getJson($this->endpoint('token_url'), [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ], failureCode: OAuthExchangeFailed::EXCHANGE);

        // Step 2. Without this the connection dies before the first scheduled post. See the docblock.
        //
        // Wrapped in the credential DTO rather than handed on as a string: a positional string argument
        // is rendered — truncated to fifteen characters — by `getTraceAsString()`, and this frame is on
        // the stack across a call that throws. See AbstractOAuthProvider::getJson().
        return $this->exchangeForLongLived(
            new PlatformCredentials($this->requireAccessToken($short)),
            OAuthExchangeFailed::EXCHANGE,
        );
    }

    /**
     * Always true: renewal needs nothing a connection does not already have.
     *
     * Whether it will SUCCEED is a different question — a lapsed token cannot be exchanged — and that
     * answer belongs to the platform, not to a predicate we could evaluate locally.
     */
    public function canRefresh(PlatformCredentials $credentials): bool
    {
        return $credentials->accessToken !== '';
    }

    /**
     * Renew by re-exchanging the CURRENT long-lived token. See the class docblock.
     *
     * @throws OAuthExchangeFailed
     */
    public function refresh(PlatformCredentials $credentials): OAuthTokens
    {
        if (!$this->canRefresh($credentials)) {
            throw new OAuthExchangeFailed(OAuthExchangeFailed::REFRESH_UNSUPPORTED);
        }

        return $this->exchangeForLongLived($credentials, OAuthExchangeFailed::REFRESH);
    }

    /**
     * WHO authorized this. See the class docblock for why this is `/me` and not a page picker.
     *
     * @throws OAuthExchangeFailed
     */
    public function fetchAccount(OAuthTokens $tokens): RemoteAccount
    {
        // `fields=id,name`, from the config. Meta happens to default `/me` to exactly these, which is
        // why this platform kept working while the same defect broke YouTube outright — asking for them
        // explicitly makes the response shape ours rather than a default we are relying on.
        $body = $this->getJson(
            $this->endpoint('account_url'),
            $this->queryFor('account_params'),
            bearer: $tokens,
        );

        $id = $body['id'] ?? null;

        if (!is_string($id) || $id === '') {
            throw new OAuthExchangeFailed(OAuthExchangeFailed::ACCOUNT_LOOKUP, null, 'no_account');
        }

        $name = $body['name'] ?? null;

        return RemoteAccount::make($id, is_string($name) ? $name : null);
    }

    /**
     * The `fb_exchange_token` grant, which serves both as step two of an exchange and as the whole of a
     * refresh. One method, because it is literally one call — and because the two callers differ only in
     * which failure code a rejection should carry.
     *
     * Meta does not echo granted scopes on this endpoint, so the scope list stays whatever the exchange
     * recorded. That is honest: a renewal does not re-grant anything, and inventing an empty list here
     * would make a refresh look like a permission loss.
     *
     * IT TAKES THE CREDENTIAL DTO, NOT THE TOKEN STRING. `getTraceAsString()` renders positional string
     * arguments (truncated to fifteen characters) and this frame is on the stack while
     * `requireAccessToken()` throws directly beneath it — which is how fifteen bytes of a live token end
     * up in a trace. The DTO refuses to render and appears as `Object(…)`.
     *
     * @throws OAuthExchangeFailed
     */
    private function exchangeForLongLived(PlatformCredentials $credentials, string $failureCode): OAuthTokens
    {
        $body = $this->getJson($this->endpoint('long_lived_url'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'fb_exchange_token' => $credentials->accessToken,
        ], failureCode: $failureCode);

        return OAuthTokens::make(
            accessToken: $this->requireAccessToken($body),
            // NEVER. Meta issues none, and a null here is the accurate statement rather than a gap.
            refreshToken: null,
            expiresIn: $this->expiresIn($body),
        );
    }
}
