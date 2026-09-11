<?php

namespace App\Modules\Publishing\Managers;

use App\Modules\Publishing\DTOs\OAuthTokens;
use App\Modules\Publishing\DTOs\RemoteAccount;
use App\Modules\Publishing\Enums\PlatformConnectionStatus;
use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Exceptions\CredentialsUnreadable;
use App\Modules\Publishing\Exceptions\OAuthExchangeFailed;
use App\Modules\Publishing\Exceptions\PublicationTransitionRefused;
use App\Modules\Publishing\Models\PlatformConnection;
use App\Modules\Publishing\Models\Publication;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * THE ONLY THING THAT CHANGES WHETHER AN ACCOUNT IS USABLE — AND, INSEPARABLY, WHAT THAT DOES TO THE
 * PUBLICATIONS WAITING ON IT.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A MANAGER AND NOT A SERVICE
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * The house rule reserves Managers for lifecycle with COORDINATED behaviour, and this is the case it
 * describes. A connection's status is not a label on a row: it is a lever that holds or releases a
 * queue. `needs_reauth` must put every scheduled publication on that connection into `blocked`, and
 * `active` must put them back — and the two halves have to happen together or the mechanism is worse
 * than not having it.
 *
 * Split them and the failure is silent in the expensive direction: a connection marked `needs_reauth`
 * whose publications were not held goes out — or rather fails — at 09:00, twelve times, which is the
 * precise scenario `blocked` was invented to prevent. So the two writes live in one method, in one
 * transaction, and no service may perform either half alone.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * IT DOES NOT WRITE A PUBLICATION'S STATUS. IT ASKS THE PUBLICATION MANAGER TO.
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * Every publication move below goes through {@see PublicationManager::block()} and
 * {@see PublicationManager::arm()} — the edges `scheduled → blocked` and `blocked → scheduled`, which
 * already existed in B1's table precisely so B2 would have somewhere to attach without adding one.
 *
 * That is not deference for its own sake. It means the transition table is still the single description
 * of how a publication moves, and it means this class cannot invent a move: if the machine ever stops
 * allowing `blocked → scheduled`, this code stops working loudly instead of writing a state nothing
 * else expects.
 *
 * `PublishingStateMachineTest` names this file alongside `PublicationManager` in its status-write scan.
 * It has to: that scan is LITERAL over file bytes and cannot tell `platform_connections.status` from
 * `publications.status`, so the arrival of a second state-bearing model in this module made a false
 * positive inevitable. The exemption is paid for with a NARROWER assertion aimed at this file alone —
 * that it never assigns a status onto a publication, and that the two moves it makes are the named
 * `block()` and `arm()` calls below. The second exemption therefore cannot become a second door into the
 * publication machine.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHY THERE ARE TRANSACTIONS HERE WHEN `PublicationPublisher` REFUSES THEM
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * The publisher must not use one because one of its steps has already changed the OUTSIDE WORLD — a
 * rolled-back handle leaves a container on a platform with no record of it. Nothing here touches a
 * platform. Every write below is ours, and a half-applied hold is exactly the inconsistency a
 * transaction is for. The HTTP call that precedes a `connect()` happens strictly before the transaction
 * opens, for the same reason: a network round trip inside a transaction holds row locks for the length
 * of somebody else's outage.
 *
 * AND THEY ARE OPENED ON THE ROW'S OWN CONNECTION — see {@see transaction()}. `DB::transaction()`
 * resolves `database.default`, while a tenant-aware model in an own-database workspace writes to the
 * `tenant` connection: the transaction would have guarded a connection nothing in the callback touches,
 * and the atomicity promised three times above would not have existed for exactly the customers whose
 * data is most isolated. The precedent is `KnowledgeSubjectPurgeService`, for the same reason.
 */
class PlatformConnectionManager
{
    /**
     * The failure codes THIS class stamps on a publication when it holds one.
     *
     * A release only lifts holds it recognises. That is the difference between "un-block the queue I
     * blocked" and "un-block everything blocked for any reason" — and the second is a real hazard the
     * day B3 adds a hold for a different cause (a rate limit, a moderation flag, an approval withdrawn).
     * Re-arming those on an unrelated reconnect would publish something a person had deliberately stopped.
     *
     * @var array<int, string>
     */
    private const HOLD_CODES = [
        self::HOLD_NEEDS_REAUTH,
        self::HOLD_DISCONNECTED,
    ];

    /** The connection stopped working. Advice: reconnect the account. */
    public const HOLD_NEEDS_REAUTH = 'connection_needs_reauth';

    /** Somebody disconnected the account. Advice: pick another destination, or connect it again. */
    public const HOLD_DISCONNECTED = 'connection_disconnected';

    /**
     * WHY A CONNECTION IS NOT WORKING — the whole vocabulary of `platform_connections.failure_code`.
     *
     * ONE LIST, HERE, because it is the one field on this table a person actually reads: each code is
     * rendered through `publishing.connection_failures.*` on the screen somebody visits when publishing
     * has stopped. The codes drifted apart once already — the refresher stamped `token_refresh_failed`
     * while the translations spelled it `refresh_failed`, so the sentence a user saw came from the
     * factory's default rather than from what had happened, and nothing failed anywhere.
     * `PublishingConnectionVocabularyTest` now pins the two sides together in both languages.
     *
     * IT IS DELIBERATELY NOT {@see OAuthExchangeFailed}'s LIST. That one describes a token ENDPOINT
     * call and travels in the callback's redirect (`token_exchange_failed`, `account_lookup_failed`);
     * this one describes the state of a stored account. Where the two genuinely name the same fact the
     * constant is defined AS the other, so there is one literal rather than two spellings to keep level.
     *
     * @var array<int, string>
     */
    public const FAILURE_CODES = [
        self::FAILURE_REFRESH_FAILED,
        self::FAILURE_REFRESH_UNSUPPORTED,
        self::FAILURE_CREDENTIALS_UNREADABLE,
        self::FAILURE_DISCONNECTED,
    ];

    /** The platform refused to renew. The user revoked access, or the grant lapsed. */
    public const FAILURE_REFRESH_FAILED = 'refresh_failed';

    /** There is nothing left to renew with — a Google connection holding no refresh token. */
    public const FAILURE_REFRESH_UNSUPPORTED = OAuthExchangeFailed::REFRESH_UNSUPPORTED;

    /** The stored ciphertext will not open with this installation's key. APP_KEY was rotated. */
    public const FAILURE_CREDENTIALS_UNREADABLE = CredentialsUnreadable::FAILURE_CODE;

    /** Somebody disconnected it on purpose. Written by {@see revoke()}, so the row says why it is gone. */
    public const FAILURE_DISCONNECTED = 'disconnected_by_user';

    public function __construct(private PublicationManager $publications) {}

    /**
     * A COMPLETED HANDSHAKE BECOMES A CONNECTION — created, or an existing one repaired.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * IT IS AN UPSERT, AND THE KEY IS THE ACCOUNT
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * (platform, external_account_id) within the workspace. Re-authorizing the same channel must UPDATE
     * the row rather than add a second one — two rows for one account means two tokens of which one is
     * stale, and a publication routed by whichever a picker listed first. It is also what makes "fix the
     * broken connection by connecting again" work at all: a new row would leave every held publication
     * pointing at the old one, permanently blocked, with a healthy-looking connection beside it.
     *
     * `withTrashed()` for the same reason one step further out. A disconnect soft-deletes, so
     * reconnecting an account somebody previously removed has to find and restore THAT row — otherwise
     * the unique index refuses the insert and the user sees a database error for the most reasonable
     * thing they could have done.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * IT RELEASES THE QUEUE
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * Every publication this connection was holding goes back to `scheduled`, at the moment it was
     * originally armed for. That is the second half of the fence: `blocked` is only worth having if
     * repairing the cause undoes it, and undoing it by hand across twelve rows is not a repair anybody
     * would perform.
     *
     * A publication whose armed moment has PASSED while the connection was broken comes back as
     * `scheduled` in the past, which the due-sweep will claim on its next pass. That is deliberate:
     * silently moving it forward would publish it at a time nobody chose, and silently dropping it would
     * be a post somebody scheduled that never happened and never said so.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * THE CREATOR IS REQUIRED, NOT OPTIONAL
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * A null would not leave the column empty — `HasCreator` would fall back to `auth()->id()`, and the
     * one caller is an UNAUTHENTICATED callback whose `$request->user()` is nobody at best. Defaulting
     * the parameter would have made the hazard the whole callback is arranged to avoid reachable by
     * omitting an argument, so the type says it out loud instead.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * THE LOOKUP TAKES A ROW LOCK, AND WHAT THAT DOES AND DOES NOT COVER
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * Two callbacks for the same account arriving together (a double-click, a retried navigation) both
     * read, both decide to write, and the second one's write lands on a row the first has moved. With
     * `lockForUpdate` the second waits and then re-reads, which turns the pair into an ordinary upsert.
     *
     * The limit, stated rather than discovered: a lock cannot be taken on a row that does not exist, so
     * two SIMULTANEOUS FIRST connections of the same never-seen account still race, and the unique index
     * decides — the loser gets a `QueryException`, which the callback reports as `connection_failed`.
     * That is the correct outcome for the rarer case: nothing is written twice, and the remedy (click
     * connect again) now finds a row to lock.
     */
    public function connect(
        PublishingPlatform $platform,
        RemoteAccount $account,
        OAuthTokens $tokens,
        string $creatorId,
    ): PlatformConnection {
        return $this->transaction(function () use ($platform, $account, $tokens, $creatorId): PlatformConnection {
            $connection = PlatformConnection::withTrashed()
                ->where('platform', $platform)
                ->where('external_account_id', $account->id)
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                $connection = new PlatformConnection;
                $connection->platform = $platform;
                $connection->external_account_id = $account->id;

                // Stamped EXPLICITLY, from the signed state, because the callback is unauthenticated by
                // construction — see PlatformOAuthCallbackController. HasCreator's rule 1 (an explicit id
                // wins) is what makes this stick; leaving it to auth() would attribute the row to
                // whoever the request happened to look like, which on that route is nobody at best.
                $connection->creator_id = $creatorId;
            }

            $connection->account_name = $account->name;
            $connection->last_refreshed_at = now();
            $connection->failure_code = null;
            $connection->deleted_at = null;

            $this->applyTokens($connection, $tokens);
            $this->write($connection, PlatformConnectionStatus::ACTIVE);

            $this->releaseQueue($connection);

            return $connection;
        });
    }

    /**
     * THE HOLD. `active → needs_reauth`, and every scheduled publication on this connection with it.
     *
     * Called when a refresh fails, when the stored ciphertext will not open, and (from B3) when a
     * publish is rejected with an authentication error. All three mean the same thing operationally:
     * nothing will go out on this account until a person visits a consent screen.
     *
     * Idempotent when it IS called twice — the failure code is rewritten and the hold finds nothing left
     * to hold — which matters because B3 will call it from the publish path, where a connection already
     * parked by the sweep can be parked again by a rejected post.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * THE SWEEP DOES NOT COME BACK. THAT IS THE DESIGN, AND HERE IS WHY.
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * `scopeRefreshDue` composes `usable()`, which is `status = active`, so a parked connection leaves
     * the sweep's selection permanently and is never retried. Deliberate, not an oversight:
     *
     *   NOTHING WOULD CHANGE. Every way into this state is one a retry cannot lift. A revoked Google
     *   grant stays revoked; a lapsed Meta token has nothing left to exchange; ciphertext that will not
     *   open with this key will not open on the next pass either. The remedy is a person at a consent
     *   screen in all three cases.
     *
     *   RETRYING WOULD COST SOMETHING. An hourly pass against a token endpoint that has already said no,
     *   forever, per broken connection — the kind of traffic a platform rate-limits or holds against the
     *   application as a whole, affecting the connections that still work.
     *
     * What this costs, named: a connection broken by a TRANSIENT fault — a 5xx at the token endpoint, a
     * network partition during the pass — is parked as though it were permanent, and a person has to
     * reconnect an account that would have healed on its own. That is the trade this state exists to
     * make: the publications are held either way, so the failure is visible and inert rather than silent
     * and repeated. If transient failures ever prove common in production, the right change is to
     * distinguish them at the point of parking (a 5xx is not an `invalid_grant`), not to re-sweep
     * everything that is broken.
     */
    public function markNeedsReauth(PlatformConnection $connection, string $failureCode): PlatformConnection
    {
        return $this->transaction(function () use ($connection, $failureCode): PlatformConnection {
            $connection->failure_code = $failureCode;

            $this->write($connection, PlatformConnectionStatus::NEEDS_REAUTH);

            $this->holdQueue($connection, self::HOLD_NEEDS_REAUTH);

            return $connection;
        });
    }

    /**
     * DISCONNECT — a person removing an account.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * WHAT HAPPENS TO THE PUBLICATIONS, DECIDED RATHER THAN DEFAULTED
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * Scheduled ones are BLOCKED, under `connection_disconnected`. Everything else is untouched.
     *
     * The three alternatives were considered and each is worse:
     *
     *   DELETE THEM. A scheduled publication is somebody's work — a caption, a chosen moment, media
     *     picked from the Disk. Disconnecting an account is not a statement about that work, and a
     *     destructive side effect on a button labelled "disconnect" is a trap.
     *
     *   LEAVE THEM SCHEDULED. They come due, find no usable connection, and fail one at a time at their
     *     appointed minutes. That is the exact pattern `blocked` exists to replace, and it would be
     *     produced by an action the user took on purpose — the least excusable version of it.
     *
     *   MOVE THEM BACK TO `draft`. Tempting, and it loses the moment. `disarm()` clears `scheduled_at`,
     *     so reconnecting could not restore what somebody planned; they would have to re-pick every
     *     time. `blocked` keeps the schedule, which is the whole reason that state keeps it.
     *
     * PUBLISHED publications are untouched and keep pointing at this row. That is why the disconnect is a
     * SOFT delete and why the foreign key is `restrict`: those rows are our only record of artifacts that
     * still exist in public, under this account's name.
     *
     * The status is set to `revoked` BEFORE the soft delete so the row says why it is gone, not merely
     * that it is — and the failure code says WHY it is gone rather than leaving whatever broke it last.
     * Without that write, disconnecting an account that had previously failed to renew left
     * `refresh_failed` on the row: the screen would explain that the platform would not renew this
     * account, about an account somebody had deliberately removed. `disconnected_by_user` was translated
     * in both languages from the start and, until this line, was never written by anything.
     */
    public function revoke(PlatformConnection $connection): PlatformConnection
    {
        return $this->transaction(function () use ($connection): PlatformConnection {
            $this->holdQueue($connection, self::HOLD_DISCONNECTED);

            $connection->failure_code = self::FAILURE_DISCONNECTED;

            $this->write($connection, PlatformConnectionStatus::REVOKED);

            $connection->delete();

            return $connection;
        });
    }

    /**
     * A SUCCESSFUL RENEWAL. Not a transition — the connection was and remains `active`.
     *
     * It lives on the Manager anyway, because the ONE rule about persisting tokens has to be stated
     * where the write is, and it is a rule about not losing a credential:
     *
     * THE MERGE IS THE POINT. A Google renewal returns a new access token and NO refresh token; writing
     * the response over the row would null the stored one. The connection would then work for one more
     * hour and need a human — with the cause a successful refresh two files away from the symptom. So a
     * null refresh token in the response means "unchanged", never "there is none". Meta connections have
     * no refresh token in the first place, so the merge is a no-op for them and correct for both.
     */
    public function storeTokens(PlatformConnection $connection, OAuthTokens $tokens): PlatformConnection
    {
        $this->applyTokens($connection, $tokens);

        $connection->last_refreshed_at = now();
        $connection->failure_code = null;
        $connection->save();

        return $connection;
    }

    /**
     * The token half of a write, shared by a connect and a renewal so the merge rule has one
     * implementation.
     *
     * Scopes follow the same rule as the refresh token and for a related reason: Meta does not echo
     * granted scopes on a renewal, and replacing the recorded list with an empty one would make a
     * successful refresh look like the user had withdrawn every permission — on the screen somebody
     * consults when a publish fails with a permission error.
     */
    private function applyTokens(PlatformConnection $connection, OAuthTokens $tokens): void
    {
        $connection->access_token = $tokens->accessToken;

        if ($tokens->refreshToken !== null) {
            $connection->refresh_token = $tokens->refreshToken;
        }

        if ($tokens->scopes !== []) {
            $connection->scopes = $tokens->scopes;
        }

        $connection->expires_at = $tokens->expiresAt;
    }

    /**
     * ONE TRANSACTION, ON THE CONNECTION THE ROWS ACTUALLY LIVE ON.
     *
     * `DB::transaction()` resolves `database.default`. A tenant-aware model in an own-database workspace
     * does not write there: {@see \App\Traits\TenantAware::getConnectionName()} routes it to `tenant`.
     * So the plain form opened — and committed — a transaction on the CENTRAL database while every
     * statement below went to the tenant one, and the atomicity this class promises three times over did
     * not exist at all for own-database customers. A half-applied hold there would leave a connection
     * marked `needs_reauth` with its queue still armed: twelve failures at nine in the morning, which is
     * the precise scenario `blocked` was invented to prevent.
     *
     * The connection name is taken from the MODEL rather than assumed, so it follows the active tenant
     * without this class knowing anything about tenancy. Same shape as
     * `KnowledgeSubjectPurgeService::apply()`, which met the same problem first.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function transaction(Closure $callback): mixed
    {
        return DB::connection((new PlatformConnection)->getConnectionName())->transaction($callback);
    }

    /**
     * The one status write. Every method above funnels through it, which is what makes "the Manager owns
     * the connection's status" structural rather than habitual.
     *
     * There is no transition TABLE here, unlike `PublicationManager`, and that absence is a decision. A
     * publication's graph encodes an irreversible act and two fences that must not be crossed; a
     * connection has three states and every pair is legitimately reachable — a revoked account can be
     * reconnected, an active one can break, a broken one can be disconnected. A table over that would be
     * a table saying "yes" to everything, which is a mechanism with nothing to enforce and a second
     * place to keep in step.
     */
    private function write(PlatformConnection $connection, PlatformConnectionStatus $to): void
    {
        $connection->status = $to;
        $connection->save();
    }

    /**
     * HOLD every publication armed on this connection.
     *
     * Only `scheduled` rows: a draft has nothing to hold, a `publishing` one is mid-flight and the state
     * machine refuses the edge anyway, and `published` is done. Deliberately NOT `failed` — the machine
     * allows `failed → blocked`, but a failed publication is already a row somebody has to look at, and
     * sweeping it into a hold would hide it behind a cause that is not necessarily its own.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * THE ROW THE DUE SWEEP CLAIMS WHILE THIS LOOP IS RUNNING
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * This selects `scheduled` rows and then writes them one at a time, and the due sweep runs every
     * minute — so a row can be claimed into `publishing` between the two. `publishing → blocked` is not
     * an edge the machine has, and holding a publication that is mid-call would be wrong even if it
     * were: it is already talking to a platform, and the hold exists to stop calls that have not started.
     *
     * The conditional write in `PublicationManager::transition()` refuses it, and that refusal is caught
     * PER ROW rather than allowed to escape — this loop runs inside {@see transaction()}, so one raced
     * publication would otherwise roll back the connection's own status write and leave a revoked token
     * marked active. One publication racing a sweep must not be able to lose the record of a revocation.
     */
    private function holdQueue(PlatformConnection $connection, string $failureCode): void
    {
        $publications = Publication::query()
            ->where('platform_connection_id', $connection->id)
            ->where('status', PublicationStatus::SCHEDULED)
            ->get();

        foreach ($publications as $publication) {
            try {
                $this->publications->block($publication, $failureCode, [
                    // The id, and nothing else. A failure context is rendered to a user and this one is
                    // about a row holding live credentials — see the publications migration.
                    'platform_connection_id' => $connection->id,
                ]);
            } catch (PublicationTransitionRefused) {
                // Claimed by the due sweep since this loop's SELECT. It is in flight; its own outcome
                // will be recorded by the worker, and the hold covers everything behind it.
                Log::info('A publication was claimed for publishing before the hold could reach it.', [
                    'publication' => $publication->id,
                    'platform_connection_id' => $connection->id,
                ]);
            }
        }
    }

    /**
     * RELEASE the publications this connection was holding, back to the moments they were armed for.
     *
     * The `whereIn` on the failure code is the load-bearing part — see HOLD_CODES. `whereNotNull` on the
     * instant is the belt: `block()` keeps `scheduled_at`, so a held row always has one, and a row that
     * somehow does not cannot be re-armed to a moment nobody chose.
     */
    private function releaseQueue(PlatformConnection $connection): void
    {
        $publications = Publication::query()
            ->where('platform_connection_id', $connection->id)
            ->where('status', PublicationStatus::BLOCKED)
            ->whereIn('failure_code', self::HOLD_CODES)
            ->whereNotNull('scheduled_at')
            ->get();

        foreach ($publications as $publication) {
            try {
                $this->publications->arm($publication, $publication->scheduled_at);
            } catch (PublicationTransitionRefused) {
                // Somebody re-armed or disarmed this one by hand between the SELECT and here. Their
                // decision is the newer one and it stands; the release covers the rest. Caught for the
                // same reason as in holdQueue(): this loop is inside a transaction that must not be
                // rolled back by one racing row.
                Log::info('A held publication had already been moved before the release could reach it.', [
                    'publication' => $publication->id,
                    'platform_connection_id' => $connection->id,
                ]);
            }
        }
    }
}
