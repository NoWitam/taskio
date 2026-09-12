<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Approvals\Models\ApprovalProcess;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Publishing\DTOs\OAuthTokens;
use App\Modules\Publishing\DTOs\RemoteAccount;
use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Mail\PublicationFailedMail;
use App\Modules\Publishing\Managers\PlatformConnectionManager;
use App\Modules\Publishing\Managers\PublicationManager;
use App\Modules\Publishing\Models\PlatformConnection;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Models\PublicationAttempt;
use App\Modules\Publishing\Services\OAuthStateService;
use App\Modules\Publishing\Services\PublicationPublisher;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Modules\Workspaces\Services\WorkspaceProvisioner;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * THE WHOLE PUBLISHING MODULE, ONCE, ON AN OWN-DATABASE WORKSPACE. Guarded behind TENANT_DB_TESTS=1 (it
 * issues CREATE DATABASE / DROP DATABASE), mirroring {@see CalendarTenantDatabaseTest} — which it
 * deliberately does not extend, so each can be run alone.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * STATUS AT THE TIME OF WRITING: WRITTEN, NOT YET RUN — STILL TRUE AFTER B2
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The owner has not yet approved `CREATE DATABASE` / `DROP DATABASE` for this chapter, so this file has
 * never had a green run. It ships anyway, and the reason is the one CLAUDE.md already names: the default
 * suite still LOADS this class, so a parse error or a dead import is caught, while a method body
 * scripting a contract that has moved on is NOT. Writing it now means the shared-mode twin and the
 * own-database twin were authored against the same contract on the same day, which is the only moment
 * they are ever guaranteed to agree.
 *
 * B3 ADDED TWO MORE, AND THEY ARE THE FIRST ONES WHERE A MIS-ROUTED QUERY COSTS A PUBLIC ARTIFACT.
 * Everything before them was about where data is STORED; the queue is about what leaves the building.
 * The due sweep must reach a tenant database or an own-database customer's publications never go out at
 * all, and the reconciliation probe must read the TENANT trail — a probe answering from the central one
 * would report "proven absent" about a post that exists, mark the row `failed`, and thereby authorise a
 * retry that publishes it twice. Neither failure is visible in shared mode, where there is only one
 * place for a query to land.
 *
 * D4 ADDED THE FIRST ONE THAT READS BOTH DATABASES AT ONCE. The failed-publication letter takes its
 * CONTENT from the tenant row and its ADDRESS from the central `users`/`workspaces` pair — tenancy is
 * central in both db_modes — so it is the one place in this module where getting the connection wrong
 * does not mean "no data" but "the wrong half". See
 * {@see test_the_failure_letter_is_written_from_the_tenant_row}.
 *
 * B2 ADDED THREE MORE OF THEM, and they are the ones this whole arrangement was really for. A workspace
 * that pays for its own database is buying "our data is in our database"; `platform_connections` holds
 * ACCESS TOKENS FOR THEIR ACCOUNTS, so it is the single table where that promise matters most and the
 * one place where a mis-routed write is not a bug report but a broken product claim. The callback makes
 * that routing decision from a SIGNED STATE with no middleware helping it, which is exactly the kind of
 * hand-rolled tenancy that fails silently in shared mode because there is only one place for a query to
 * land.
 *
 * BEFORE COMMITTING ANY CHANGE TO THIS MODULE'S PERSISTENCE, run it by hand:
 *
 *     TENANT_DB_TESTS=1 php artisan test --filter=PublishingTenantDatabaseTest
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY PUBLISHING IN PARTICULAR NEEDS THIS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Two tenant tables, a model that reads them both, a calendar source that resolves its own connection
 * through the tenancy layer, and a state machine that writes on every step. That is the exact surface
 * that fails SILENTLY in own-database mode: in shared mode every query lands in the one place there is,
 * so nothing about the routing is ever exercised.
 *
 * And the consequence here is worse than a blank grid. A `publication_attempts` write that landed in the
 * CENTRAL database while the publication lived in the tenant one would make `findExisting()` unable to
 * see its own evidence — which is the reconciliation, blinded, on exactly the customers nobody tests on.
 * That is the scenario {@see test_the_attempt_trail_and_its_publication_live_in_the_same_database}
 * exists for.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE STRONGEST ASSERTIONS HERE ARE THE NEGATIVE ONES
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Central DECOY rows are planted carrying this very workspace's id — precisely the rows a mis-routed
 * query would find. Without them, a source reading the central database would still make every positive
 * assertion pass: the data would be found, because it would be found in the wrong place.
 *
 * NOTE: no RefreshDatabase — the provisioning DDL cannot run inside the suite transaction — so every
 * central row this file creates is removed by hand in tearDown.
 */
class PublishingTenantDatabaseTest extends TestCase
{
    /** Not the application default, so a fallback can never look like a success. */
    private const WORKSPACE_TIMEZONE = 'Europe/Warsaw';

    private const WINDOW_FROM = '2026-09-01';

    private const WINDOW_TO = '2026-09-30';

    private ?Workspace $workspace = null;

    private ?User $user = null;

    private ?string $tenantDatabase = null;

    /** Central decoy ids, deleted by hand in tearDown. */
    private array $centralDecoyIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (env('TENANT_DB_TESTS') !== '1') {
            $this->markTestSkipped('Set TENANT_DB_TESTS=1 to run real tenant-database provisioning (issues DDL).');
        }

        if (config('database.connections.' . config('database.default') . '.driver') !== 'pgsql') {
            $this->markTestSkipped('Tenant-database provisioning requires the pgsql driver.');
        }

        config(['app.timezone' => 'UTC']);
        $this->assertNotSame(self::WORKSPACE_TIMEZONE, config('app.timezone'));
    }

    /**
     * The schema half: both tenant tables exist, neither carries the per-row scoping column, and each
     * matches central EXACTLY apart from it.
     *
     * The parity is compared rather than spot-checked, for the reason the calendar's twin gives: the
     * same models read both databases, so anything present on one side only is a defect visible to
     * exactly one workspace — and invisible to everybody testing on the other.
     */
    public function test_the_tenant_schema_mirrors_central_minus_the_workspace_column(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $schema = Schema::connection(TenantManager::CONNECTION);

        foreach (['publications', 'publication_attempts', 'platform_connections'] as $table) {
            $this->assertTrue($schema->hasTable($table), "the tenant database must have {$table}");

            $this->assertFalse(
                $schema->hasColumn($table, 'workspace_id'),
                "one tenant database is one workspace — {$table}.workspace_id must not exist there",
            );

            $central = collect(Schema::connection(config('database.default'))->getColumnListing($table))
                ->reject(fn (string $column): bool => $column === 'workspace_id')
                ->sort()
                ->values()
                ->all();

            $tenant = collect($schema->getColumnListing($table))->sort()->values()->all();

            $this->assertSame($central, $tenant, "the tenant {$table} mirror must match central minus workspace_id");
        }

        // THE TWO IDEMPOTENCY COLUMNS, named individually. A tenant mirror missing either would make
        // every publish on that workspace unable to resume — and would do it silently, producing
        // duplicate containers for exactly one customer.
        foreach (['remote_id', 'remote_draft_id', 'platform_connection_id', 'scheduled_at', 'media'] as $column) {
            $this->assertTrue(
                $schema->hasColumn('publications', $column),
                "expected the tenant publications.{$column} column to exist",
            );
        }

        // Append-only, on both sides: a trail row is never amended, so there is no updated_at to mirror.
        $this->assertFalse(
            $schema->hasColumn('publication_attempts', 'updated_at'),
            'the attempt trail is append-only — an updated_at would invite amending evidence',
        );

        // B2. The two credential columns, named individually. A tenant mirror missing either would make
        // every connect on that workspace fail at the insert — loudly, at least — but a mirror missing
        // `refresh_token` specifically would work perfectly for Meta and break only Google, and only an
        // hour after the first connect.
        foreach (['access_token', 'refresh_token', 'external_account_id', 'expires_at', 'status'] as $column) {
            $this->assertTrue(
                $schema->hasColumn('platform_connections', $column),
                "expected the tenant platform_connections.{$column} column to exist",
            );
        }
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * THE CALLBACK CHOOSES A DATABASE FROM A SIGNED STATE, WITH NO MIDDLEWARE HELPING IT.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * THE MOST IMPORTANT TEST IN THIS FILE, and the reason B2's tenancy is worth exercising for real.
     *
     * Every other write in the application reaches its tenant through `ResolveWorkspace`, which reads a
     * header. The OAuth callback has no header — it is a browser redirect from Google — so it activates
     * the tenant BY HAND from the workspace id inside the signed state. Hand-rolled tenancy is precisely
     * what shared mode cannot test: there, every query lands in the one database there is, so a callback
     * that never activated anything would look perfect.
     *
     * The failure this guards against is not a blank screen. It is a customer's ACCESS TOKENS written
     * into the shared database while they are paying for their own — which is not a defect report, it is
     * a false statement about where their data lives.
     */
    public function test_a_connection_made_through_the_callback_lands_in_the_tenant_database_only(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        config([
            'publishing.platforms.youtube.client_id' => 'test-google-client-id',
            'publishing.platforms.youtube.client_secret' => 'test-google-client-secret',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fake-access-tenant-only',
                'refresh_token' => 'fake-refresh-tenant-only',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/youtube.upload',
            ]),
            'googleapis.com/youtube/v3/*' => Http::response([
                'items' => [['id' => 'UCtenant_only_channel', 'snippet' => ['title' => 'TENANT channel']]],
            ]),
        ]);

        $authorization = $this->asUser()
            ->postJson('/api/publishing/connections/youtube/authorize')
            ->assertOk();

        // The BROWSER BINDING the authorize endpoint set. Sent back unencrypted, because the cookie is
        // exempt from `EncryptCookies` so that it can cross from the `api` group to the `web` one — see
        // OAuthStateService::HANDSHAKE_COOKIE.
        $handshake = (string) $authorization->getCookie(OAuthStateService::HANDSHAKE_COOKIE, decrypt: false)?->getValue();

        parse_str((string) parse_url((string) $authorization->json('data.authorize_url'), PHP_URL_QUERY), $query);

        // No headers of ours. The workspace arrives only in `state`.
        $this->withUnencryptedCookie(OAuthStateService::HANDSHAKE_COOKIE, $handshake)
            ->get('/oauth/youtube/callback?' . http_build_query([
                'code' => 'tenant-code',
                'state' => $query['state'],
            ]))->assertRedirectContains('connection=connected');

        $this->useTenant();

        $connection = PlatformConnection::query()->sole();

        $this->assertSame('UCtenant_only_channel', $connection->external_account_id);
        $this->assertSame('fake-access-tenant-only', $connection->credentials()->accessToken);

        // ── THE NEGATIVE ──────────────────────────────────────────────────────────
        $central = DB::connection(config('database.default'));

        $this->assertSame(
            0,
            (int) $central->table('platform_connections')->where('id', $connection->id)->count(),
            'an own-database workspace\'s ACCESS TOKENS must never be written to the central database',
        );
        $this->assertSame(
            0,
            (int) $central->table('platform_connections')->where('workspace_id', $this->workspace->id)->count(),
            'nor any connection scoped to it — this is the assertion a callback that skipped activate() would fail',
        );
    }

    /**
     * THE HOLD AND THE RELEASE HAPPEN INSIDE THE TENANT DATABASE.
     *
     * The connection and the publications it holds must be read and written on the same connection. A
     * split would be quiet and bad in a specific way: the connection would park correctly and the
     * publications would stay `scheduled`, so the fence would be silently absent for exactly the
     * customers nobody tests on — and they would get the twelve-failures-at-nine-o'clock morning the
     * `blocked` state exists to prevent.
     */
    public function test_the_hold_and_release_cascade_inside_the_tenant_database(): void
    {
        $this->provisionOwnDatabaseWorkspace();
        $this->useTenant();

        $connection = PlatformConnection::factory()->create();

        $publication = Publication::factory()->scheduled('2026-09-10 09:00:00')->create([
            'title' => 'TENANT publication',
            'creator_id' => $this->user->id,
            'platform_connection_id' => $connection->id,
        ]);

        $manager = app(PlatformConnectionManager::class);

        // The COLUMN'S vocabulary, from the Manager that owns it. It was the literal
        // `token_refresh_failed` here — the token endpoint's code, which no translation could render.
        $manager->markNeedsReauth($connection, PlatformConnectionManager::FAILURE_REFRESH_FAILED);

        $this->assertSame(PublicationStatus::BLOCKED, $publication->fresh()->status);
        $this->assertSame(PlatformConnectionManager::HOLD_NEEDS_REAUTH, $publication->fresh()->failure_code);

        $manager->connect(
            platform: $connection->platform,
            account: RemoteAccount::make($connection->external_account_id, 'TENANT channel'),
            tokens: OAuthTokens::make('fake-access-tenant-repaired', 'fake-refresh-tenant-repaired', 3600),
            creatorId: $this->user->id,
        );

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::SCHEDULED, $fresh->status);
        $this->assertSame('2026-09-10T09:00:00+00:00', $fresh->scheduled_at->utc()->toIso8601String());

        // ── THE NEGATIVE ──────────────────────────────────────────────────────────
        $this->assertSame(
            0,
            (int) DB::connection(config('database.default'))
                ->table('platform_connections')
                ->where('workspace_id', $this->workspace->id)
                ->count(),
        );
    }

    /**
     * THE SCHEDULED SWEEP VISITS OWN-DATABASE WORKSPACES ONE AT A TIME.
     *
     * The command's shared pass runs unscoped, which covers every shared workspace at once and NOTHING
     * in a tenant database — those have to be visited individually. A sweep that only did the shared
     * pass would leave own-database customers' tokens to expire in silence, which for a Meta connection
     * is unrecoverable: after sixty days there is nothing left to exchange.
     */
    public function test_the_refresh_sweep_reaches_an_own_database_workspace(): void
    {
        $this->provisionOwnDatabaseWorkspace();
        $this->useTenant();

        $connection = PlatformConnection::factory()->expiringIn(2)->create();

        app(TenantContext::class)->clear();
        app(TenantManager::class)->forget();

        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fake-access-tenant-renewed',
                'expires_in' => 3600,
            ]),
        ]);

        $this->artisan('publishing:refresh-tokens')->assertSuccessful();

        $this->useTenant();

        $this->assertSame(
            'fake-access-tenant-renewed',
            $connection->fresh()->credentials()->accessToken,
            'the sweep never reached the tenant database',
        );
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * B3 — THE DUE SWEEP CLAIMS, DISPATCHES AND PUBLISHES INSIDE THE TENANT DATABASE.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * The sweep's SHARED pass runs deliberately unscoped, which covers every shared workspace at once
     * and NOTHING in a tenant database — those have to be visited one at a time. A sweep that only did
     * the shared pass would leave own-database customers' publications armed for moments that pass, for
     * ever, with somebody waiting for a post that no machinery is ever going to send.
     *
     * The sharper half is what the JOB has to carry. `QueueTenancy` stamps the DISPATCHING context onto
     * a job, and in the shared pass that context is empty by design — so `PublishPublicationJob` takes
     * the workspace as an explicit constructor argument and re-establishes it on the worker. In own-
     * database mode there is no `workspace_id` column on the row at all, so the id can only have come
     * from the ACTIVE context at claim time. This test is the one that proves that path: get it wrong
     * and the worker publishes with no tenant configured, reading and writing the CENTRAL tables.
     *
     * The queue connection is `sync` under test, so the job really runs inside the command.
     */
    public function test_the_due_sweep_publishes_an_own_database_workspaces_publication(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        // A CENTRAL decoy, due, scoped to this very workspace — the row a mis-routed sweep would claim
        // and publish instead. Without it a sweep reading the wrong database would still make every
        // positive assertion below pass.
        $this->plantCentralPublicationDecoy();

        $this->useTenant();

        $publication = Publication::factory()->scheduled('2026-09-10 09:00:00')->create([
            'title' => 'TENANT publication',
            'creator_id' => $this->user->id,
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 09:01:00', 'UTC'));

        // As the scheduler runs it: no tenant configured, nothing but the command.
        app(TenantContext::class)->clear();
        app(TenantManager::class)->forget();

        $this->artisan('publishing:dispatch-due')->assertSuccessful();

        $this->useTenant();

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::PUBLISHED, $fresh->status, 'the sweep never reached the tenant database');
        $this->assertTrue($fresh->isPublicArtifact());
        $this->assertSame(1, $fresh->attempts, 'the sweep claimed once; the job must not claim again');

        // Both phases are on record BESIDE their subject — reconciliation reads this trail.
        $this->assertSame(
            2,
            PublicationAttempt::query()->where('publication_id', $publication->id)->count(),
        );

        // ── THE NEGATIVE ──────────────────────────────────────────────────────────
        $central = DB::connection(config('database.default'));

        $this->assertSame(
            0,
            (int) $central->table('publication_attempts')->where('publication_id', $publication->id)->count(),
            'a tenant publication\'s attempt trail must not be written to the central database',
        );

        // AND THE DECOY WAS NOT PUBLISHED. A sweep that read central would have claimed it — and for a
        // real destination that is a post going out on somebody else\'s account.
        $this->assertSame(
            'scheduled',
            (string) $central->table('publications')
                ->where('workspace_id', $this->workspace->id)
                ->value('status'),
            'a CENTRAL publication was claimed while sweeping an own-database workspace',
        );
    }

    /**
     * B6 — AN APPROVAL ARMS THE PUBLICATION INSIDE THE TENANT DATABASE.
     *
     * The new seam of the B6 fix round, in the mode that made it a finding: `ApprovalService::decide()`
     * opens its transaction on the DEFAULT connection, while for an own-database workspace both the
     * process and the publication live on the TENANT connection. The arming is deferred with
     * `DB::afterCommit()` so a rolled-back decision can never leave an armed row behind — and this
     * scenario pins the half that only exists in own-database mode: the deferred effect must land in
     * the TENANT database, with nothing written centrally by any part of the decide→arm chain.
     */
    public function test_an_approval_arms_the_publication_inside_the_tenant_database(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $this->useTenant();

        $pipeline = ApprovalPipeline::factory()->create(['creator_id' => $this->user->id]);

        $pipeline->stages()->create([
            'name' => 'Review',
            'icon' => 'check-circle',
            'description' => null,
            'approver_type' => 'user',
            'approver_id' => $this->user->id,
            'order' => 1,
        ]);

        $armAt = CarbonImmutable::parse('2099-04-01 07:00:00', 'UTC');

        $publication = Publication::factory()->create([
            'title' => 'TENANT reviewed publication',
            'creator_id' => $this->user->id,
            'approval_pipeline_id' => $pipeline->id,
            'arm_on_approval_at' => $armAt,
        ]);

        app(ApprovalService::class)->startProcess($publication, $this->user);

        app(ApprovalService::class)->decide(
            $publication->fresh()->pendingApprovalProcess,
            ApprovalProcessStatus::Approved,
        );

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::SCHEDULED, $fresh->status, 'the approval must arm the TENANT row');
        $this->assertSame($armAt->toIso8601String(), $fresh->scheduled_at->utc()->toIso8601String());
        $this->assertNull($fresh->arm_on_approval_at, 'the intent is consumed in the tenant database too');

        // ONE row: `decide()` updates the pending process in place, and `advance()` inserts a second
        // one only when the pipeline has a next stage — this one does not. (The first draft of this
        // scenario asserted 2 and would have failed its very first gated run; measured in shared mode
        // during the B6 re-review.)
        $decided = ApprovalProcess::query()->where('approvable_id', $publication->id)->get();

        $this->assertCount(1, $decided, 'a one-stage review is one process row, decided in place');
        $this->assertSame(ApprovalProcessStatus::Approved, $decided->sole()->status);

        // ── THE NEGATIVE ──────────────────────────────────────────────────────────
        $central = DB::connection(config('database.default'));

        $this->assertSame(
            0,
            (int) $central->table('publications')->where('workspace_id', $this->workspace->id)->count(),
            'no part of the decide→arm chain may write a publication centrally',
        );

        $this->assertSame(
            0,
            (int) $central->table('approval_processes')->where('approvable_id', $publication->id)->count(),
            'the approval trail of a tenant publication must not leak to the central database',
        );
    }

    /**
     * B3 — THE REAPER AND THE PROBE BOTH WORK INSIDE THE TENANT DATABASE.
     *
     * The scenario the whole reconciliation doctrine exists for, in the mode where it is easiest to get
     * silently wrong: a worker died holding a claim, the platform call had already landed, and the only
     * evidence is a `publication_attempts` row. `findExisting()` reads that trail — so a reaper that
     * parked the row centrally, or a probe that read the central trail, would answer "proven absent"
     * about a post that exists, mark the row `failed`, and thereby AUTHORISE a retry that publishes it
     * a second time. That is the worst outcome this module can produce, and it is reachable only in
     * own-database mode.
     *
     * A CENTRAL decoy attempt row is planted under the same publication id, so a mis-routed probe would
     * conclude with the decoy's remote id rather than the tenant's.
     */
    public function test_the_reconciliation_sweep_reaps_and_probes_inside_the_tenant_database(): void
    {
        $this->provisionOwnDatabaseWorkspace();
        $this->useTenant();

        $stranded = Publication::factory()->publishing()->create([
            'title' => 'TENANT publication',
            'creator_id' => $this->user->id,
            'remote_draft_id' => 'dryrun_draft_tenant_container',
            'last_attempt_at' => now()->subSeconds((int) config('publishing.queue.stale_after') + 60),
        ]);

        // What a killed worker leaves behind: the platform call landed, the status write did not.
        PublicationAttempt::create([
            'publication_id' => $stranded->id,
            'platform' => 'dry_run',
            'phase' => 'publish',
            'succeeded' => true,
            'attempt' => 1,
            'remote_draft_id' => 'dryrun_draft_tenant_container',
            'remote_id' => 'dryrun_tenant_artifact',
        ]);

        $this->plantCentralAttemptDecoy($stranded->id);

        app(TenantContext::class)->clear();
        app(TenantManager::class)->forget();

        $this->artisan('publishing:reconcile')->assertSuccessful();

        $this->useTenant();

        $fresh = $stranded->fresh();

        // Reaped out of `publishing`, then resolved by the probe in the same pass — with nobody having
        // looked at a screen.
        $this->assertSame(PublicationStatus::PUBLISHED, $fresh->status);
        $this->assertSame(
            'dryrun_tenant_artifact',
            $fresh->remote_id,
            'a CENTRAL attempt row reached an own-database workspace\'s reconciliation',
        );

        // ── THE NEGATIVE ──────────────────────────────────────────────────────────
        $this->assertSame(
            0,
            (int) DB::connection(config('database.default'))
                ->table('publications')
                ->where('id', $stranded->id)
                ->count(),
            'the reaper must not have written the row into the central database',
        );
    }

    /** The WRITE path, through the real endpoint, so the connection is chosen by middleware from a header. */
    public function test_a_publication_written_through_the_api_lands_in_the_tenant_database_only(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $id = $this->asUser()
            ->postJson('/api/publishing/publications', [
                'title' => 'Zapowiedź odcinka',
                'platform' => 'dry_run',
                // Zone-less on purpose: it must be read in the workspace zone, which lives in the
                // CENTRAL table while this write goes to the tenant one.
                'scheduled_at' => '2026-09-10T09:00:00',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->useTenant();

        $publication = Publication::query()->findOrFail($id);

        // +02:00 in September — proof that the central workspaces row was consulted from inside a
        // tenant-routed request.
        $this->assertSame('2026-09-10T07:00:00+00:00', $publication->scheduled_at->utc()->toIso8601String());

        // ── THE NEGATIVE ──────────────────────────────────────────────────────────
        $central = DB::connection(config('database.default'));

        $this->assertSame(
            0,
            (int) $central->table('publications')->where('id', $id)->count(),
            'an own-database workspace must not leave its publications in the central database',
        );
        $this->assertSame(
            0,
            (int) $central->table('publications')->where('workspace_id', $this->workspace->id)->count(),
            'nor any publication scoped to it — this is the assertion a hardcoded connection would fail',
        );
    }

    /**
     * THE FULL PUBLISH, on a tenant connection — and the trail lands beside its subject.
     *
     * This is the test that would catch the worst own-database defect available here: attempts written
     * to one database while the publication lives in another. `findExisting()` reads the trail, so a
     * split would leave reconciliation permanently unable to see its own evidence — it would answer
     * "proven absent" for a post that exists, and the retry it authorises would publish it twice.
     */
    public function test_the_attempt_trail_and_its_publication_live_in_the_same_database(): void
    {
        $this->provisionOwnDatabaseWorkspace();
        $this->useTenant();

        $publication = Publication::factory()->create([
            'title' => 'TENANT publication',
            'creator_id' => $this->user->id,
        ]);

        app(PublicationManager::class)->arm($publication, CarbonImmutable::parse('2026-09-10 09:00:00', 'UTC'));
        app(PublicationPublisher::class)->publish($publication);

        $this->assertSame(PublicationStatus::PUBLISHED, $publication->fresh()->status);

        $this->assertSame(
            2,
            PublicationAttempt::query()->where('publication_id', $publication->id)->count(),
            'both phases must be on record in the TENANT database',
        );

        // ── THE NEGATIVE ──────────────────────────────────────────────────────────
        $central = DB::connection(config('database.default'));

        $this->assertSame(
            0,
            (int) $central->table('publication_attempts')->where('publication_id', $publication->id)->count(),
            'the trail must not be split from its subject across databases — reconciliation reads it',
        );
    }

    /**
     * RECONCILIATION READS THE TENANT TRAIL.
     *
     * The scenario a crash leaves behind: the platform call landed, the status write did not. The
     * evidence is a tenant row, and a decoy is planted CENTRALLY under the same publication id — so a
     * mis-routed read would answer with the decoy's remote id instead of the real one.
     */
    public function test_reconciliation_reads_the_tenant_trail_and_never_the_central_one(): void
    {
        $this->provisionOwnDatabaseWorkspace();
        $this->useTenant();

        $publication = Publication::factory()->needsReconcile()->create([
            'title' => 'TENANT publication',
            'creator_id' => $this->user->id,
        ]);

        PublicationAttempt::create([
            'publication_id' => $publication->id,
            'platform' => 'dry_run',
            'phase' => 'publish',
            'succeeded' => true,
            'attempt' => 1,
            'remote_id' => 'dryrun_tenant_artifact',
        ]);

        $this->plantCentralAttemptDecoy($publication->id);

        app(PublicationPublisher::class)->reconcile($publication);

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::PUBLISHED, $fresh->status);
        $this->assertSame(
            'dryrun_tenant_artifact',
            $fresh->remote_id,
            'a CENTRAL attempt row reached an own-database workspace\'s reconciliation',
        );
    }

    /**
     * D4 — THE FAILURE LETTER IS WRITTEN FROM THE TENANT ROW, AND ADDRESSED FROM THE CENTRAL ONE.
     *
     * The first thing in this module that reads BOTH databases in one breath, which is why it gets a
     * twin. The publication lives in the tenant database; the recipient does not, and cannot: `users`,
     * `workspaces` and `workspace_user` are central in both db_modes, so establishing "who is answerable
     * for this" is a central query made while a tenant connection is configured.
     *
     * Two failures are invisible in shared mode and both are pinned here:
     *
     *   A LISTENER THAT READ CENTRAL for the publication would find nothing at all for a tenant id — and
     *   a failure nobody is told about is the exact defect D4 exists to remove. That the letter EXISTS is
     *   the assertion; the central decoy (this workspace's id, a different row) is what stops a
     *   mis-routed read from quietly succeeding with the wrong content.
     *
     *   A RECIPIENT LOOKUP ROUTED TO THE TENANT would find no `users` table there at all. The creator is
     *   deliberately a system record (`workflow_run`), so the address can only come from the workspace
     *   owner — i.e. from central — while everything about the content comes from the tenant row.
     *
     * The run row itself is NOT created: the morph resolves to null either way, and inventing a
     * `workflow_runs` fixture here would couple this test to the tenant schema of another module for no
     * gain. What matters is `creator_type`, which is what makes `creatorUser()` answer null.
     */
    public function test_the_failure_letter_is_written_from_the_tenant_row(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $this->plantCentralPublicationDecoy();

        $this->useTenant();

        $publication = Publication::factory()->publishing()->create([
            'title' => 'TENANT publication',
            'creator_id' => $this->user->id,
        ]);

        $publication->forceFill([
            'creator_id' => (string) Str::uuid7(),
            'creator_type' => 'workflow_run',
        ])->save();

        Mail::fake();

        app(PublicationManager::class)->markFailed($publication->fresh(), 'title_missing');

        Mail::assertQueued(PublicationFailedMail::class, 1);

        /** @var PublicationFailedMail $letter */
        $letter = Mail::queued(PublicationFailedMail::class)->first();

        $this->assertTrue(
            $letter->hasTo($this->user->email),
            'the recipient lookup must reach the CENTRAL owner while a tenant connection is configured',
        );

        $this->assertSame($publication->id, $letter->publicationId);
        $this->assertSame('TENANT publication', $letter->title);
        $this->assertSame(self::WORKSPACE_TIMEZONE, $letter->timezone);

        $this->assertStringNotContainsString(
            'CENTRAL decoy',
            $letter->render(),
            'a CENTRAL publication\'s title reached an own-database workspace\'s letter',
        );
    }

    /** The calendar source resolves the tenant connection like every other source. */
    public function test_the_calendar_source_reads_the_tenant_database(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $this->plantCentralPublicationDecoy();

        $this->useTenant();

        Publication::factory()->scheduled('2026-09-10 09:00:00')->create([
            'title' => 'TENANT publication',
            'creator_id' => $this->user->id,
        ]);

        $data = $this->asUser()
            ->getJson('/api/calendar/occurrences?from=' . self::WINDOW_FROM . '&to=' . self::WINDOW_TO . '&sources[]=publication')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data, 'the tenant grid must not be empty — that would make the check below vacuous');

        foreach ($data as $occurrence) {
            $this->assertStringStartsWith(
                'TENANT',
                $occurrence['title'],
                'a CENTRAL publication reached an own-database workspace\'s grid: ' . $occurrence['title'],
            );
        }
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────

    private function asUser(): self
    {
        parent::actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);

        return $this;
    }

    private function provisionOwnDatabaseWorkspace(): void
    {
        $this->user = User::factory()->create();

        $this->workspace = Workspace::factory()->create([
            'owner_id' => $this->user->id,
            'db_mode' => 'own',
            'status' => 'provisioning',
            'timezone' => self::WORKSPACE_TIMEZONE,
        ]);
        $this->workspace->users()->attach($this->user->id);

        $provisioner = app(WorkspaceProvisioner::class);
        $this->tenantDatabase = $provisioner->databaseName($this->workspace);
        $provisioner->provision($this->workspace);

        $this->workspace->forceFill(['status' => WorkspaceStatus::Ready])->save();
    }

    /**
     * A publication in the CENTRAL database, scoped to THIS workspace, inside the window — the row a
     * mis-routed query would happily return. Written with the query builder rather than through the
     * model, because the model is exactly the thing under test and would route it away.
     */
    private function plantCentralPublicationDecoy(): void
    {
        $central = DB::connection(config('database.default'));
        $id = (string) Str::uuid();
        $this->centralDecoyIds[] = $id;

        $central->table('publications')->insert([
            'id' => $id,
            'workspace_id' => $this->workspace->id,
            'title' => 'CENTRAL decoy',
            'body' => null,
            'platform' => 'dry_run',
            'platform_connection_id' => null,
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-12 09:00:00',
            'published_at' => null,
            'media' => '[]',
            'options' => '{}',
            'remote_id' => null,
            'remote_draft_id' => null,
            'remote_url' => null,
            'attempts' => 0,
            'last_attempt_at' => null,
            'failure_code' => null,
            'failure_context' => null,
            'creator_id' => $this->user->id,
            'creator_type' => 'user',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A CENTRAL attempt row for a TENANT publication — the row a mis-routed reconciliation would find. */
    private function plantCentralAttemptDecoy(string $publicationId): void
    {
        DB::connection(config('database.default'))->table('publication_attempts')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'publication_id' => $publicationId,
            'platform' => 'dry_run',
            'phase' => 'publish',
            'succeeded' => true,
            'attempt' => 1,
            'remote_draft_id' => null,
            'remote_id' => 'CENTRAL_decoy_artifact',
            'request' => null,
            'failure_code' => null,
            'failure_context' => null,
            'creator_id' => $this->user->id,
            'creator_type' => 'user',
            'created_at' => now(),
        ]);
    }

    private function useTenant(): void
    {
        app(TenantContext::class)->set($this->workspace);
        app(TenantManager::class)->configure($this->workspace);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        app(TenantContext::class)->clear();

        // Drop ONLY the generated tenant database — never the central app DB.
        if ($this->tenantDatabase !== null) {
            app(TenantManager::class)->forget();

            DB::connection(config('database.default'))
                ->statement('drop database if exists "' . $this->tenantDatabase . '"');
        }

        $central = DB::connection(config('database.default'));

        if ($this->workspace !== null) {
            $central->table('publication_attempts')->where('workspace_id', $this->workspace->id)->delete();
            // BEFORE the connections: `publications.platform_connection_id` is a `restrict` foreign key,
            // so a connection with a central publication behind it cannot be deleted. In a green run
            // there is nothing central to delete at all — that is what the tests assert — but a FAILING
            // run leaves exactly the rows this has to be able to clean up.
            $central->table('publications')->where('workspace_id', $this->workspace->id)->delete();
            $central->table('platform_connections')->where('workspace_id', $this->workspace->id)->delete();
            $central->table('workspace_user')->where('workspace_id', $this->workspace->id)->delete();
        }

        $this->centralDecoyIds = [];

        $this->workspace?->forceDelete();
        $this->user?->forceDelete();

        parent::tearDown();
    }
}
