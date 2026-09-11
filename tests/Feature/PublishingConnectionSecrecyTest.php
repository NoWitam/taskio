<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Publishing\Contracts\PlatformAdapter;
use App\Modules\Publishing\DTOs\RemoteDraft;
use App\Modules\Publishing\DTOs\RemoteRef;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Models\PlatformConnection;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Services\OAuthStateService;
use App\Modules\Publishing\Services\PlatformAdapterRegistry;
use App\Modules\Publishing\Services\PublicationPublisher;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\TestCase;

/**
 * R4 B2 — THE HARD PROHIBITIONS: NO TOKEN REACHES A RESPONSE, A URL, OR A LOG.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THESE ARE SCANNING TESTS, AND THAT IS THE POINT
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * A test that asserted `$json['access_token']` is absent would only prove that ONE key is missing. The
 * ways a credential actually escapes are the ones nobody names in advance: a field added to a resource,
 * a model spread into a payload, an exception whose message embeds a response body, a query log that
 * interpolates its bindings.
 *
 * So each test below drives a REAL scenario with DISTINCTIVE token literals and then searches the whole
 * artifact — the serialized response, the redirect URL, the log file — for those literals. What is
 * asserted is not "this field is absent" but "this string is nowhere", which is the property that
 * matters and the only one that survives somebody adding a field.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE QUERY LOG IS THE INTERESTING ONE
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * This application prepends `LogMiddleware` globally, and in `local` it writes EVERY statement of every
 * request with its values interpolated (`$query->toRawSql()`). An INSERT into `platform_connections`
 * therefore appears in the log in full.
 *
 * What keeps that safe is the `encrypted` cast: the ciphertext is what lives in the attribute, so the
 * ciphertext is what is bound, and it is the ciphertext that is logged. That is the concrete thing the
 * cast buys over encrypting by hand at a call site, and
 * {@see test_no_token_reaches_the_log_including_the_interpolated_query_log} is what proves it rather
 * than assuming it.
 *
 * The log channel is configured BY THIS TEST rather than inherited: there is no `.env.testing`, so a
 * suite that read `LOG_CHANNEL` would pass or fail according to what somebody last set by hand — the
 * exact failure mode CLAUDE.md warns about.
 */
class PublishingConnectionSecrecyTest extends TestCase
{
    use RefreshDatabase;

    private const ACCESS_TOKEN = 'fake-access-SECRECY-must-never-appear-0001';

    private const REFRESH_TOKEN = 'fake-refresh-SECRECY-must-never-appear-0002';

    /**
     * THE AUTHORIZATION CODE, as a searchable literal.
     *
     * It is a credential in its own right for about ten minutes — exchangeable for the access token —
     * and it arrives in the QUERY STRING of a URL that a globally prepended middleware used to write to
     * the log verbatim. See {@see test_no_authorization_code_reaches_the_log}.
     */
    private const AUTHORIZATION_CODE = 'the-SECRECY-authorization-code-0003';

    private User $owner;

    private Workspace $workspace;

    private string $logFile;

    /** The browser binding for the handshake in flight. */
    private ?string $handshake = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->workspace->users()->attach($this->owner->id);

        app(TenantContext::class)->set($this->workspace);

        config([
            'publishing.platforms.youtube.client_id' => 'test-google-client-id',
            'publishing.platforms.youtube.client_secret' => 'test-google-client-secret',
        ]);

        // `LogMiddleware` only records anything in `local` — it interpolates every binding of every
        // statement, which is a developer's tool and not a thing to run on a server. This suite is ABOUT
        // what that log contains, so it says which environment it needs rather than inheriting one:
        // under `testing` the whole file would pass by logging nothing, which is the vacuous form of
        // every assertion below.
        $this->app['env'] = 'local';

        // A dedicated file, named by this test, so nothing here depends on `.env` and nothing here
        // appends to the developer's own log.
        $this->logFile = storage_path('logs/testing-publishing-secrecy.log');
        @unlink($this->logFile);

        config([
            'logging.default' => 'single',
            'logging.channels.single.path' => $this->logFile,
            'logging.channels.single.level' => 'debug',
        ]);

        // The LogManager caches resolved channels, so a config change after boot is otherwise ignored.
        $this->app->forgetInstance('log');

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);

        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * NOTHING THE API EVER ANSWERS WITH CONTAINS A TOKEN.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * The list endpoint is the obvious risk and is checked as raw text rather than key by key. The
     * assertion about `$json` shape below is secondary — it says the response is USEFUL (it has the
     * metadata a screen needs), so that a resource which accidentally returned an empty object could not
     * pass the secrecy check vacuously.
     */
    public function test_no_token_reaches_any_api_response(): void
    {
        $this->connectThroughTheRealFlow();

        $body = $this->asOwner()
            ->getJson('/api/publishing/connections')
            ->assertOk()
            ->getContent();

        $this->assertNoTokensIn($body, 'the connections list');

        $json = json_decode($body, true);
        $connection = $json['data'][0];

        // Not vacuous: the payload really is the connections list.
        $this->assertSame('youtube', $connection['platform']);
        $this->assertSame('UCsecrecy_channel_00000', $connection['external_account_id']);
        $this->assertSame('active', $connection['status']);
        $this->assertTrue($connection['credentials_readable']);

        // And belt-and-braces on the shape: no key that could ever hold one.
        foreach (array_keys($connection) as $key) {
            $this->assertStringNotContainsString('token', $key, "the resource exposes a [{$key}] field");
        }
    }

    /**
     * A PUBLICATION'S PAYLOAD CANNOT REACH ONE EITHER, THROUGH ITS CONNECTION.
     *
     * The obvious future mistake: eager-loading the connection onto a publication for a "which account"
     * label and spreading the model into the payload. `PlatformConnection::$hidden` is the guard that
     * catches that one, and this is what would notice if it were removed.
     */
    public function test_no_token_reaches_a_publication_payload(): void
    {
        $connection = $this->connectThroughTheRealFlow();

        $publication = Publication::factory()
            ->on(PublishingPlatform::YOUTUBE)
            ->create([
                'creator_id' => $this->owner->id,
                'platform_connection_id' => $connection->id,
            ]);

        $this->assertNoTokensIn(
            $this->asOwner()->getJson('/api/publishing/publications/' . $publication->id)->assertOk()->getContent(),
            'the publication payload',
        );

        // The model serialized directly — the path a log context or a hasty endpoint would take.
        $this->assertNoTokensIn((string) json_encode($connection->fresh()->toArray()), 'the model as an array');
    }

    /**
     * THE REDIRECT CARRIES AN OUTCOME AND NOTHING ELSE.
     *
     * A query parameter ends up in browser history, in the SPA's router state, in anything reading
     * `location.search`, and in screenshots pasted into support tickets. The code has just been
     * exchanged for a token at this exact moment, which is why this is checked at all.
     */
    public function test_no_token_reaches_the_redirect_url(): void
    {
        $this->fakeGoogle();

        $state = $this->mintState();

        $location = $this->hitCallback(self::AUTHORIZATION_CODE, $state)->headers->get('Location');

        app(TenantContext::class)->set($this->workspace);

        $this->assertNoTokensIn((string) $location, 'the redirect URL');
        $this->assertStringNotContainsString(self::AUTHORIZATION_CODE, (string) $location);
        $this->assertStringContainsString('connection=connected', (string) $location);
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * THE AUTHORIZATION CODE IS NOT IN THE LOG EITHER. IT WAS.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * `LogMiddleware` writes `request()->fullUrl()` for every request, and B2's callback is reached as
     * `GET /oauth/youtube/callback?code=…&state=…`. So the code — exchangeable for an access token, and
     * still exchangeable for about ten minutes if the exchange it was meant for FAILED, which is the
     * case somebody reads the log to understand — sat in `storage/logs` beside a state naming the
     * workspace it belonged to.
     *
     * The remedy is in two independent halves, and this test would fail if either were the only one:
     * the middleware now logs nothing outside `local`, AND it redacts credential-bearing parameters
     * from the URL even there. This suite runs it in `local` precisely so the redaction is what is
     * being measured rather than the gate.
     *
     * The PARAMETER NAMES surviving is deliberate and asserted: "this request carried a code" is the
     * half that makes a log readable, and the value is the half that must not be there.
     */
    public function test_no_authorization_code_reaches_the_log(): void
    {
        $this->connectThroughTheRealFlow();

        $log = $this->log();

        $this->assertNotSame('', $log, 'the log is empty — this test would pass vacuously');
        $this->assertStringContainsString(
            '/oauth/youtube/callback',
            $log,
            'the callback request was not logged at all, so this proves nothing about what it logged',
        );

        $this->assertStringNotContainsString(
            self::AUTHORIZATION_CODE,
            $log,
            'an OAuth authorization code reached the application log. It is exchangeable for an access '
            . 'token, and a failed exchange leaves it exchangeable for longer.',
        );

        $this->assertStringContainsString('code=[redacted]', $log, 'the parameter name should survive; only its value goes');
        $this->assertStringContainsString('state=[redacted]', $log);
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * NOT IN THE LOG. INCLUDING THE INTERPOLATED QUERY LOG.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * `LogMiddleware` writes every statement of every request with its values inlined, so the INSERT
     * that stores a connection is in this file in full. What makes that survivable is the `encrypted`
     * cast: what gets bound is ciphertext, so what gets logged is ciphertext.
     *
     * The assertion that the log is NON-EMPTY and mentions the table is not padding — without it, a
     * misconfigured channel would make the whole test pass by writing nothing at all.
     */
    public function test_no_token_reaches_the_log_including_the_interpolated_query_log(): void
    {
        $this->connectThroughTheRealFlow();

        $log = $this->log();

        $this->assertNotSame('', $log, 'the log is empty — this test would pass vacuously');
        $this->assertStringContainsString(
            'platform_connections',
            $log,
            'the query log did not record the connection write, so it cannot be evidence about it',
        );

        $this->assertNoTokensIn($log, 'the application log');
    }

    /**
     * A FAILED TOKEN EXCHANGE PUTS NOTHING FROM THE RESPONSE BODY IN THE LOG.
     *
     * THE SHARPEST VERSION OF THIS RULE. The framework's idiomatic `$response->throw()` raises a
     * `RequestException` whose message embeds the first 120 characters of the body — and Laravel then
     * logs that with a stack trace. For a token endpoint, 120 characters of the body IS a credential.
     *
     * So the fake below answers a FAILURE whose body still contains a token, which is not as strange as
     * it sounds: a partial success, a proxy's error envelope, a platform echoing the request. Nothing
     * about that body may reach the log. The platform's machine error code may, and is asserted present
     * — because a diagnosis with nothing in it is its own defect.
     */
    public function test_a_failed_exchange_never_logs_the_response_body(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_client',
                'error_description' => 'Unauthorized',
                // The thing that must not travel, in the place a body would carry it.
                'access_token' => self::ACCESS_TOKEN,
            ], 401),
        ]);

        $this->hitCallback(self::AUTHORIZATION_CODE, $this->mintState())
            ->assertRedirectContains('reason=token_exchange_failed');

        app(TenantContext::class)->set($this->workspace);

        $log = $this->log();

        $this->assertNoTokensIn($log, 'the log after a failed exchange');
        $this->assertStringNotContainsString('Unauthorized', $log, 'the platform\'s prose reached the log');

        // NOT VACUOUS: the failure IS diagnosable, from a status and a documented error code.
        $this->assertStringContainsString('invalid_client', $log);
        $this->assertStringContainsString('"status":401', $log);
    }

    /**
     * A FAILED REFRESH DOES NOT LOG A BODY EITHER, AND THE HOLD IT CAUSES CARRIES NO CREDENTIAL.
     *
     * `failure_context` on a publication is rendered to a user, so it is a payload like any other. The
     * assertion is that the hold names the connection and nothing else — a context that had grown to
     * include "what we tried to send" would be the same leak in a slower form.
     */
    public function test_a_failed_refresh_leaves_no_credential_in_the_hold_it_places(): void
    {
        $connection = PlatformConnection::factory()->expiringIn(2)->create([
            'creator_id' => $this->owner->id,
            'access_token' => self::ACCESS_TOKEN,
            'refresh_token' => self::REFRESH_TOKEN,
        ]);

        $publication = Publication::factory()->scheduled()->create([
            'creator_id' => $this->owner->id,
            'platform_connection_id' => $connection->id,
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Token has been expired or revoked.',
                'access_token' => self::ACCESS_TOKEN,
            ], 400),
        ]);

        $this->artisan('publishing:refresh-tokens')->assertSuccessful();

        app(TenantContext::class)->set($this->workspace);

        $publication->refresh();

        $this->assertSame(
            ['platform_connection_id' => $connection->id],
            $publication->failure_context,
            'a hold names the connection and nothing else',
        );

        $this->assertNoTokensIn((string) json_encode($publication->failure_context), 'the hold context');
        $this->assertNoTokensIn($this->log(), 'the log after a failed refresh');
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * B3 — A PUBLISH THAT FAILS IN AN UNKNOWN WAY LOGS NOTHING FROM THE EXCEPTION'S MESSAGE.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * The catch-all in `PublicationPublisher::publishClaimed()` is the single most dangerous log site in
     * the module, and the reason is WHO WRITES THE STRING IT WAS LOGGING. Every other assertion in this
     * file is about code in this repository choosing not to put a credential somewhere. This one is
     * about code that is not in this repository: the adapter beneath it holds a live access token while
     * it talks to a platform, the catch takes `Throwable`, and the message on whatever arrives is
     * composed by an HTTP client, a driver, or a platform SDK.
     *
     * The two shapes that make it concrete, neither hypothetical:
     *   - META AUTHENTICATES BY QUERY STRING (`?access_token=…`). Any client that names the failing URL
     *     in its message — most of them do — puts a working credential in the message on every timeout.
     *   - `RequestException` embeds the first 120 characters of the response body, which on these
     *     endpoints is a token.
     *
     * So the adapter below throws with a token in the message, which is exactly what those would
     * produce, and the log is searched for it. The class, the file and the line are asserted PRESENT:
     * a redaction that left nothing diagnosable would be its own defect, and this test would not notice
     * the difference otherwise.
     */
    public function test_a_publish_that_fails_unknowably_never_logs_the_exception_message(): void
    {
        $publication = Publication::factory()->publishing()->create(['creator_id' => $this->owner->id]);

        $this->useAdapter(new class(self::ACCESS_TOKEN) implements PlatformAdapter
        {
            public function __construct(private string $token) {}

            public function platform(): PublishingPlatform
            {
                return PublishingPlatform::DRY_RUN;
            }

            public function createDraft(Publication $publication): RemoteDraft
            {
                return RemoteDraft::make('dryrun_draft_before_the_leak');
            }

            /** The shape an HTTP client produces when Meta's query-string auth times out. */
            public function publishDraft(Publication $publication, string $remoteDraftId): RemoteRef
            {
                throw new RuntimeException(
                    'cURL error 28: Operation timed out for '
                    . 'https://graph.facebook.com/v21.0/me/feed?access_token=' . $this->token,
                );
            }

            public function findExisting(Publication $publication): ?RemoteRef
            {
                throw new RuntimeException(
                    'cURL error 28: Operation timed out for '
                    . 'https://graph.facebook.com/v21.0/me?access_token=' . $this->token,
                );
            }
        });

        app(PublicationPublisher::class)->publishClaimed($publication);

        // And the probe, which is the worse of the two: it runs unattended, on a schedule, for as long
        // as the row sits unresolved. A leak here is not logged once — it is logged hourly.
        app(PublicationPublisher::class)->reconcile($publication->fresh());

        $log = $this->log();

        $this->assertNotSame('', $log, 'the log is empty — this test would pass vacuously');
        $this->assertNoTokensIn($log, 'the log after an unknowable publish failure');
        $this->assertStringNotContainsString(
            'graph.facebook.com',
            $log,
            'the failing URL reached the log, and on this platform the URL IS the credential',
        );

        // NOT VACUOUS: both failures are still diagnosable, from the parts nobody else authors.
        $this->assertStringContainsString('needs reconciliation', $log);
        $this->assertStringContainsString('Reconciliation could not establish', $log);
        $this->assertStringContainsString('RuntimeException', $log);
        $this->assertStringContainsString('PublishingConnectionSecrecyTest.php:', $log, 'the location must survive');
    }

    /**
     * THE CIPHERTEXT IN THE COLUMN IS NOT THE TOKEN.
     *
     * The claim every other test in this file rests on, checked directly against the raw column rather
     * than through the model. If this ever failed, the `encrypted` casts would have silently stopped
     * applying and every scan above would still pass for a while — because the model would keep handing
     * back the same strings either way.
     */
    public function test_the_stored_columns_are_ciphertext_and_not_the_tokens(): void
    {
        $connection = PlatformConnection::factory()->create([
            'creator_id' => $this->owner->id,
            'access_token' => self::ACCESS_TOKEN,
            'refresh_token' => self::REFRESH_TOKEN,
        ]);

        $raw = DB::table('platform_connections')->where('id', $connection->id)->first();

        $this->assertNoTokensIn((string) $raw->access_token . '|' . (string) $raw->refresh_token, 'the raw columns');

        // And the round trip still works, so the check above is about encryption and not about a
        // write that silently dropped the value.
        $this->assertSame(self::ACCESS_TOKEN, $connection->fresh()->credentials()->accessToken);
        $this->assertSame(self::REFRESH_TOKEN, $connection->fresh()->credentials()->refreshToken);
    }

    /**
     * CREDENTIALS REFUSE TO BE DUMPED OR SERIALIZED.
     *
     * The two paths a live credential takes out of a process without anybody deciding it should: a
     * `var_dump`/`dd` reaching for `__debugInfo`, and a queue payload or cache entry reaching for
     * `__sleep`. Both are one line, both look harmless, and this is where they stop.
     */
    public function test_credentials_cannot_be_dumped_or_serialized(): void
    {
        $credentials = PlatformConnection::factory()->create([
            'creator_id' => $this->owner->id,
            'access_token' => self::ACCESS_TOKEN,
            'refresh_token' => self::REFRESH_TOKEN,
        ])->credentials();

        ob_start();
        var_dump($credentials);
        $dumped = (string) ob_get_clean();

        $this->assertNoTokensIn($dumped, 'a var_dump of the credentials');
        $this->assertStringContainsString('redacted', $dumped);

        $this->expectException(\LogicException::class);

        serialize($credentials);
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * NOTHING IN THIS MODULE CALLS `->throw()`. NOT ONCE.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * The scenario tests above prove that the paths they walk do not leak a body. This proves the
     * property for the paths NOBODY HAS WRITTEN YET, which is where it will actually be lost:
     * `$response->throw()` is one idiomatic method call, it is what a reviewer's eye slides over, and
     * `RequestException`'s message embeds the first 120 characters of the body. On a token endpoint
     * those 120 characters are a working credential, and Laravel's handler writes the message to the log
     * with a stack trace.
     *
     * The property holds across the whole of `app/` today, so this is not a bar the module has to be
     * held to alone — but B3 adds the publishing calls, which are the ones with a token in the
     * Authorization header, and the module is where the temptation will arrive.
     *
     * IF YOU ARE HERE BECAUSE THIS FAILED: the answer is not an exemption. Read the failed response
     * yourself, take the status and the platform's machine error code from named fields, and throw
     * `OAuthExchangeFailed` — a class with no parameter a body could arrive through. That is what
     * `AbstractOAuthProvider::decode()` does and why it exists.
     *
     * COMMENTS ARE STRIPPED BEFORE THE SCAN, through PHP's own tokenizer rather than a regex. This
     * module argues about `->throw()` at length in three docblocks — including the one that forbids it —
     * so a literal byte scan reports the files that EXPLAIN the rule as the ones breaking it. Two guards
     * below keep the stripping from turning the whole test into a tautology.
     */
    public function test_nothing_in_the_publishing_module_calls_throw_on_an_http_response(): void
    {
        $root = app_path('modules/Publishing');
        $this->assertDirectoryExists($root);

        $scanned = 0;
        $offenders = [];

        /** @var iterable<\SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $scanned++;

            // `->throw(` and `->throwIf(`/`->throwUnless(`/`->throwIfStatus(` — every member of the
            // family, because they all raise the same exception with the same message.
            if (preg_match('/->throw(?:If|Unless|IfStatus|UnlessStatus|IfClientError|IfServerError)?\s*\(/', $this->codeOf($file->getPathname())) === 1) {
                $offenders[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'A Publishing file calls ->throw() on an HTTP response. Its exception message embeds the '
            . 'first 120 characters of the body, which on a token endpoint is a live credential, and '
            . "the framework logs that message with a stack trace. Offenders:\n  - "
            . implode("\n  - ", $offenders),
        );

        // NOT VACUOUS, twice over. A scan that found no files, or a stripper that returned empty
        // strings, would both assert precisely nothing.
        $this->assertGreaterThan(20, $scanned, 'expected to scan the whole Publishing module');
        $this->assertStringContainsString(
            '$client->get(',
            $this->codeOf(app_path('modules/Publishing/OAuth/AbstractOAuthProvider.php')),
            'comment stripping ate the code as well — the scan above would then pass on anything',
        );
    }

    /**
     * A file's PHP with every comment removed.
     *
     * Through the tokenizer, so "a comment" means what the language means by it rather than what a
     * regex can be talked into. Whitespace is kept: the patterns above do not span lines, and preserving
     * layout keeps a failure message legible.
     */
    private function codeOf(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────

    /** Fail with the offending literal quoted, so a failure says WHAT leaked and not merely that it did. */
    private function assertNoTokensIn(string $haystack, string $what): void
    {
        foreach (['access' => self::ACCESS_TOKEN, 'refresh' => self::REFRESH_TOKEN] as $kind => $token) {
            $this->assertStringNotContainsString(
                $token,
                $haystack,
                "A {$kind} token reached {$what}. Nothing derived from a credential may travel there.",
            );
        }
    }

    private function log(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    /** Swap the dry-run slot for a leaking adapter — the registry refuses a second registration. */
    private function useAdapter(PlatformAdapter $adapter): void
    {
        $registry = new PlatformAdapterRegistry;
        $registry->register($adapter);

        $this->app->instance(PlatformAdapterRegistry::class, $registry);
    }

    private function asOwner(): self
    {
        $this->actingAs($this->owner)->withHeader('X-Workspace-Id', $this->workspace->id);

        return $this;
    }

    private function mintState(): string
    {
        $states = app(OAuthStateService::class);

        $state = $states->issue($this->owner, $this->workspace, PublishingPlatform::YOUTUBE);

        // The BROWSER SECRET, not the `jti`: the cookie carries 32 random bytes and the state carries
        // only their digest, which is what stops a client holding a stolen state from building one.
        $this->handshake = $state->browserSecret;

        return $states->encode($state);
    }

    /** The callback, from the browser that started the handshake. See PublishingOAuthStateTest. */
    private function hitCallback(string $code, string $state)
    {
        return $this
            ->withUnencryptedCookie(OAuthStateService::HANDSHAKE_COOKIE, (string) $this->handshake)
            ->get('/oauth/youtube/callback?' . http_build_query([
                'code' => $code,
                'state' => $state,
            ]));
    }

    /** The whole handshake, through the real routes, so the log contains a real connection write. */
    private function connectThroughTheRealFlow(): PlatformConnection
    {
        $this->fakeGoogle();

        $response = $this->asOwner()
            ->postJson('/api/publishing/connections/youtube/authorize')
            ->assertOk();

        $this->handshake = (string) $response->getCookie(OAuthStateService::HANDSHAKE_COOKIE, decrypt: false)?->getValue();

        parse_str((string) parse_url((string) $response->json('data.authorize_url'), PHP_URL_QUERY), $query);

        $this->hitCallback(self::AUTHORIZATION_CODE, $query['state'])
            ->assertRedirectContains('connection=connected');

        app(TenantContext::class)->set($this->workspace);

        return PlatformConnection::query()->sole();
    }

    private function fakeGoogle(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => self::ACCESS_TOKEN,
                'refresh_token' => self::REFRESH_TOKEN,
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/youtube.upload',
            ]),
            'googleapis.com/youtube/v3/*' => Http::response([
                'items' => [[
                    'id' => 'UCsecrecy_channel_00000',
                    'snippet' => ['title' => 'Secrecy Channel'],
                ]],
            ]),
        ]);
    }
}
