<?php

namespace App\Modules\Publishing\OAuth;

use App\Modules\Publishing\DTOs\OAuthTokens;
use App\Modules\Publishing\DTOs\PlatformCredentials;
use App\Modules\Publishing\DTOs\RemoteAccount;
use App\Modules\Publishing\Exceptions\OAuthExchangeFailed;

/**
 * GOOGLE'S DIALECT — the one that issues a refresh token, and issues it exactly once.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE DEFECT THIS CLASS IS SHAPED AROUND
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * Google returns a refresh token ONLY on the first consent for a given user-and-application pair, and
 * only when the authorize URL carried BOTH `access_type=offline` AND `prompt=consent`. Leave either out
 * and everything looks perfect: the consent screen appears, the user approves, the exchange returns 200
 * with a valid access token, the connection is stored, a publication goes out. An hour later the access
 * token expires, there is nothing to renew it with, and the account needs a human — with no failure
 * anywhere pointing at the authorize call that caused it, an hour earlier.
 *
 * Two things follow. The parameters live in `config/publishing.php` as DATA, labelled load-bearing, so
 * they are visible to whoever is reading the configuration rather than buried in a query-string
 * assembly. And {@see exchangeCode()} refuses an exchange that came back without a refresh token,
 * because a connection that cannot be renewed is not a connection — it is an hour of one, and the
 * honest moment to say so is now, at a screen where somebody can re-consent, rather than in a queue at
 * 09:00 tomorrow.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * A REFRESH RETURNS NO REFRESH TOKEN, AND THAT IS NORMAL
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * `grant_type=refresh_token` answers with a new access token, an `expires_in`, and nothing else. The
 * stored refresh token stays valid and MUST BE KEPT — which is why the whole module persists tokens by
 * merging rather than assigning (`PlatformConnectionManager::storeTokens`). Writing this response over
 * the row would null the refresh token on the first successful renewal: the connection would then work
 * for one more hour, exactly as if the `prompt=consent` parameter had been missing, and the cause would
 * be two files away from the symptom.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT ONLY A REAL CONNECT CAN PROVE
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * Every path below is exercised against `Http::fake` — the shapes are from Google's published contract
 * and the parsing is real. What fakes cannot establish: that the scope strings in the config are the
 * ones the application was actually granted, that the redirect URI matches the one registered byte for
 * byte, and that `channels?mine=true` returns an item for the authorizing account (it answers with an
 * EMPTY `items` array for a Google account that has never created a YouTube channel — handled below as
 * a refusal, but only a real account can show which case is which).
 */
class GoogleOAuthProvider extends AbstractOAuthProvider
{
    public function authorizeUrl(string $state): string
    {
        // `include_granted_scopes`, `access_type` and `prompt` arrive from the config. See the class
        // docblock for why the last two are not optional.
        //
        // THE CORE PARAMETERS ARE THE LEFT OPERAND, AND THAT ORDER IS THE POINT. `+` keeps the left
        // side's keys, so written the other way round a `state`, `redirect_uri` or `client_id` in
        // `authorize_params` would REPLACE the ones this method computes — a config file able to point
        // a consent screen's redirect somewhere else, which is the classic first leg of an OAuth code
        // theft. Extras may only add.
        $query = [
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
        ] + $this->authorizeParams();

        return $this->endpoint('authorize_url') . '?' . http_build_query($query);
    }

    /**
     * Code → tokens. One call.
     *
     * @throws OAuthExchangeFailed
     */
    public function exchangeCode(string $code): OAuthTokens
    {
        $body = $this->postForm($this->endpoint('token_url'), [
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ], OAuthExchangeFailed::EXCHANGE);

        $tokens = OAuthTokens::make(
            accessToken: $this->requireAccessToken($body),
            refreshToken: is_string($body['refresh_token'] ?? null) ? $body['refresh_token'] : null,
            expiresIn: $this->expiresIn($body),
            // Google answers with a SPACE-SEPARATED string, not an array. What it grants can be narrower
            // than what was asked for, and recording the granted set is the only way a later permission
            // failure is explicable.
            scopes: $this->parseScopes($body['scope'] ?? null),
        );

        // THE REFUSAL THIS CLASS EXISTS FOR. A connection with no refresh token is an hour of a
        // connection; saying so here is the difference between a re-consent now and a broken queue
        // tomorrow morning.
        if (!$tokens->refreshToken) {
            throw new OAuthExchangeFailed(OAuthExchangeFailed::UNUSABLE_RESPONSE, null, 'missing_refresh_token');
        }

        return $tokens;
    }

    /** Google renews with the refresh token, so a connection without one cannot be renewed at all. */
    public function canRefresh(PlatformCredentials $credentials): bool
    {
        return $credentials->hasRefreshToken();
    }

    /**
     * Renew. Returns a new access token and NO refresh token — see the class docblock.
     *
     * @throws OAuthExchangeFailed
     */
    public function refresh(PlatformCredentials $credentials): OAuthTokens
    {
        if (!$this->canRefresh($credentials)) {
            throw new OAuthExchangeFailed(OAuthExchangeFailed::REFRESH_UNSUPPORTED);
        }

        $body = $this->postForm($this->endpoint('token_url'), [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'refresh_token' => (string) $credentials->refreshToken,
            'grant_type' => 'refresh_token',
        ], OAuthExchangeFailed::REFRESH);

        return OAuthTokens::make(
            accessToken: $this->requireAccessToken($body),
            // Absent by design. The stored one is kept by the merge at the write site.
            refreshToken: is_string($body['refresh_token'] ?? null) ? $body['refresh_token'] : null,
            expiresIn: $this->expiresIn($body),
            scopes: $this->parseScopes($body['scope'] ?? null),
        );
    }

    /**
     * WHICH CHANNEL this token can post to.
     *
     * `mine=true` answers for the authorizing account and nothing else, which is the only account we are
     * entitled to name. An empty `items` array is a real and common answer — a Google account that has
     * never created a YouTube channel — and it is a refusal rather than a connection with a blank id: a
     * connection whose account cannot be identified would break the per-account uniqueness the whole
     * table is keyed on.
     *
     * @throws OAuthExchangeFailed
     */
    public function fetchAccount(OAuthTokens $tokens): RemoteAccount
    {
        // `part=snippet&mine=true`, from the config. Both are required — Google answers 400
        // `missingRequiredParameter` without `part` — and they are passed as parameters rather than
        // carried on the URL because an HTTP client can replace a URL's query and cannot replace these.
        $body = $this->getJson(
            $this->endpoint('account_url'),
            $this->queryFor('account_params'),
            bearer: $tokens,
        );

        $item = $body['items'][0] ?? null;

        if (!is_array($item) || !is_string($item['id'] ?? null) || $item['id'] === '') {
            throw new OAuthExchangeFailed(OAuthExchangeFailed::ACCOUNT_LOOKUP, null, 'no_channel');
        }

        $title = $item['snippet']['title'] ?? null;

        return RemoteAccount::make($item['id'], is_string($title) ? $title : null);
    }

    /**
     * Google's `scope` field: one space-separated string.
     *
     * Tolerating an array as well costs a line and covers the day a `v3` endpoint answers the way every
     * other platform does — the alternative being a silently empty granted-scope list, which is exactly
     * the field somebody consults when a publish fails with a permission error.
     *
     * @return array<int, string>
     */
    private function parseScopes(mixed $scope): array
    {
        if (is_array($scope)) {
            return array_values(array_filter($scope, 'is_string'));
        }

        if (!is_string($scope) || trim($scope) === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', trim($scope)) ?: []));
    }
}
