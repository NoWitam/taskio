<?php

namespace App\Modules\Publishing\Services;

use App\Models\User;
use App\Modules\Publishing\DTOs\OAuthState;
use App\Modules\Publishing\DTOs\StartedHandshake;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Managers\PlatformConnectionManager;
use App\Modules\Publishing\Models\PlatformConnection;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * THE TWO HALVES OF A HANDSHAKE, AND THE LIST THEY PRODUCE.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THE HANDSHAKE IS TWO ENDPOINTS AND NOT ONE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * {@see beginAuthorization()} runs inside an ORDINARY AUTHENTICATED API REQUEST — bearer token,
 * `X-Workspace-Id`, `ResolveWorkspace`, a policy. That is the only moment in the whole flow when the
 * application can prove who is asking and for which workspace, so that is where the proof is minted.
 *
 * {@see completeAuthorization()} runs from a browser redirect with none of those. Everything it knows
 * comes out of the verified {@see OAuthState} handed to it. It therefore takes the state as an
 * ARGUMENT rather than reading a request: a service that reached for `auth()` or `request()` here would
 * be reading whatever the callback happens to look like, and the difference between "the user who
 * started this" and "whoever the request looks like" is the difference between attributing an account
 * correctly and attaching it to the wrong workspace. B2's review found a globally prepended middleware
 * logging a hardcoded user in on every request, which is precisely how "whoever the request looks like"
 * stops being nobody without anybody deciding that it should.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE NETWORK CALLS HAPPEN BEFORE ANY WRITE, AND OUTSIDE ANY TRANSACTION
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Exchange, then account lookup, then — only once both have succeeded — one transactional write in the
 * Manager. Two reasons, and the second is the one that would have been discovered the hard way:
 *
 *   A failed exchange must leave NOTHING behind. A half-written connection with no account id would
 *   occupy the unique key and make the retry (the same user connecting the same channel again) collide
 *   with a row that never worked.
 *
 *   An HTTP round trip inside a transaction holds row locks for the duration of somebody else's
 *   outage. The exchange has a ten-second timeout; a lock held that long on a connection row blocks the
 *   refresh sweep and every publish routed through it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * NO STATUS IS WRITTEN HERE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Not the connection's and not a publication's. Both belong to Managers, and the reason is not
 * tidiness: a service that set a connection `active` on its own would un-block a queue of publications
 * for a connection nobody verified, and they would go out on a schedule.
 */
class PlatformConnectionService
{
    public function __construct(
        private OAuthProviderRegistry $providers,
        private OAuthStateService $states,
        private PlatformConnectionManager $connections,
    ) {}

    /**
     * The workspace's connections, newest first.
     *
     * NOT paginated, and that is a judgement rather than an omission: a workspace has as many
     * connections as it has social accounts, which is a handful. A cursor over four rows is machinery
     * with a cost and no reader. `withTrashed` is deliberately absent — a disconnected account is gone
     * from every list and picker; the row survives only so the publications pointing at it still
     * resolve.
     *
     * @return Collection<int, PlatformConnection>
     */
    public function index(Request $request): Collection
    {
        return PlatformConnection::query()
            ->with('creator')
            ->when(
                $platforms = array_values(array_filter($request->array('platform'), 'is_string')),
                fn ($query) => $query->whereIn('platform', $platforms),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * START a handshake: mint the state, and answer with the URL to send the browser to.
     *
     * The ledger entry that makes the nonce single-use is written by the state service before this
     * returns, so there is no window in which a URL exists that cannot be redeemed — nor one in which it
     * can be redeemed twice.
     *
     * The BROWSER BINDING comes back with it, as a cookie for the controller to attach. A handshake is
     * only startable from an authenticated request, and this is the last moment at which anything about
     * the requesting browser is knowable — the return leg is a navigation the platform performs.
     */
    public function beginAuthorization(User $user, Workspace $workspace, PublishingPlatform $platform): StartedHandshake
    {
        $provider = $this->providers->resolve($platform);

        $state = $this->states->issue($user, $workspace, $platform);

        return new StartedHandshake(
            authorizeUrl: $provider->authorizeUrl($this->states->encode($state)),
            // So a client can tell somebody how long they have, rather than guessing from a constant it
            // would have to keep in step with the server's config.
            expiresIn: $state->secondsRemaining(),
            handshakeCookie: $this->states->handshakeCookie($state),
        );
    }

    /**
     * FINISH a handshake: code in, stored connection out.
     *
     * The caller has already verified and CONSUMED the state — that is not this method's job, and
     * splitting it that way is deliberate: a service that verified its own state could be called twice
     * with the same one by a caller that did not know better, and the second call would succeed.
     *
     * The account lookup is not optional. Without it there is no `external_account_id`, and without that
     * the same channel can be authorized twice into two rows with two live tokens.
     *
     * @throws \App\Modules\Publishing\Exceptions\OAuthExchangeFailed when the platform refuses either call
     */
    public function completeAuthorization(OAuthState $state, string $code): PlatformConnection
    {
        $provider = $this->providers->resolve($state->platform);

        // ── outside the world's reach, before anything of ours is written ──────────
        $tokens = $provider->exchangeCode($code);
        $account = $provider->fetchAccount($tokens);

        // ── one transactional write, in the Manager, which also releases the held queue ──
        return $this->connections->connect(
            platform: $state->platform,
            account: $account,
            tokens: $tokens,
            creatorId: $state->userId,
        );
    }
}
