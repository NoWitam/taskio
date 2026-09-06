<?php

namespace App\Modules\Publishing\Models;

use App\Models\AbstractModel;
use App\Modules\Publishing\DTOs\PlatformCredentials;
use App\Modules\Publishing\Enums\PlatformConnectionStatus;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Exceptions\CredentialsUnreadable;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A PLATFORM CONNECTION — one account this workspace has been authorized to publish through.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THIS ROW HOLDS SOMEBODY ELSE'S CREDENTIALS. EVERYTHING BELOW FOLLOWS FROM THAT.
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Not a password we chose, not a session we issued: an access token that lets this application post
 * publicly as a real channel or page. A leak is not an incident about our users' data, it is the ability
 * to speak as them.
 *
 * So the two token columns are `encrypted` casts — the shape `workspaces.db_password` established, and
 * the ONLY encryption precedent in this repository. The cast is what makes the protection unforgettable
 * rather than diligent: the ciphertext is what lives in the attribute, so it is the ciphertext that goes
 * into the query, into the query log (`LogMiddleware` interpolates every statement's values), into a
 * backup and into a replica. A helper called at each write site would have protected the writes somebody
 * remembered.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `$hidden`, AND WHY IT IS NOT REDUNDANT WITH "WE HAVE A RESOURCE FOR THAT"
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * {@see \App\Modules\Publishing\Http\Resources\PlatformConnectionResource} lists the fields it emits,
 * so no token can reach the API through it. `$hidden` covers every OTHER way a model becomes text: a
 * `->toArray()` in a log context, a model dropped whole into an exception's context array, a `dd()` in
 * a debugging session, a future endpoint written in a hurry. Each of those is a single line that looks
 * harmless. The two mechanisms are cheap and independent, and this is the table to spend that on.
 *
 * Note what `$hidden` does NOT do: it does not stop {@see credentials()}, which is the deliberate,
 * named way to read them.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE MANAGER OWNS `status`. THIS MODEL DOES NOT.
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The same arrangement `Publication` has, and for a sharper reason: this status is not merely a label,
 * it holds or releases the queue. A service that set it to `active` on its own would silently un-block
 * a queue of publications for a connection that does not work, and they would go out — or fail — on a
 * schedule. {@see \App\Modules\Publishing\Managers\PlatformConnectionManager} is the only writer, and
 * that is asserted over the module's file bytes by `PublishingStateMachineTest`.
 *
 * AND IT IS NOT FILLABLE, which is where this model now differs from `Publication`. The byte scan is
 * LITERAL: it looks for the column's name spelled out beside a write. It therefore cannot see the one
 * door that spells nothing out — an update handed a whole validated array — because the column's name is
 * in the REQUEST rather than in the source. That is exactly the shape a future endpoint would reach for,
 * so the second guard is the one that catches it: leave the column out of the list below and mass
 * assignment cannot reach it at all. Direct attribute writes, which is what the Manager does, are not
 * governed by that list and are unaffected.
 *
 * Nothing in the application mass-assigns it today; this is a door closed before somebody opens it, not a
 * defect repaired. The one place that legitimately needs to START a connection in a given state is
 * `PlatformConnectionFactory`, which force-fills for that reason and says so.
 *
 * @property PlatformConnectionStatus $status
 * @property PublishingPlatform $platform
 * @property array<int, string> $scopes
 */
class PlatformConnection extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, SoftDeletes, TenantAware;

    protected $table = 'platform_connections';

    protected $fillable = [
        'platform',
        'external_account_id',
        'account_name',
        'access_token',
        'refresh_token',
        'scopes',
        'expires_at',
        // `status` IS DELIBERATELY ABSENT. The Manager assigns it directly; nothing may mass-assign it.
        // See the class docblock.
        'last_refreshed_at',
        'failure_code',
        'creator_id',
    ];

    /**
     * BOTH TOKENS, ALWAYS. The last line of defence against a model that becomes JSON somewhere nobody
     * audited — see the class docblock for why this is kept alongside the Resource rather than instead
     * of it.
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected $casts = [
        'platform' => PublishingPlatform::class,
        'status' => PlatformConnectionStatus::class,
        // THE TWO SENSITIVE COLUMNS. Copied from workspaces.db_password, the one existing precedent.
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'scopes' => 'array',
        'expires_at' => 'datetime',
        'last_refreshed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The publications routed through this account.
     *
     * The inverse of a `restrict` foreign key, so this relation is also the reason a connection cannot
     * be hard-deleted while it is non-empty. A disconnect soft-deletes instead, and these rows keep
     * resolving to it — which is the point: they are the record of artifacts that still exist in public.
     */
    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }

    /**
     * THE ONE WAY TO READ THE CREDENTIALS, and the only place a decryption failure is turned into
     * something the application can act on.
     *
     * Reading `$connection->access_token` directly throws {@see DecryptException} — a raw framework
     * exception that, left alone, becomes a 500 on a list screen or a stack trace in a worker log. It
     * has one real cause and it is not exotic: APP_KEY was rotated, or this database was restored beside
     * a different one. Every row is then unreadable at once, which is the moment the application must
     * behave WELL rather than loudly.
     *
     * So it is translated into {@see CredentialsUnreadable}, which the refresher catches and answers by
     * sending the connection to `needs_reauth` — the honest state, and the one a person can fix by
     * re-connecting. Never a 500, and never a silent skip that would leave a connection looking healthy
     * and publishing nothing.
     *
     * @throws CredentialsUnreadable when the ciphertext cannot be opened with this installation's key
     */
    public function credentials(): PlatformCredentials
    {
        try {
            return new PlatformCredentials(
                accessToken: (string) $this->access_token,
                refreshToken: $this->refresh_token !== null ? (string) $this->refresh_token : null,
            );
        } catch (DecryptException $e) {
            throw CredentialsUnreadable::for($this->id, $e);
        }
    }

    /**
     * Whether the stored credentials can still be opened at all.
     *
     * Its one caller is the API resource, which must be able to render a list of connections on an
     * installation whose key was rotated — a screen that 500s is a screen nobody can use to fix the
     * problem it is reporting.
     */
    public function hasReadableCredentials(): bool
    {
        try {
            $this->credentials();

            return true;
        } catch (CredentialsUnreadable) {
            return false;
        }
    }

    /**
     * Everything a publication may actually go out on.
     *
     * A scope rather than a `where` spelled at each call site, so "usable" has one definition. Soft
     * deletes are excluded by the trait, so a disconnected account is out of this set without the scope
     * having to say so.
     */
    public function scopeUsable(Builder $query): void
    {
        $query->where('status', PlatformConnectionStatus::ACTIVE);
    }

    /**
     * Connections whose access token expires within `$lead` seconds — the refresh sweep's selection.
     *
     * `whereNotNull('expires_at')` is load-bearing, not tidiness. A platform that never told us when its
     * token expires leaves a null, and a null compared against a timestamp is not "long ago" — it is
     * unknown. Treating it as due would put the sweep in a loop against a token endpoint for a
     * connection that may be perfectly healthy, once per pass, forever.
     */
    public function scopeRefreshDue(Builder $query, int $lead): void
    {
        $query->usable()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addSeconds($lead));
    }

    protected static function newFactory()
    {
        return \Database\Factories\PlatformConnectionFactory::new();
    }
}
