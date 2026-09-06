<?php

namespace App\Modules\Publishing\OAuth;

use App\Modules\Publishing\Contracts\OAuthProvider;
use App\Modules\Publishing\DTOs\OAuthTokens;
use App\Modules\Publishing\DTOs\PlatformCredentials;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Exceptions\OAuthExchangeFailed;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * THE PART OF AN OAUTH PROVIDER THAT IS ABOUT NOT LEAKING A TOKEN.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS BASE CLASS EXISTS AT ALL
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * Not to share four lines of HTTP setup. It exists so that "a token endpoint's response body never
 * leaves the method that read it" is enforced by INHERITANCE rather than by each implementor
 * remembering it — and the reason that matters is that the framework's own ergonomics push the other
 * way, hard:
 *
 *   `$response->throw()` raises a `RequestException` whose message embeds the first 120 characters of
 *   the body. For a token endpoint that is `{"access_token":"ya29.a0Af…` — a working credential, in an
 *   exception message, which Laravel's handler then writes to the log with a stack trace. It is one
 *   idiomatic method call, it is what every other HTTP client in this repository does, and here it is
 *   forbidden.
 *
 *   `Http::` failures also carry the REQUEST. A `ConnectionException` or a dumped `PendingRequest` can
 *   surface the form body, which on a token call contains the client secret and the authorization code.
 *
 * So {@see postForm()} and {@see getJson()} are the only doors, they never call `throw()`, and every
 * failure becomes {@see OAuthExchangeFailed} — a class with no parameter a body could arrive through.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT IS ALLOWED OUT OF A FAILED CALL
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * The HTTP status, and the platform's own MACHINE error code read from named fields. Both Google
 * (`error`) and Meta (`error.type` / `error.code`) document these as enumerations, they are what
 * actually distinguishes "the user revoked access" from "our clock is skewed", and neither is a place a
 * credential appears. Everything else — the message, the description, the body, the headers — is
 * dropped at the boundary.
 *
 * Even the exception CLASS of a transport failure is logged rather than its message: a
 * `ConnectionException` names the host, which is harmless, but a subclass we have not anticipated may
 * not stop there.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * TIMEOUTS ARE SHORT AND THERE ARE NO RETRIES
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * A token exchange happens while a person is staring at a redirect. Ten seconds is already longer than
 * anybody waits, and a retry loop here would turn one rejected code into several — authorization codes
 * are single-use at the platform, so the second attempt fails differently from the first and reports
 * the wrong reason.
 */
abstract class AbstractOAuthProvider implements OAuthProvider
{
    private const TIMEOUT_SECONDS = 10;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    public function __construct(protected PublishingPlatform $platform) {}

    public function platform(): PublishingPlatform
    {
        return $this->platform;
    }

    /**
     * Configured means: we know who we are to this platform.
     *
     * Endpoints have defaults and scopes have defaults; the client id and secret cannot, and their
     * absence is the shipped state until the owner registers the applications.
     */
    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    /**
     * The callback URL this platform must redirect to — and must have registered.
     *
     * Built from `publishing.oauth.redirect_base` when set, so a deployment behind a public HTTPS origin
     * (which both platforms require, and which no `localhost` satisfies) hands out the origin the
     * platform knows rather than whatever `APP_URL` happens to say. Falling back to the named route
     * keeps every test and every ordinary environment working with no configuration.
     */
    protected function redirectUri(): string
    {
        $base = config('publishing.oauth.redirect_base');

        if (is_string($base) && trim($base) !== '') {
            return rtrim(trim($base), '/') . '/oauth/' . $this->platform->value . '/callback';
        }

        return route('publishing.oauth.callback', ['platform' => $this->platform->value]);
    }

    /** @return array<string, mixed> */
    protected function config(): array
    {
        $config = config('publishing.platforms.' . $this->platform->value);

        return is_array($config) ? $config : [];
    }

    protected function clientId(): string
    {
        return (string) ($this->config()['client_id'] ?? '');
    }

    protected function clientSecret(): string
    {
        return (string) ($this->config()['client_secret'] ?? '');
    }

    /** @return array<int, string> */
    protected function scopes(): array
    {
        $scopes = $this->config()['scopes'] ?? [];

        return is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];
    }

    /** @return array<string, string> */
    protected function authorizeParams(): array
    {
        return $this->params('authorize_params');
    }

    /**
     * Configured query parameters for an endpoint, with the CALLER'S OWN WINNING.
     *
     * The house doctrine for this module is that per-platform differences are data in
     * `config/publishing.php` rather than branches in a service, and an endpoint's required query
     * parameters are exactly that kind of difference — `part=snippet&mine=true` for a YouTube channel
     * lookup, `fields=id,name` for a Meta one.
     *
     * The merge direction is the correction of a real defect: written the other way round, a config file
     * could overwrite the parameters the caller is not free to negotiate. Extras may only ADD.
     *
     * @param  array<string, mixed>  $explicit
     * @return array<string, mixed>
     */
    protected function queryFor(string $key, array $explicit = []): array
    {
        return $explicit + $this->params($key);
    }

    protected function endpoint(string $key): string
    {
        return (string) ($this->config()[$key] ?? '');
    }

    /** @return array<string, string> */
    private function params(string $key): array
    {
        $params = $this->config()[$key] ?? [];

        return is_array($params) ? array_map('strval', $params) : [];
    }

    /**
     * POST a form to a token endpoint and hand back the decoded body.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     *
     * @throws OAuthExchangeFailed
     */
    protected function postForm(string $url, array $form, string $failureCode): array
    {
        return $this->decode(
            fn (): Response => $this->client()->asForm()->post($url, $form),
            $failureCode,
        );
    }

    /**
     * GET a JSON endpoint, optionally bearing a token.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * THE URL'S OWN QUERY STRING IS MERGED, NEVER REPLACED
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * `PendingRequest::get($url, $query)` decides by `func_num_args()`, so passing a query — INCLUDING
     * AN EMPTY ONE — hands Guzzle a `query` option, and Guzzle OVERWRITES the URL's own query rather
     * than merging with it. An empty array becomes `withQuery('')`, which erases it outright.
     *
     * That is not a hypothetical: it silently truncated
     * `…/youtube/v3/channels?part=snippet&mine=true` to `…/youtube/v3/channels`, which Google answers
     * with 400 `missingRequiredParameter`. Every real YouTube connect would have failed AFTER a
     * successful consent and a successful token exchange — the refresh token issued, the grant live on
     * the user's account, and nothing stored on ours. Meta survived it by luck, because `/me` defaults
     * to the fields we wanted.
     *
     * So the parameters carried by the URL are parsed out and merged UNDER the caller's, and the
     * guarantee holds however an endpoint is configured. The parameters themselves have moved into
     * `config/publishing.php` as `*_params` lists, which is where a per-platform difference belongs;
     * this is the belt that makes a relapse impossible rather than unlikely.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * THE BEARER IS A DTO, NOT A STRING
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * `Throwable::getTraceAsString()` renders positional string arguments, truncated to fifteen
     * characters — enough of an access token to be worth having, and a frame for this method is on the
     * stack for the whole of a call that can throw. Both DTOs refuse to render (`__debugInfo`) and
     * refuse to serialize (`__sleep`), so a trace shows `Object(…)` instead. That is the difference
     * between the guarantee holding by inheritance and holding because somebody remembered.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws OAuthExchangeFailed
     */
    protected function getJson(
        string $url,
        array $query = [],
        OAuthTokens|PlatformCredentials|null $bearer = null,
        string $failureCode = OAuthExchangeFailed::ACCOUNT_LOOKUP,
    ): array {
        $carried = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $carried);

        // The caller's parameters win; the URL's own survive instead of being erased.
        $query += $carried;

        return $this->decode(
            function () use ($url, $query, $bearer): Response {
                $client = $this->client();

                if ($bearer !== null) {
                    $client = $client->withToken($bearer->accessToken);
                }

                return $client->get($url, $query);
            },
            $failureCode,
        );
    }

    /**
     * THE BOUNDARY. Everything a platform said crosses it as either a decoded array or a code.
     *
     * @param  callable(): Response  $send
     * @return array<string, mixed>
     *
     * @throws OAuthExchangeFailed
     */
    private function decode(callable $send, string $failureCode): array
    {
        try {
            $response = $send();
        } catch (Throwable $e) {
            // CLASS ONLY. A transport exception's message can carry the URL with its query string, and
            // a Meta token call puts the client secret there.
            Log::warning('Publishing OAuth call could not be completed.', [
                'platform' => $this->platform->value,
                'failure_code' => $failureCode,
                'exception' => $e::class,
            ]);

            throw new OAuthExchangeFailed($failureCode);
        }

        if ($response->failed()) {
            $error = $this->platformErrorCode($response);

            Log::warning('Publishing OAuth call was rejected by the platform.', [
                'platform' => $this->platform->value,
                'failure_code' => $failureCode,
                'status' => $response->status(),
                // A documented enumeration, read from named fields. Never the body — see the class
                // docblock for what the body of a token response is.
                'platform_error' => $error,
            ]);

            throw new OAuthExchangeFailed($failureCode, $response->status(), $error);
        }

        $body = $response->json();

        if (!is_array($body)) {
            // A 200 whose body is not JSON. Nothing about it is logged: an unexpected shape from a token
            // endpoint is exactly where an undocumented field carrying a credential would be.
            throw new OAuthExchangeFailed(OAuthExchangeFailed::UNUSABLE_RESPONSE, $response->status());
        }

        return $body;
    }

    /**
     * The platform's machine error code, from the two shapes in play, or null.
     *
     * Google:  `{"error": "invalid_grant", "error_description": "..."}` — the description is prose and is
     *          deliberately not read.
     * Meta:    `{"error": {"type": "OAuthException", "code": 190, "message": "..."}}` — again, the
     *          message is prose and is not read.
     *
     * Anything else answers null. A parser that fell back to "stringify whatever is there" would be a
     * body making it out under a different name, which is the exact leak this file exists to prevent.
     */
    private function platformErrorCode(Response $response): ?string
    {
        $body = $response->json();

        if (!is_array($body)) {
            return null;
        }

        $error = $body['error'] ?? null;

        if (is_string($error) && $error !== '') {
            return substr($error, 0, 64);
        }

        if (is_array($error)) {
            $type = $error['type'] ?? null;
            $code = $error['code'] ?? null;

            $parts = array_filter([
                is_string($type) ? substr($type, 0, 48) : null,
                // BOUNDED, like the type beside it. `code` is documented as an integer, so the string
                // branch exists only for a platform that answered with something else — which is
                // precisely the case where "however long it is" would be wrong. An unbounded field read
                // out of a response body and written to a log is a body reaching the log by increments.
                is_int($code) || is_string($code) ? substr((string) $code, 0, 32) : null,
            ]);

            return $parts === [] ? null : implode(':', $parts);
        }

        return null;
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS);
    }

    /**
     * The access token out of a decoded token response, or a refusal.
     *
     * Shared because both dialects agree on this one field, and because "the call succeeded but there is
     * no token in it" is a case worth having a name for rather than a null that travels.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws OAuthExchangeFailed
     */
    protected function requireAccessToken(array $body): string
    {
        $token = $body['access_token'] ?? null;

        if (!is_string($token) || $token === '') {
            throw new OAuthExchangeFailed(OAuthExchangeFailed::UNUSABLE_RESPONSE);
        }

        return $token;
    }

    /**
     * `expires_in` as an int, tolerating the string form.
     *
     * Meta's Graph API has historically answered with a numeric string, and a strict `is_int` would have
     * read that as "no expiry" — turning a sixty-day token into one the refresher never looks at, which
     * fails silently two months later.
     *
     * @param  array<string, mixed>  $body
     */
    protected function expiresIn(array $body): ?int
    {
        $value = $body['expires_in'] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
