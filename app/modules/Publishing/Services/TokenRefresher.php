<?php

namespace App\Modules\Publishing\Services;

use App\Modules\Publishing\Exceptions\CredentialsUnreadable;
use App\Modules\Publishing\Exceptions\OAuthExchangeFailed;
use App\Modules\Publishing\Exceptions\UnknownOAuthProvider;
use App\Modules\Publishing\Managers\PlatformConnectionManager;
use App\Modules\Publishing\Models\PlatformConnection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * KEEPS CREDENTIALS ALIVE, AND — WHEN THAT FAILS — MAKES THE FAILURE HOLD THE QUEUE INSTEAD OF
 * PRODUCING TWELVE OF THEM AT NINE IN THE MORNING.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * IT RUNS AHEAD OF EXPIRY, NOT AT IT
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * `publishing.tokens.refresh_lead` — a day by default. Refreshing at the moment of expiry means
 * refreshing too late: the sweep is on a cron, the platform's clock is not ours, and a token that lapses
 * between two passes takes whatever was scheduled in that window with it.
 *
 * The lead matters most for Meta, which cannot be recovered after the fact. Its renewal exchanges the
 * CURRENT long-lived token for a new one, so once the sixty days lapse there is nothing left to exchange
 * and only a person at a consent screen can repair the connection. A day of passes is the margin.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * EVERY WAY THIS CAN FAIL ENDS IN `needs_reauth`. NONE OF THEM ENDS IN A 500 OR A SKIP.
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * The codes below come from {@see PlatformConnectionManager}'s list, which is the vocabulary of the
 * `failure_code` COLUMN — not from {@see OAuthExchangeFailed}, which describes a token ENDPOINT call.
 * Stamping the latter is what put `token_refresh_failed` on rows whose translation was `refresh_failed`,
 * so the sentence a user read came from a fallback rather than from what had happened. What the endpoint
 * said still travels, in the log context, where the distinction between the two is the useful part.
 *
 *   THE PLATFORM REFUSED          `refresh_failed`. The user revoked access, the app's permissions
 *                                 changed, the grant expired.
 *   THE CIPHERTEXT WILL NOT OPEN  `credentials_unreadable`. APP_KEY was rotated, or this database was
 *                                 restored beside a different application. Caught SPECIFICALLY, because
 *                                 the alternative is a `DecryptException` reaching the top of a worker:
 *                                 no state change, no hold, and the same row failing identically on
 *                                 every pass forever while its publications go out into a wall.
 *   NOTHING TO RENEW WITH         `refresh_unsupported`. A Google connection with no refresh token —
 *                                 which the exchange now refuses to create, but which a row predating
 *                                 that refusal, or a manual repair, could still hold.
 *   ANYTHING ELSE AT ALL          `refresh_failed`. A `Throwable` catch-all, and it is not laziness:
 *                                 the alternative is one unanticipated exception class stopping a sweep
 *                                 that is holding queues for every other connection behind it.
 *
 * A connection that cannot be renewed is not left alone to be discovered later. It is parked, and the
 * parking is what blocks its publications — see {@see PlatformConnectionManager::markNeedsReauth()}.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT IS LOGGED
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * The connection id, the platform, our own failure code, an HTTP status and the platform's machine error
 * code. Never a token, never a response body, never an exception MESSAGE from the HTTP layer — the
 * provider has already reduced those to a code at its own boundary, which is the only place that
 * reduction is safe to make.
 */
class TokenRefresher
{
    public function __construct(
        private OAuthProviderRegistry $providers,
        private PlatformConnectionManager $connections,
    ) {}

    /**
     * Refresh everything due ON THE ACTIVE CONNECTION, and answer how many were renewed.
     *
     * "Active connection" is the database the tenancy layer has selected — the console command walks
     * every own-database workspace and calls this once per database, plus once with no tenant for the
     * shared one. Nothing here knows about workspaces, which is what lets it be the same code both times.
     *
     * The batch is ordered by `expires_at` so the most urgent go first: if the cap bites, what is
     * deferred is what has the most time left, and the next pass picks it up.
     *
     * @return array{refreshed: int, parked: int}
     */
    public function refreshDue(): array
    {
        $connections = PlatformConnection::query()
            ->refreshDue($this->lead())
            ->orderBy('expires_at')
            ->limit($this->batch())
            ->get();

        $refreshed = 0;
        $parked = 0;

        foreach ($connections as $connection) {
            $this->refresh($connection) ? $refreshed++ : $parked++;
        }

        return ['refreshed' => $refreshed, 'parked' => $parked];
    }

    /**
     * Renew ONE connection. True when it is still usable afterwards.
     *
     * Returns a boolean rather than throwing, because every caller's answer to a failure is the same and
     * has already been applied by the time this returns: the connection is parked and its queue is held.
     * A caller that had to catch would be a caller that could forget to.
     */
    public function refresh(PlatformConnection $connection): bool
    {
        try {
            $provider = $this->providers->resolve($connection->platform);
        } catch (UnknownOAuthProvider) {
            // A CONFIGURATION error, not a credential one: this build cannot speak to that platform at
            // all. Parked anyway, because the operational truth is identical — nothing will go out on
            // this connection — and a held queue is better than a queue that fails item by item.
            $this->park($connection, PlatformConnectionManager::FAILURE_REFRESH_UNSUPPORTED, ['reason' => 'no_provider']);

            return false;
        }

        try {
            $credentials = $connection->credentials();
        } catch (CredentialsUnreadable) {
            // THE APP_KEY CASE. Never a 500, never a skip. See the class docblock.
            $this->park($connection, PlatformConnectionManager::FAILURE_CREDENTIALS_UNREADABLE);

            return false;
        }

        if (!$provider->canRefresh($credentials)) {
            $this->park($connection, PlatformConnectionManager::FAILURE_REFRESH_UNSUPPORTED);

            return false;
        }

        try {
            $tokens = $provider->refresh($credentials);
        } catch (OAuthExchangeFailed $e) {
            // The endpoint's own code and status ride along in the CONTEXT. The row gets the column's
            // vocabulary; the log gets the diagnosis. `endpoint_failure` is named separately because
            // `park()`'s own `failure_code` would otherwise shadow it — and the two are not always the
            // same thing: a 200 with no token in it reports `token_response_unusable`, which is a
            // materially different fault from a refused grant.
            $this->park($connection, PlatformConnectionManager::FAILURE_REFRESH_FAILED, [
                'endpoint_failure' => $e->failureCode,
            ] + $e->context());

            return false;
        } catch (Throwable $e) {
            // CLASS ONLY, never the message: this is the layer immediately above an HTTP client that was
            // holding a token when it threw.
            $this->park($connection, PlatformConnectionManager::FAILURE_REFRESH_FAILED, ['exception' => $e::class]);

            return false;
        }

        $this->connections->storeTokens($connection, $tokens);

        return true;
    }

    /**
     * Park a connection and hold its queue, saying why in the log and on the row.
     *
     * @param  array<string, mixed>  $context  codes and statuses only — see the class docblock
     */
    private function park(PlatformConnection $connection, string $failureCode, array $context = []): void
    {
        Log::warning('A platform connection could not be renewed and now needs re-authorization.', [
            'platform_connection_id' => $connection->id,
            'platform' => $connection->platform->value,
            'failure_code' => $failureCode,
            'previous_status' => $connection->status->value,
        ] + $context);

        $this->connections->markNeedsReauth($connection, $failureCode);
    }

    private function lead(): int
    {
        return max(0, (int) config('publishing.tokens.refresh_lead', 86400));
    }

    private function batch(): int
    {
        return max(1, (int) config('publishing.tokens.refresh_batch', 200));
    }
}
