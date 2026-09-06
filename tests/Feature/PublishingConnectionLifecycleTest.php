<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Publishing\Enums\PlatformConnectionStatus;
use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Managers\PlatformConnectionManager;
use App\Modules\Publishing\Models\PlatformConnection;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Services\OAuthStateService;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * R4 B2 — THE WHOLE LIFE OF A CONNECTION, THROUGH THE REAL ROUTES, ON `Http::fake`.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHY FAKES ARE THE HONEST INSTRUMENT HERE, AND WHAT THEY CANNOT REACH
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * The applications with Google and Meta do not exist yet — registering them is the owner's day-0 track —
 * so there is no credential in the world this suite could use. What CAN be proven now is everything on
 * our side of the wire: that the authorize URL carries the parameters without which Google issues no
 * refresh token, that a callback arriving with no authentication finds its user and its database, that a
 * failed renewal holds a queue, and that repairing the connection releases it.
 *
 * What a fake cannot establish is listed on the providers and repeated in the report: whether the scope
 * strings are the ones actually granted, whether the redirect URI matches one registered with the
 * platform byte for byte, and whether `/me` and `channels?mine=true` answer for a real business account
 * the way their documentation says. Those belong to the first real connect.
 *
 * `Http::preventStrayRequests()` is on throughout, so a code path that reached for an endpoint nobody
 * faked fails here rather than in production.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE TOKENS IN THIS FILE ARE DISTINCTIVE LITERALS ON PURPOSE
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * `fake-access-…` and `fake-refresh-…` are searched for verbatim by
 * {@see PublishingConnectionSecrecyTest}. A realistic-looking random string would make a leak assertion
 * either unreliable or unreadable.
 */
class PublishingConnectionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const ACCESS_TOKEN = 'fake-access-ya29-lifecycle-0001';

    private const REFRESH_TOKEN = 'fake-refresh-1ff-lifecycle-0001';

    private const RENEWED_ACCESS_TOKEN = 'fake-access-ya29-renewed-0002';

    private const CHANNEL_ID = 'UCtaskio_demo_channel_00';

    private User $owner;

    private Workspace $workspace;

    /** The binding cookie the authorize endpoint set — what the browser carries into the callback. */
    private string $handshake = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->workspace->users()->attach($this->owner->id);

        app(TenantContext::class)->set($this->workspace);

        // The applications do not exist yet, so every provider is unconfigured in the shipped state.
        // A test about the handshake has to say which credentials it is pretending to have — exactly
        // the discipline CLAUDE.md demands of anything that depends on a flag.
        config([
            'publishing.platforms.youtube.client_id' => 'test-google-client-id',
            'publishing.platforms.youtube.client_secret' => 'test-google-client-secret',
            'publishing.platforms.facebook.client_id' => 'test-meta-client-id',
            'publishing.platforms.facebook.client_secret' => 'test-meta-client-secret',
        ]);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * THE COMPLETION CRITERION, IN ONE TEST:
     * connect → refresh fails → needs_reauth → publications blocked → re-connect → publications released.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * Written as a single narrative rather than five tests, deliberately. Each step's meaning is the
     * state the previous one left behind, and split into independent tests the interesting half — that
     * the SAME row is repaired and the SAME publications come back to the SAME instant — would have to
     * be staged by fixtures instead of produced by the mechanism.
     */
    public function test_a_broken_connection_holds_its_queue_and_reconnecting_releases_it(): void
    {
        // ONE stub for the token endpoint, answering by GRANT — which is what the real endpoint does.
        //
        // Two reasons it is not two successive `Http::fake()` calls. First, Laravel MERGES stubs and the
        // FIRST match wins, so a later fake for the same URL is silently ignored — a trap this test fell
        // into and which would otherwise have made a "the refresh failed" scenario quietly assert that a
        // successful refresh leaves everything healthy. Second, it is more honest: `oauth2.googleapis.com/token`
        // is one endpoint serving `authorization_code` and `refresh_token`, and this scenario needs the
        // second to fail while the first keeps working.
        $refreshFails = false;
        $accessToken = self::ACCESS_TOKEN;

        Http::fake([
            'oauth2.googleapis.com/token' => function ($request) use (&$refreshFails, &$accessToken) {
                if (($request['grant_type'] ?? null) === 'refresh_token' && $refreshFails) {
                    // What Google answers once a user has revoked access — the ordinary way a
                    // connection breaks.
                    return Http::response([
                        'error' => 'invalid_grant',
                        'error_description' => 'Token has been expired or revoked.',
                    ], 400);
                }

                return Http::response([
                    'access_token' => $accessToken,
                    'refresh_token' => self::REFRESH_TOKEN,
                    'expires_in' => 3600,
                    'scope' => 'https://www.googleapis.com/auth/youtube.upload',
                    'token_type' => 'Bearer',
                ]);
            },
            'googleapis.com/youtube/v3/*' => Http::response([
                'items' => [[
                    'id' => self::CHANNEL_ID,
                    'snippet' => ['title' => 'Taskio Demo'],
                ]],
            ]),
        ]);

        // ── 1. CONNECT ────────────────────────────────────────────────────────────
        $authorizeUrl = $this->beginAuthorization();

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $authorizeUrl);

        $params = $this->queryOf($authorizeUrl);

        // THE TWO PARAMETERS WITHOUT WHICH GOOGLE ISSUES NO REFRESH TOKEN. Their absence would not fail
        // anything until an hour after the first real connect, which is why they are asserted here.
        $this->assertSame('offline', $params['access_type']);
        $this->assertSame('consent', $params['prompt']);
        $this->assertSame(route('publishing.oauth.callback', ['platform' => 'youtube']), $params['redirect_uri']);
        $this->assertNotEmpty($params['state']);

        $this->hitCallback('youtube', 'auth-code-1', $params['state'])
            ->assertRedirectContains('connection=connected');

        $connection = PlatformConnection::query()->sole();
        $connectionId = $connection->id;

        $this->assertSame(PlatformConnectionStatus::ACTIVE, $connection->status);
        $this->assertSame(self::CHANNEL_ID, $connection->external_account_id);
        $this->assertSame('Taskio Demo', $connection->account_name);
        $this->assertSame(self::ACCESS_TOKEN, $connection->credentials()->accessToken);
        $this->assertSame(self::REFRESH_TOKEN, $connection->credentials()->refreshToken);
        $this->assertNotNull($connection->expires_at);

        // ATTRIBUTED FROM THE SIGNED STATE, not from whoever the request looked like. The callback is
        // unauthenticated by construction, so this is the only thing that could have set it.
        $this->assertSame($this->owner->id, $connection->creator_id);

        // ── 2. SOMETHING IS ARMED ON IT ───────────────────────────────────────────
        $publication = Publication::factory()
            ->on(PublishingPlatform::YOUTUBE)
            ->scheduled('2026-10-01 09:00:00')
            ->create([
                'creator_id' => $this->owner->id,
                'platform_connection_id' => $connection->id,
            ]);

        // ── 3. THE RENEWAL FAILS ──────────────────────────────────────────────────
        // Brought inside the refresh lead, and the token endpoint starts refusing the refresh grant.
        $connection->forceFill(['expires_at' => now()->addHours(2)])->save();
        $refreshFails = true;

        $this->artisan('publishing:refresh-tokens')->assertSuccessful();

        $connection->refresh();

        $this->assertSame(PlatformConnectionStatus::NEEDS_REAUTH, $connection->status);
        // The COLUMN'S vocabulary, which is the one the translations render. It used to be the token
        // endpoint's (`token_refresh_failed`), which nothing could translate.
        $this->assertSame(PlatformConnectionManager::FAILURE_REFRESH_FAILED, $connection->failure_code);

        // ── 4. THE FENCE: THE QUEUE IS HELD, NOT FAILED ───────────────────────────
        $publication->refresh();

        $this->assertSame(
            PublicationStatus::BLOCKED,
            $publication->status,
            'a broken connection must HOLD its queue — the alternative is one failure per scheduled item',
        );
        $this->assertSame(PlatformConnectionManager::HOLD_NEEDS_REAUTH, $publication->failure_code);
        $this->assertSame(
            ['platform_connection_id' => $connectionId],
            $publication->failure_context,
            'the hold names the connection and nothing else — a failure context is rendered to a user',
        );

        // THE SCHEDULE SURVIVES. Without this, repairing the connection would not be enough to recover.
        $this->assertSame(
            '2026-10-01T09:00:00+00:00',
            $publication->scheduled_at->utc()->toIso8601String(),
        );

        // ── 5. RE-CONNECT ─────────────────────────────────────────────────────────
        // The `authorization_code` grant was never the one failing, so a person going back to the
        // consent screen is exactly the repair the product promises.
        $accessToken = self::RENEWED_ACCESS_TOKEN;

        $secondUrl = $this->beginAuthorization();

        $this->hitCallback('youtube', 'auth-code-2', $this->queryOf($secondUrl)['state'])
            ->assertRedirectContains('connection=connected');

        // THE SAME ROW. A second row would leave every held publication pointing at the old connection,
        // permanently blocked, with a healthy-looking connection sitting beside it.
        $this->assertSame(1, PlatformConnection::withTrashed()->count());

        $connection->refresh();

        $this->assertSame($connectionId, $connection->id);
        $this->assertSame(PlatformConnectionStatus::ACTIVE, $connection->status);
        $this->assertNull($connection->failure_code);
        $this->assertSame(self::RENEWED_ACCESS_TOKEN, $connection->credentials()->accessToken);

        // ── 6. THE QUEUE IS RELEASED, TO THE MOMENT IT WAS ARMED FOR ──────────────
        $publication->refresh();

        $this->assertSame(PublicationStatus::SCHEDULED, $publication->status);
        $this->assertNull($publication->failure_code);
        $this->assertSame(
            '2026-10-01T09:00:00+00:00',
            $publication->scheduled_at->utc()->toIso8601String(),
            'a released publication returns to the moment somebody chose, not to a new one',
        );
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * THE CHANNEL LOOKUP ACTUALLY ASKS FOR WHAT GOOGLE REQUIRES.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * This is the one property in the file that is about the URL LEAVING the application rather than
     * about what came back, and it exists because of a defect no other test here could have seen.
     *
     * `PendingRequest::get($url, $query)` decides by `func_num_args()`, so passing an EMPTY query still
     * hands Guzzle a `query` option — and Guzzle REPLACES the URL's own query rather than merging. The
     * configured `…/channels?part=snippet&mine=true` therefore went out as `…/channels`, which Google
     * answers with 400 `missingRequiredParameter`. Every real YouTube connect would have failed after a
     * successful consent and a successful token exchange: refresh token issued, grant live on the user's
     * account, nothing stored on ours. Meta escaped it only because `/me` defaults to `id,name`.
     *
     * WHY THE EXISTING FAKES COULD NOT CATCH IT: `googleapis.com/youtube/v3/*` matches the mutilated URL
     * exactly as happily as the healthy one, and the fake then answers with a channel either way. The
     * whole suite went green against a request that could not have worked. So the assertion here is on
     * what was SENT, not on what came back — the only shape of test that could have failed.
     */
    public function test_the_channel_lookup_carries_the_parameters_google_requires(): void
    {
        $this->fakeSuccessfulGoogleHandshake();

        $this->hitCallback('youtube', 'code-1', $this->queryOf($this->beginAuthorization())['state'])
            ->assertRedirectContains('connection=connected');

        Http::assertSent(function ($request): bool {
            if (!str_contains($request->url(), '/youtube/v3/channels')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['part'] ?? null) === 'snippet'
                && ($query['mine'] ?? null) === 'true';
        });

        // Not vacuous: the lookup really did happen and really did produce the connection.
        $this->assertSame(self::CHANNEL_ID, PlatformConnection::query()->sole()->external_account_id);
    }

    /**
     * A GOOGLE RENEWAL RETURNS NO REFRESH TOKEN, AND THE STORED ONE MUST SURVIVE.
     *
     * The defect this pins is quiet and expensive: writing the refresh response over the row nulls the
     * refresh token, so the connection works for one more hour and then needs a human — with the cause
     * (a SUCCESSFUL refresh) two files away from the symptom.
     */
    public function test_a_successful_google_renewal_keeps_the_refresh_token_it_was_not_given_back(): void
    {
        $connection = PlatformConnection::factory()->expiringIn(2)->create([
            'creator_id' => $this->owner->id,
            'access_token' => 'fake-access-old',
            'refresh_token' => 'fake-refresh-keepme',
        ]);

        // Exactly what Google answers to `grant_type=refresh_token`: a new access token, no refresh one.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => self::RENEWED_ACCESS_TOKEN,
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/youtube.upload',
                'token_type' => 'Bearer',
            ]),
        ]);

        $this->artisan('publishing:refresh-tokens')->assertSuccessful();

        $connection->refresh();

        $this->assertSame(PlatformConnectionStatus::ACTIVE, $connection->status);
        $this->assertSame(self::RENEWED_ACCESS_TOKEN, $connection->credentials()->accessToken);
        $this->assertSame(
            'fake-refresh-keepme',
            $connection->credentials()->refreshToken,
            'a renewal that carried no refresh token must not be read as "there is none"',
        );
    }

    /**
     * A META CONNECTION RENEWS WITH ITS ACCESS TOKEN, because it has no refresh token to renew with.
     *
     * The whole reason `OAuthProvider::refresh()` takes the credential PAIR rather than a refresh-token
     * string. A signature admitting only the latter would have made this platform inexpressible, and the
     * branch would have grown in the refresher instead.
     */
    public function test_a_meta_connection_renews_by_re_exchanging_its_own_access_token(): void
    {
        $connection = PlatformConnection::factory()
            ->meta(PublishingPlatform::FACEBOOK)
            ->expiringIn(6)
            ->create([
                'creator_id' => $this->owner->id,
                'access_token' => 'fake-access-meta-long-lived',
            ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'access_token' => 'fake-access-meta-renewed',
                'token_type' => 'bearer',
                // Meta has historically answered with the STRING form. Read as an int by the provider;
                // a strict is_int() would have recorded "no expiry" and left the sweep blind to it.
                'expires_in' => '5183944',
            ]),
        ]);

        $this->artisan('publishing:refresh-tokens')->assertSuccessful();

        $connection->refresh();

        $this->assertSame(PlatformConnectionStatus::ACTIVE, $connection->status);
        $this->assertSame('fake-access-meta-renewed', $connection->credentials()->accessToken);
        $this->assertNull($connection->credentials()->refreshToken);
        $this->assertTrue(
            $connection->expires_at->greaterThan(now()->addDays(50)),
            'a numeric-string expires_in must be understood, or a sixty-day token looks like no expiry',
        );

        // The renewal used the ACCESS token, which is the entire point of this platform's dialect.
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'fb_exchange_token=fake-access-meta-long-lived'));
    }

    /**
     * A TOKEN THAT WILL NOT DECRYPT PARKS THE CONNECTION. IT NEVER THROWS.
     *
     * The APP_KEY-rotation incident, simulated the way it actually presents: ciphertext in the column
     * that this installation's key cannot open. Written through the query builder rather than by
     * rotating the key in-process, because the encrypter is a singleton built at boot — rotating config
     * alone would not change it, and the test would pass for the wrong reason.
     *
     * Every row is affected at once when this happens, so the behaviour has to be graceful: parked and
     * held, never a 500 on the screen somebody would use to fix it, and never an exception that leaves
     * the sweep failing identically on every pass forever.
     */
    public function test_an_unreadable_credential_parks_the_connection_rather_than_throwing(): void
    {
        $connection = PlatformConnection::factory()->expiringIn(2)->create([
            'creator_id' => $this->owner->id,
        ]);

        $publication = Publication::factory()->scheduled()->create([
            'creator_id' => $this->owner->id,
            'platform_connection_id' => $connection->id,
        ]);

        DB::table('platform_connections')
            ->where('id', $connection->id)
            ->update(['access_token' => 'this-is-not-an-encrypted-payload']);

        // No Http::fake for a token endpoint: nothing may be attempted, because there is nothing to
        // send. preventStrayRequests turns a call into a failure rather than a surprise.
        $this->artisan('publishing:refresh-tokens')->assertSuccessful();

        $connection->refresh();

        $this->assertSame(PlatformConnectionStatus::NEEDS_REAUTH, $connection->status);
        $this->assertSame('credentials_unreadable', $connection->failure_code);
        $this->assertFalse($connection->hasReadableCredentials());

        $this->assertSame(PublicationStatus::BLOCKED, $publication->refresh()->status);
    }

    /**
     * A CONNECTION THE PLATFORM GAVE NO EXPIRY FOR IS LEFT ALONE.
     *
     * Null is not "expired long ago", it is unknown. Treating it as due would put the sweep in a loop
     * against a token endpoint, once per pass, forever — for a connection that may be perfectly healthy.
     */
    public function test_a_connection_with_no_stated_expiry_is_never_swept(): void
    {
        $connection = PlatformConnection::factory()->withoutExpiry()->create([
            'creator_id' => $this->owner->id,
        ]);

        // Any HTTP call at all fails the test.
        $this->artisan('publishing:refresh-tokens')->assertSuccessful();

        $this->assertSame(PlatformConnectionStatus::ACTIVE, $connection->refresh()->status);
    }

    /**
     * DISCONNECTING HOLDS THE SCHEDULED QUEUE AND KEEPS THE HISTORY.
     *
     * The decision recorded on `PlatformConnectionManager::revoke()`, asserted rather than described:
     * scheduled work is held (not deleted, not disarmed, not left to fail), a published row keeps
     * pointing at the account it went out on, and the connection row survives as a soft delete so that
     * pointer still resolves.
     */
    public function test_disconnecting_holds_the_queue_and_leaves_published_history_intact(): void
    {
        $connection = PlatformConnection::factory()->create(['creator_id' => $this->owner->id]);

        $scheduled = Publication::factory()->scheduled('2026-10-02 08:00:00')->create([
            'creator_id' => $this->owner->id,
            'platform_connection_id' => $connection->id,
        ]);

        $published = Publication::factory()->published()->create([
            'creator_id' => $this->owner->id,
            'platform_connection_id' => $connection->id,
        ]);

        $this->asOwner()
            ->deleteJson('/api/publishing/connections/' . $connection->id)
            ->assertNoContent();

        // Gone from every list, still in the database.
        $this->assertSame(0, PlatformConnection::query()->count());

        $trashed = PlatformConnection::withTrashed()->findOrFail($connection->id);

        $this->assertSame(PlatformConnectionStatus::REVOKED, $trashed->status);
        $this->assertNotNull($trashed->deleted_at);

        $scheduled->refresh();
        $this->assertSame(PublicationStatus::BLOCKED, $scheduled->status);
        $this->assertSame(PlatformConnectionManager::HOLD_DISCONNECTED, $scheduled->failure_code);
        $this->assertSame(
            '2026-10-02T08:00:00+00:00',
            $scheduled->scheduled_at->utc()->toIso8601String(),
            'a hold keeps the moment — disarming it would make reconnecting insufficient to recover',
        );

        // THE HISTORY. The post is still out there; the record of which account it went out on must not
        // have been severed.
        $published->refresh();
        $this->assertSame(PublicationStatus::PUBLISHED, $published->status);
        $this->assertSame($connection->id, $published->platform_connection_id);
    }

    /**
     * RECONNECTING AN ACCOUNT SOMEBODY DISCONNECTED RESTORES THE ORIGINAL ROW.
     *
     * The path the unique index makes non-obvious, and the reason `connect()` looks the row up
     * `withTrashed()`. A disconnect is a SOFT delete, so the row — and the
     * (workspace, platform, external_account_id) index entry — is still there. An implementation that
     * inserted a fresh row would hit a constraint violation and show a database error for the most
     * reasonable thing a user could do after disconnecting something by mistake.
     *
     * Restoring it is also what makes the publications recover: they point at the OLD id, so a second row
     * would leave them blocked forever beside a connection that looks perfectly healthy.
     */
    public function test_reconnecting_a_disconnected_account_restores_the_original_row_and_its_queue(): void
    {
        $this->fakeSuccessfulGoogleHandshake();

        $this->hitCallback('youtube', 'code-1', $this->queryOf($this->beginAuthorization())['state'])
            ->assertRedirectContains('connection=connected');

        $connection = PlatformConnection::query()->sole();
        $connectionId = $connection->id;

        $publication = Publication::factory()->scheduled('2026-11-05 07:30:00')->create([
            'creator_id' => $this->owner->id,
            'platform_connection_id' => $connection->id,
        ]);

        $this->asOwner()
            ->deleteJson('/api/publishing/connections/' . $connection->id)
            ->assertNoContent();

        $this->assertSame(PublicationStatus::BLOCKED, $publication->refresh()->status);
        $this->assertSame(PlatformConnectionManager::HOLD_DISCONNECTED, $publication->failure_code);

        // ── CONNECT THE SAME ACCOUNT AGAIN ────────────────────────────────────────
        $this->hitCallback('youtube', 'code-2', $this->queryOf($this->beginAuthorization())['state'])
            ->assertRedirectContains('connection=connected');

        $this->assertSame(1, PlatformConnection::withTrashed()->count(), 'the trashed row must be reused, not duplicated');

        $restored = PlatformConnection::query()->sole();

        $this->assertSame($connectionId, $restored->id);
        $this->assertNull($restored->deleted_at);
        $this->assertSame(PlatformConnectionStatus::ACTIVE, $restored->status);

        // A hold placed by a DISCONNECT is lifted by a reconnect — the two codes are peers.
        $publication->refresh();
        $this->assertSame(PublicationStatus::SCHEDULED, $publication->status);
        $this->assertSame(
            '2026-11-05T07:30:00+00:00',
            $publication->scheduled_at->utc()->toIso8601String(),
        );
    }

    /**
     * A HOLD PLACED FOR SOME OTHER REASON IS NOT LIFTED BY A RECONNECT.
     *
     * The `whereIn` on the failure code in `releaseQueue()`, asserted. Today nothing else blocks a
     * publication, so this is a guard for B3 — which adds holds for rate limits, moderation and withdrawn
     * approvals. Re-arming one of those on an unrelated reconnect would publish something a person had
     * deliberately stopped, and it would look exactly like the feature working.
     */
    public function test_a_hold_placed_for_another_reason_survives_a_reconnect(): void
    {
        $this->fakeSuccessfulGoogleHandshake();

        $this->hitCallback('youtube', 'code-1', $this->queryOf($this->beginAuthorization())['state']);

        $connection = PlatformConnection::query()->sole();

        $publication = Publication::factory()->scheduled('2026-11-06 07:30:00')->create([
            'creator_id' => $this->owner->id,
            'platform_connection_id' => $connection->id,
        ]);

        // A hold with a cause that is NOT this connection.
        app(\App\Modules\Publishing\Managers\PublicationManager::class)
            ->block($publication, 'awaiting_approval');

        $this->hitCallback('youtube', 'code-2', $this->queryOf($this->beginAuthorization())['state'])
            ->assertRedirectContains('connection=connected');

        $publication->refresh();

        $this->assertSame(
            PublicationStatus::BLOCKED,
            $publication->status,
            'a reconnect must lift only the holds it placed',
        );
        $this->assertSame('awaiting_approval', $publication->failure_code);
    }

    /**
     * THE `restrict` FOREIGN KEY, ASSERTED.
     *
     * A soft delete never reaches it, which is why nothing in normal operation tests this. What it
     * catches is the other path — a purge, a console force-delete, a retention job — where it turns a
     * silent loss of the record of public artifacts into a refusal somebody has to decide about.
     */
    public function test_a_connection_with_publications_cannot_be_erased(): void
    {
        $connection = PlatformConnection::factory()->create(['creator_id' => $this->owner->id]);

        Publication::factory()->published()->create([
            'creator_id' => $this->owner->id,
            'platform_connection_id' => $connection->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $connection->forceDelete();
    }

    /**
     * TWO PUBLICATIONS CANNOT CLAIM ONE ARTIFACT ON ONE CONNECTION.
     *
     * The database-level half of the module's founding doctrine. Per connection, never global: the same
     * identifier on two channels is two different artifacts, and a global index would refuse a
     * legitimate publication the first time two accounts collided.
     */
    public function test_the_same_remote_artifact_cannot_be_recorded_twice_on_one_connection(): void
    {
        $connection = PlatformConnection::factory()->create(['creator_id' => $this->owner->id]);

        Publication::factory()->published()->create([
            'creator_id' => $this->owner->id,
            'platform_connection_id' => $connection->id,
            'remote_id' => 'yt_the_one_video',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Publication::factory()->published()->create([
            'creator_id' => $this->owner->id,
            'platform_connection_id' => $connection->id,
            'remote_id' => 'yt_the_one_video',
        ]);
    }

    /**
     * THE SAME ARTIFACT ID ON A DIFFERENT CONNECTION IS A DIFFERENT ARTIFACT.
     *
     * The other half of the sentence above, and the reason the index is not global.
     */
    public function test_the_same_artifact_id_on_another_connection_is_allowed(): void
    {
        $first = PlatformConnection::factory()->create(['creator_id' => $this->owner->id]);
        $second = PlatformConnection::factory()->create([
            'creator_id' => $this->owner->id,
            'external_account_id' => 'UCsecond_channel_000000',
        ]);

        foreach ([$first, $second] as $connection) {
            Publication::factory()->published()->create([
                'creator_id' => $this->owner->id,
                'platform_connection_id' => $connection->id,
                'remote_id' => 'yt_same_id_two_channels',
            ]);
        }

        $this->assertSame(2, Publication::query()->where('remote_id', 'yt_same_id_two_channels')->count());
    }

    /** `dry_run` publishes nothing and has no account. Asking to connect one says so. */
    public function test_the_rehearsal_destination_cannot_be_connected(): void
    {
        $this->asOwner()
            ->postJson('/api/publishing/connections/dry_run/authorize')
            ->assertStatus(422)
            ->assertJsonValidationErrors('platform');
    }

    /**
     * AN UNCONFIGURED PLATFORM IS REFUSED AT THE DOOR, NOT AT THE CONSENT SCREEN.
     *
     * This is the SHIPPED state of every real destination until the owner registers the applications.
     * Sending somebody to Google with an empty `client_id` produces Google's own error page, in Google's
     * language, with no way back into our flow and nothing on our side that noticed.
     */
    public function test_a_platform_without_credentials_is_refused_before_any_redirect(): void
    {
        config(['publishing.platforms.youtube.client_id' => null]);

        $this->asOwner()
            ->postJson('/api/publishing/connections/youtube/authorize')
            ->assertStatus(422)
            ->assertJsonValidationErrors('platform');
    }

    /** A member who did not create a connection cannot disconnect it; the workspace owner always can. */
    public function test_disconnecting_is_the_creator_or_the_workspace_owner(): void
    {
        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id);

        $connection = PlatformConnection::factory()->create(['creator_id' => $this->owner->id]);

        $this->actingAs($member)
            ->withHeader('X-Workspace-Id', $this->workspace->id)
            ->deleteJson('/api/publishing/connections/' . $connection->id)
            ->assertForbidden();

        $this->assertSame(PlatformConnectionStatus::ACTIVE, $connection->refresh()->status);

        $this->asOwner()
            ->deleteJson('/api/publishing/connections/' . $connection->id)
            ->assertNoContent();
    }

    /**
     * A PUBLICATION MAY NOT NAME A CONNECTION THAT SERVES SOMEWHERE ELSE.
     *
     * The rule B1 left a note for. It would pass every schema constraint and fail at publish time, on a
     * schedule, having looked correct on every screen in between.
     */
    public function test_a_publication_cannot_be_pointed_at_a_connection_for_another_platform(): void
    {
        $facebook = PlatformConnection::factory()->meta()->create(['creator_id' => $this->owner->id]);

        $this->asOwner()
            ->postJson('/api/publishing/publications', [
                'title' => 'Wrong destination',
                'platform' => 'youtube',
                'platform_connection_id' => $facebook->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('platform_connection_id');
    }

    /** Nor one that is broken: arming onto it would create something immediately eligible to be held. */
    public function test_a_publication_cannot_be_pointed_at_a_connection_that_needs_reauth(): void
    {
        $broken = PlatformConnection::factory()->needsReauth()->create(['creator_id' => $this->owner->id]);

        $this->asOwner()
            ->postJson('/api/publishing/publications', [
                'title' => 'Broken destination',
                'platform' => 'youtube',
                'platform_connection_id' => $broken->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('platform_connection_id');
    }

    /** And a healthy, matching one is accepted — so the three refusals above are not vacuous. */
    public function test_a_publication_may_name_a_usable_connection_for_its_own_platform(): void
    {
        $connection = PlatformConnection::factory()->create(['creator_id' => $this->owner->id]);

        $this->asOwner()
            ->postJson('/api/publishing/publications', [
                'title' => 'Right destination',
                'platform' => 'youtube',
                'platform_connection_id' => $connection->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.platform_connection_id', $connection->id);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────

    private function asOwner(): self
    {
        $this->actingAs($this->owner)->withHeader('X-Workspace-Id', $this->workspace->id);

        return $this;
    }

    /**
     * Start a handshake through the real endpoint and hand back the URL it minted.
     *
     * It also keeps the BROWSER BINDING the endpoint set, because that is what a browser does and
     * because the callback now refuses a handshake without it. Read undecrypted: the cookie is exempt
     * from `EncryptCookies` so that it can cross from the `api` group that sets it to the `web` group
     * that reads it — see `OAuthStateService::HANDSHAKE_COOKIE`.
     */
    private function beginAuthorization(string $platform = 'youtube'): string
    {
        $response = $this->asOwner()
            ->postJson('/api/publishing/connections/' . $platform . '/authorize')
            ->assertOk();

        $cookie = $response->getCookie(OAuthStateService::HANDSHAKE_COOKIE, decrypt: false);

        $this->assertNotNull($cookie, 'the authorize endpoint must bind the handshake to this browser');

        $this->handshake = (string) $cookie->getValue();

        return $response->json('data.authorize_url');
    }

    /**
     * The callback, as the platform makes it: a plain GET with no headers of ours.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * IT RE-ESTABLISHES THE TEST'S TENANT CONTEXT AFTERWARDS, AND THAT IS NOT A WORKAROUND
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * The callback controller CLEARS the tenant context in a `finally`, on purpose: it activates a
     * tenant by hand (no `ResolveWorkspace` on this route) and a tenant connection left configured would
     * be inherited by whatever a long-lived runtime handles next.
     *
     * In production that costs nothing — the next request resolves its own. In this suite the context
     * is a process-wide singleton that `setUp()` filled, so a callback silently wipes it, and every
     * `Model::factory()->create()` after that point stamps a NULL `workspace_id`. The row is then
     * invisible to every workspace-scoped query, which presents as "the release did nothing" three
     * assertions later and has nothing to do with the mechanism under test.
     *
     * This restores what the middleware would have restored, so the rest of a scenario runs as a
     * logged-in workspace member would experience it.
     */
    private function hitCallback(string $platform, string $code, string $state)
    {
        $response = $this
            ->withUnencryptedCookie(OAuthStateService::HANDSHAKE_COOKIE, $this->handshake)
            ->get('/oauth/' . $platform . '/callback?' . http_build_query([
                'code' => $code,
                'state' => $state,
            ]));

        app(TenantContext::class)->set($this->workspace);

        return $response;
    }

    /** @return array<string, string> */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function fakeSuccessfulGoogleHandshake(string $accessToken = self::ACCESS_TOKEN): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => $accessToken,
                'refresh_token' => self::REFRESH_TOKEN,
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly',
                'token_type' => 'Bearer',
            ]),
            'googleapis.com/youtube/v3/*' => Http::response([
                'items' => [[
                    'id' => self::CHANNEL_ID,
                    'snippet' => ['title' => 'Taskio Demo'],
                ]],
            ]),
        ]);
    }
}
