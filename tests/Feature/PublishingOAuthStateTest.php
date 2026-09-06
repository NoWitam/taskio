<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Exceptions\OAuthStateRejected;
use App\Modules\Publishing\Models\PlatformConnection;
use App\Modules\Publishing\Services\OAuthStateService;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * R4 B2 — THE `state` PARAMETER, WHICH IS THE ONLY THING AUTHENTICATING AN OAUTH CALLBACK.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS FILE IS AS LONG AS IT IS
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * Every other write in this application is reached through `auth:sanctum` and `ResolveWorkspace`. The
 * callback has neither — a browser redirect from Google carries no Authorization header and no workspace
 * header — so the signed `state` is the WHOLE of its identity. It decides which user a connection is
 * attributed to and which DATABASE it is written into.
 *
 * That makes the state's verification the security boundary of the batch, and a boundary is only worth
 * what its negative cases prove. Each test below is one way the boundary could be pushed on.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * EVERY REFUSAL IS A REDIRECT, NOT A STATUS CODE — AND EACH ONE ASSERTS THAT NOTHING WAS WRITTEN
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * The party at the far end is a person in a browser at the end of a consent flow, so a 403 would be a
 * framework error page in a language nobody chose. The refusal travels as a stable reason code in the
 * query string.
 *
 * That shape makes a defect easy to miss: a callback that half-worked would ALSO answer with a redirect.
 * So no test here settles for the redirect alone — each also asserts that no connection exists.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * EVERY SCENARIO CARRIES A BROWSER BINDING, BECAUSE A REAL ONE WOULD
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * B2's review added a cookie tying a handshake to the browser that started it, so `hitCallback()` sends
 * it and the tests below go on being about the checks they were written for. The ones that are about
 * the BINDING build their requests by hand — a fixture that quietly supplied the thing under test would
 * be the least useful kind of test in this file.
 *
 * The cookie's value is the handshake's SECRET (32 CSPRNG bytes), not its `jti`. It used to be the `jti`,
 * which sits in the signed payload in the URL — so any client holding a state could compute the cookie
 * and set it in a request of its own, `HttpOnly` being a rule about READING a cookie and never about
 * setting one. `test_the_binding_cannot_be_derived_from_the_state_itself` is what keeps that fixed.
 */
class PublishingOAuthStateTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    /**
     * The browser binding for the most recently minted state — what a real browser would be holding.
     *
     * `mintState()` records it and `hitCallback()` sends it, so every scenario in this file that is NOT
     * about the binding behaves like one browser doing one handshake. The scenarios that ARE about it
     * build their requests by hand, a few tests down.
     */
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
            'publishing.platforms.facebook.client_id' => 'test-meta-client-id',
            'publishing.platforms.facebook.client_secret' => 'test-meta-client-secret',
        ]);

        $this->fakeSuccessfulHandshake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * SINGLE USE. THE ONE THE BRIEF NAMES, AND THE ONE A SIGNATURE ALONE CANNOT GIVE.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * A signed string stays valid until it expires. Without a ledger, a back-button press, a prefetching
     * browser or a URL read over somebody's shoulder replays the whole handshake — and the second run
     * would be indistinguishable from the first at every layer that only checks the signature.
     *
     * The second attempt must also leave the FIRST connection untouched: a replay that "failed" but
     * rewrote the row would be a worse outcome than one that succeeded twice.
     */
    public function test_a_consumed_state_cannot_be_used_a_second_time(): void
    {
        $state = $this->mintState();

        $this->hitCallback('youtube', 'code-1', $state)
            ->assertRedirectContains('connection=connected');

        $connection = PlatformConnection::query()->sole();
        $updatedAt = $connection->updated_at;

        $this->hitCallback('youtube', 'code-1', $state)
            ->assertRedirectContains('reason=' . OAuthStateRejected::ALREADY_USED);

        $this->assertSame(1, PlatformConnection::withTrashed()->count(), 'a replay must not create a second connection');
        $this->assertEquals($updatedAt, $connection->fresh()->updated_at, 'a replay must not rewrite the first one');
    }

    /**
     * A TAMPERED PAYLOAD IS REFUSED BY THE SIGNATURE, NOT BY A LATER CHECK.
     *
     * The attack the signature exists for: re-point a legitimately obtained state at ANOTHER WORKSPACE,
     * and the callback writes a connection into a tenant the holder cannot see — choosing that tenant's
     * DATABASE on the strength of the claim. The workspace id here is a real one belonging to somebody
     * else, so nothing but the MAC stands between the two.
     *
     * The refusal must be `bad_signature` specifically. Reporting it as malformed, or as
     * "workspace unavailable" three checks later, would mean the payload had been READ before it was
     * trusted — and a check that runs after the data is used is not a check.
     */
    public function test_a_state_repointed_at_another_workspace_fails_its_signature(): void
    {
        $stranger = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $stranger->id]);
        $otherWorkspace->users()->attach($stranger->id);

        $state = $this->mintState();

        $tampered = $this->reSign($state, fn (array $claims): array => [
            ...$claims,
            'w' => $otherWorkspace->id,
        ], sign: false);

        $this->hitCallback('youtube', 'code-1', $tampered)
            ->assertRedirectContains('reason=' . OAuthStateRejected::BAD_SIGNATURE);

        $this->assertSame(0, PlatformConnection::withTrashed()->count());
    }

    /** The same attack aimed at the USER field: attribute somebody else's account to a stranger. */
    public function test_a_state_repointed_at_another_user_fails_its_signature(): void
    {
        $stranger = User::factory()->create();

        $tampered = $this->reSign($this->mintState(), fn (array $claims): array => [
            ...$claims,
            'u' => $stranger->id,
        ], sign: false);

        $this->hitCallback('youtube', 'code-1', $tampered)
            ->assertRedirectContains('reason=' . OAuthStateRejected::BAD_SIGNATURE);

        $this->assertSame(0, PlatformConnection::withTrashed()->count());
    }

    /**
     * A STATE SIGNED WITH ANOTHER KEY IS REFUSED.
     *
     * The forgery attempt that does not need to tamper with one of ours: build a well-formed payload and
     * sign it with a guess. It is the case that would pass if the signature were ever compared with `==`
     * against something attacker-influenced, or verified with the wrong key.
     */
    public function test_a_state_signed_with_a_foreign_key_is_refused(): void
    {
        $forged = $this->reSign($this->mintState(), fn (array $claims): array => $claims, sign: true, key: 'base64:' . base64_encode(str_repeat('x', 32)));

        $this->hitCallback('youtube', 'code-1', $forged)
            ->assertRedirectContains('reason=' . OAuthStateRejected::BAD_SIGNATURE);

        $this->assertSame(0, PlatformConnection::withTrashed()->count());
    }

    /**
     * AN EXPIRED STATE IS REFUSED, AND SAYS SO RATHER THAN SAYING "ALREADY USED".
     *
     * The distinction is the reason `state_ledger_grace` exists. Both would refuse the handshake, but
     * only one of them tells a person the truth about what they did: an abandoned consent screen is
     * ordinary and its remedy is "start again", while "already used" reads as though something else had
     * taken the link.
     *
     * Expiry is read from the SIGNED PAYLOAD, so this holds even where the ledger is unavailable.
     */
    public function test_an_expired_state_is_refused_and_reports_expiry_rather_than_reuse(): void
    {
        $state = $this->mintState();

        Carbon::setTestNow(now()->addSeconds((int) config('publishing.oauth.state_ttl') + 5));

        $this->hitCallback('youtube', 'code-1', $state)
            ->assertRedirectContains('reason=' . OAuthStateRejected::EXPIRED);

        $this->assertSame(0, PlatformConnection::withTrashed()->count());
    }

    /**
     * A STATE MINTED FOR ONE DESTINATION CANNOT BE REDEEMED AT ANOTHER'S CALLBACK.
     *
     * Cheap to check and worth checking: without it, a code obtained through a low-privilege
     * destination's consent screen could be presented at a high-privilege one's exchange. The platform
     * in the URL and the platform inside the signed payload have to be the same thing.
     */
    public function test_a_state_for_one_platform_cannot_be_redeemed_at_another(): void
    {
        $state = $this->mintState('youtube');

        $this->hitCallback('facebook', 'code-1', $state)
            ->assertRedirectContains('reason=' . OAuthStateRejected::PLATFORM_MISMATCH);

        $this->assertSame(0, PlatformConnection::withTrashed()->count());
    }

    /**
     * A STATE THAT IS NOT THE SHAPE WE MINT IS REFUSED WITHOUT BEING READ.
     *
     * Where truncation, a copy-paste and a probe all land. It matters that this is distinct from
     * `bad_signature`: keeping the two apart is what lets `bad_signature` mean "somebody constructed a
     * well-formed payload and tried to sign it", which is the only one of these worth looking at twice.
     */
    public function test_a_malformed_state_is_refused(): void
    {
        foreach (['', 'not-a-state', 'only.one.dot.too.many', base64_encode('{}')] as $garbage) {
            $this->hitCallback('youtube', 'code-1', $garbage)
                ->assertRedirectContains('connection=failed');
        }

        $this->assertSame(0, PlatformConnection::withTrashed()->count());
    }

    /**
     * MEMBERSHIP IS RE-CHECKED AT THE CALLBACK, NOT ASSUMED FROM THE SIGNATURE.
     *
     * The signed state proves who asked and for which workspace TEN MINUTES AGO. It cannot prove they
     * are still a member, and a signed claim about the past is not a current permission. Ten minutes is
     * long enough to be removed from a workspace.
     *
     * This is the check `ResolveWorkspace` would have made. It is absent from this route by necessity,
     * so the controller makes it by hand — and this is what says so.
     */
    public function test_a_user_removed_from_the_workspace_cannot_complete_a_handshake(): void
    {
        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id);

        $state = $this->mintState(user: $member);

        $this->workspace->users()->detach($member->id);

        $this->hitCallback('youtube', 'code-1', $state)
            ->assertRedirectContains('reason=workspace_unavailable');

        $this->assertSame(0, PlatformConnection::withTrashed()->count());
    }

    /**
     * A DECLINED CONSENT SCREEN DOES NOT SPEND THE NONCE.
     *
     * The platform sends `error=access_denied` and no code. Consuming the state here would tell somebody
     * who pressed "cancel" and then changed their mind that their link had "already been used" — for a
     * link they never used. So the state check is deliberately AFTER the error check, and this pins that
     * ordering by re-using the same state successfully afterwards.
     */
    public function test_a_declined_consent_screen_leaves_the_state_spendable(): void
    {
        $state = $this->mintState();

        $this->hitCallback('youtube', null, $state, error: 'access_denied')
            ->assertRedirectContains('reason=access_denied');

        $this->assertSame(0, PlatformConnection::withTrashed()->count());

        // THE POINT: the same state still works.
        $this->hitCallback('youtube', 'code-1', $state)
            ->assertRedirectContains('connection=connected');

        $this->assertSame(1, PlatformConnection::query()->count());
    }

    /** A callback with a valid state and no code has nothing to exchange, and says which is missing. */
    public function test_a_callback_without_a_code_is_refused(): void
    {
        $this->hitCallback('youtube', null, $this->mintState())
            ->assertRedirectContains('reason=missing_code');

        $this->assertSame(0, PlatformConnection::withTrashed()->count());
    }

    /** An unrecognised destination never reaches a controller — the route constrains the segment. */
    public function test_an_unknown_platform_is_not_routable(): void
    {
        $this->get('/oauth/tiktok/callback?code=x&state=y')->assertNotFound();
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * THE BROWSER BINDING — a stolen `state` cannot be redeemed somewhere else.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * The threat is not the one it first looks like. Reading a `state` off somebody's screen and
     * finishing the handshake with YOUR OWN account sounds like donating an account; what it actually
     * does is put a channel the attacker controls into the victim's workspace, where it appears in the
     * destination picker like any other. Everything subsequently scheduled to it is published to them.
     *
     * A signature cannot stop this — the state is genuine. Single use cannot — it is the first use. What
     * stops it is that the handshake was started in a browser holding a secret the state does not carry.
     *
     * THIS TEST COVERS THE EASIER HALF and must not be read as covering the harder one: it is about a
     * client that sends NO cookie. The client that sends a cookie it BUILT from the state is the test
     * below, and for a while this file had only this one — which is how the binding shipped answering the
     * wrong direction.
     */
    public function test_a_state_redeemed_from_another_browser_is_refused(): void
    {
        $state = $this->mintState();

        // The attacker has the URL and nothing else. Sending no cookie at all is not a weaker form of
        // sending the right one — that distinction is the whole reason a missing cookie fails here and
        // does not fall through to some later check.
        $this->get('/oauth/youtube/callback?' . http_build_query([
            'code' => 'stolen-code',
            'state' => $state,
        ]))->assertRedirectContains('reason=' . OAuthStateRejected::BROWSER_MISMATCH);

        app(TenantContext::class)->set($this->workspace);

        $this->assertSame(0, PlatformConnection::withTrashed()->count());
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * THE THIEF'S PROBE, REVERSED — NOTHING IN THE `state` IS THE BINDING.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * THE TEST ABOVE IS NOT ENOUGH ON ITS OWN, and this file said so for a while without noticing: it
     * describes an attacker who simply DOES NOT SEND a cookie. A real one sends whatever they can build.
     *
     * `HttpOnly` stops a script on somebody else's page from READING the cookie. It does not stop an
     * attacker from SETTING one in a client they control — `curl -H 'Cookie: …'` is the whole technique,
     * and there is no origin to respect when the request is theirs. So the binding is only worth
     * something if its value cannot be COMPUTED from the one thing a thief has, which is the state.
     *
     * It could be, and a reviewer's probe proved it: the cookie carried the `jti`, and the `jti` sits in
     * plain base64url inside the state's own payload. A client with no prior contact with this
     * application read it out and connected. The binding is now a 32-byte secret that appears only in
     * the cookie; the payload carries its SHA-256, which is not invertible.
     *
     * Every candidate below is something derivable from a stolen state — its parts, each of its claims,
     * and the digests a thief would try next. All of them must be refused, and none of them may spend
     * the nonce.
     */
    public function test_the_binding_cannot_be_derived_from_the_state_itself(): void
    {
        $state = $this->mintState();

        [$payload, $signature] = explode('.', $state);
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);

        $candidates = [
            'the whole state' => $state,
            'the payload segment' => $payload,
            'the signature segment' => $signature,
            'the decoded payload' => (string) base64_decode(strtr($payload, '-_', '+/'), true),
        ];

        // Every individual claim, whatever the payload happens to carry — so this test keeps covering the
        // shape rather than the shape it had on the day it was written.
        foreach ($claims as $name => $value) {
            $candidates['the ' . $name . ' claim'] = (string) $value;
            // And the digest of it, because the payload now carries a DIGEST and the obvious next guess
            // is that it is the digest of something public.
            $candidates['sha256 of the ' . $name . ' claim'] = hash('sha256', (string) $value);
        }

        $candidates['sha256 of the payload'] = hash('sha256', $payload);
        $candidates['sha256 of the whole state'] = hash('sha256', $state);

        foreach ($candidates as $what => $guess) {
            $response = $this->withUnencryptedCookie(OAuthStateService::HANDSHAKE_COOKIE, $guess)
                ->get('/oauth/youtube/callback?' . http_build_query([
                    'code' => 'stolen-code',
                    'state' => $state,
                ]));

            app(TenantContext::class)->set($this->workspace);

            // Asserted by hand rather than with `assertRedirectContains`, which takes no message: a
            // failure has to name WHICH derivation opened the door, or the next person repeats the probe.
            $this->assertStringContainsString(
                'reason=' . OAuthStateRejected::BROWSER_MISMATCH,
                (string) $response->headers->get('Location'),
                "{$what} was accepted as the browser binding, and it is derivable from a stolen state",
            );
        }

        $this->assertSame(
            0,
            PlatformConnection::withTrashed()->count(),
            'a client holding only the state must not be able to complete a handshake',
        );

        // AND THE NONCE SURVIVED ALL OF IT. A thief who cannot finish the flow must not be able to
        // destroy it either — see the spendability test below.
        $this->hitCallback('youtube', 'code-1', $state)->assertRedirectContains('connection=connected');
    }

    /**
     * THE LEGITIMATE HALF: the secret the authorize endpoint sets is what completes the handshake, and
     * it is nowhere in the URL that endpoint handed back.
     *
     * The second assertion is the mechanism stated as a property rather than as prose. If the cookie
     * value ever appears inside the state again — as a claim, as a prefix, as anything — this fails, and
     * the reviewer's probe would not have to be run by hand to find out.
     */
    public function test_the_secret_set_by_the_authorize_endpoint_completes_the_handshake(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeader('X-Workspace-Id', $this->workspace->id)
            ->postJson('/api/publishing/connections/youtube/authorize')
            ->assertOk();

        $cookie = $response->getCookie(OAuthStateService::HANDSHAKE_COOKIE, decrypt: false);

        $this->assertNotNull($cookie, 'the authorize endpoint must bind the handshake to this browser');

        $this->handshake = (string) $cookie->getValue();

        parse_str((string) parse_url((string) $response->json('data.authorize_url'), PHP_URL_QUERY), $query);

        // The whole BODY, not merely the state: the secret belongs in the response's cookie and nowhere
        // else, so a future field carrying it — for a client that "needs it" — fails here rather than in
        // a review somebody has to remember to do.
        $this->assertStringNotContainsString(
            $this->handshake,
            (string) $response->getContent(),
            'the browser binding must not be readable out of the response that sets it',
        );

        $this->hitCallback('youtube', 'code-1', (string) $query['state'])
            ->assertRedirectContains('connection=connected');

        $this->assertSame(1, PlatformConnection::query()->count());
    }

    /**
     * A STATE IN THE PREVIOUS FORMAT IS REFUSED AS MALFORMED, NOT MISREAD.
     *
     * The version field earning its keep for the first time. A v1 payload carried no binding digest, so a
     * parser that skipped the version check would find no `bh`, and whatever it then did with that
     * absence would be a decision nobody made. It is signed with the REAL key here, so nothing but the
     * version stands between it and being read.
     *
     * The practical cost is one re-click for anybody mid-handshake when this deploys.
     */
    public function test_a_state_in_the_previous_format_is_refused_as_malformed(): void
    {
        $legacy = $this->reSign($this->mintState(), fn (array $claims): array => [
            'v' => 1,
            'jti' => $claims['jti'],
            'u' => $claims['u'],
            'w' => $claims['w'],
            'p' => $claims['p'],
            'iat' => $claims['iat'],
            'exp' => $claims['exp'],
        ], sign: true, key: (string) config('app.key'));

        $this->hitCallback('youtube', 'code-1', $legacy)
            ->assertRedirectContains('reason=' . OAuthStateRejected::MALFORMED);

        $this->assertSame(0, PlatformConnection::withTrashed()->count());
    }

    /** A cookie from a DIFFERENT handshake is no better than none: the secret has to be the same one. */
    public function test_a_binding_from_another_handshake_does_not_unlock_a_state(): void
    {
        $state = $this->mintState();

        // A second handshake the attacker started legitimately, whose cookie they do hold.
        $this->mintState();

        $this->withUnencryptedCookie(OAuthStateService::HANDSHAKE_COOKIE, (string) $this->handshake)
            ->get('/oauth/youtube/callback?' . http_build_query([
                'code' => 'stolen-code',
                'state' => $state,
            ]))
            ->assertRedirectContains('reason=' . OAuthStateRejected::BROWSER_MISMATCH);

        app(TenantContext::class)->set($this->workspace);

        $this->assertSame(0, PlatformConnection::withTrashed()->count());
    }

    /**
     * A REFUSED BINDING DOES NOT SPEND THE NONCE.
     *
     * The reason the check sits before the ledger. If it did not, anybody holding a copy of a state
     * could burn it without being able to use it — the victim would then be told their link had already
     * been used, and a defence against theft would have become a free denial of service.
     */
    public function test_a_refused_binding_leaves_the_state_spendable_by_its_own_browser(): void
    {
        $state = $this->mintState();

        $this->get('/oauth/youtube/callback?' . http_build_query([
            'code' => 'stolen-code',
            'state' => $state,
        ]))->assertRedirectContains('reason=' . OAuthStateRejected::BROWSER_MISMATCH);

        app(TenantContext::class)->set($this->workspace);

        // THE POINT: the person who actually started this can still finish it.
        $this->hitCallback('youtube', 'code-1', $state)
            ->assertRedirectContains('connection=connected');

        $this->assertSame(1, PlatformConnection::query()->count());
    }

    /** And the callback clears the binding, so it cannot serve a second handshake. */
    public function test_the_callback_clears_the_browser_binding(): void
    {
        $response = $this->hitCallback('youtube', 'code-1', $this->mintState());

        $cookie = $response->getCookie(OAuthStateService::HANDSHAKE_COOKIE, decrypt: false);

        $this->assertNotNull($cookie, 'the callback must expire the binding cookie');
        $this->assertSame('', (string) $cookie->getValue());
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * THE CREATOR COMES FROM THE SIGNED STATE, NOT FROM WHOEVER THE REQUEST LOOKS LIKE.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * Every other test in this suite authenticates as the same user who minted the state, which makes
     * the two sources INDISTINGUISHABLE — an implementation reading `auth()->id()` would pass all of
     * them. So this one pulls them apart: a stranger is logged in, the state was minted for the owner,
     * and the connection must be attributed to the owner.
     *
     * It is not a hypothetical distinction. Until B2's review a globally prepended `LogMiddleware`
     * called `Auth::login()` for a hardcoded id on every request, ahead of every guard — so the callback
     * would have looked authenticated as somebody unrelated, and `HasCreator` would have handed them
     * ownership (and policy rights) over an account they had never seen.
     */
    public function test_the_connection_is_attributed_to_the_state_and_not_to_the_authenticated_user(): void
    {
        $stranger = User::factory()->create();

        $state = $this->mintState(user: $this->owner);

        $this->actingAs($stranger)
            ->hitCallback('youtube', 'code-1', $state)
            ->assertRedirectContains('connection=connected');

        $connection = PlatformConnection::query()->sole();

        $this->assertSame(
            $this->owner->id,
            $connection->creator_id,
            'the creator must come from the signed state; the authenticated user is not evidence here',
        );
        $this->assertNotSame($stranger->id, $connection->creator_id);
    }

    /** Beginning a handshake needs a logged-in member with a workspace, like every other write. */
    public function test_beginning_a_handshake_requires_authentication_and_a_workspace(): void
    {
        $this->postJson('/api/publishing/connections/youtube/authorize')->assertUnauthorized();

        // Authenticated but naming no workspace: RequireWorkspace refuses before anything is looked up.
        $this->actingAs($this->owner)
            ->postJson('/api/publishing/connections/youtube/authorize')
            ->assertStatus(400);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────

    /**
     * A real state, minted by the real service for a real user and workspace.
     *
     * It also records the BROWSER BINDING the authorize endpoint would have set as a cookie, so that
     * `hitCallback()` can behave like the browser that started the handshake. Without that, every
     * scenario here would be refused as `oauth_browser_mismatch` before reaching the check it is about.
     */
    private function mintState(string $platform = 'youtube', ?User $user = null): string
    {
        $states = app(OAuthStateService::class);

        $state = $states->issue(
            $user ?? $this->owner,
            $this->workspace,
            PublishingPlatform::from($platform),
        );

        $this->handshake = $state->browserSecret;

        return $states->encode($state);
    }

    /**
     * Take a real state apart, change its claims, and put it back together — optionally re-signing with
     * a key of our choosing.
     *
     * `sign: false` leaves the ORIGINAL signature over a payload that no longer matches it, which is
     * exactly what tampering looks like on the wire. `sign: true` with a foreign key is the forgery that
     * does not need one of ours to start from.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    private function reSign(string $state, callable $mutate, bool $sign, ?string $key = null): string
    {
        [$payload, $signature] = explode('.', $state);

        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);

        $newPayload = rtrim(strtr(base64_encode((string) json_encode($mutate($claims), JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');

        if (!$sign) {
            return $newPayload . '.' . $signature;
        }

        // The same construction the service uses, under a different key — so this fails on the KEY and
        // not on a mismatched algorithm, which would prove nothing.
        $raw = (string) $key;
        $raw = str_starts_with($raw, 'base64:') ? (string) base64_decode(substr($raw, 7), true) : $raw;
        $derived = hash_hmac('sha256', 'taskio.publishing.oauth-state.v1', $raw, true);

        $forged = rtrim(strtr(base64_encode(hash_hmac('sha256', $newPayload, $derived, true)), '+/', '-_'), '=');

        return $newPayload . '.' . $forged;
    }

    /**
     * The callback as the platform makes it — from the browser that started the handshake.
     *
     * The binding cookie is sent UNENCRYPTED because it is in `encryptCookies(except: …)`: it is set by
     * the `api` group, which does not encrypt, and read by the `web` group, which otherwise would try to
     * decrypt. Sending it the way the framework's `withCookie()` would is sending something the
     * application will not recognise.
     */
    private function hitCallback(string $platform, ?string $code, string $state, ?string $error = null)
    {
        $response = $this
            ->withUnencryptedCookie(OAuthStateService::HANDSHAKE_COOKIE, (string) $this->handshake)
            ->get('/oauth/' . $platform . '/callback?' . http_build_query(array_filter([
                'code' => $code,
                'state' => $state,
                'error' => $error,
            ])));

        // The callback clears the tenant context on purpose; the suite shares one singleton where
        // production has one per request. See PublishingConnectionLifecycleTest::hitCallback().
        app(TenantContext::class)->set($this->workspace);

        return $response;
    }

    private function fakeSuccessfulHandshake(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fake-access-state-suite',
                'refresh_token' => 'fake-refresh-state-suite',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/youtube.upload',
            ]),
            'googleapis.com/youtube/v3/*' => Http::response([
                'items' => [['id' => 'UCstate_suite_channel', 'snippet' => ['title' => 'State Suite']]],
            ]),
            'graph.facebook.com/*' => Http::response([
                'access_token' => 'fake-access-meta-state-suite',
                'expires_in' => 5183944,
                'id' => '1234567890',
                'name' => 'State Suite Page',
            ]),
        ]);
    }
}
