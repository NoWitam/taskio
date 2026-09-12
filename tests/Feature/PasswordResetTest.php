<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Auth\Mail\PasswordResetMail;
use App\Modules\Auth\Models\GroupPermission;
use App\Modules\Auth\Services\AuthContextCache;
use App\Modules\Workspaces\Models\Workspace;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Password reset (R4 / D5).
 *
 * The two properties these tests exist to defend, both of which fail SILENTLY if broken:
 *
 *  1. THE FORGOT FORM NEVER CONFIRMS AN ADDRESS. Known, unknown and cooled-down all produce
 *     byte-identical answers. A regression here does not break a screen; it quietly turns
 *     the form into a membership check against the user table.
 *
 *  2. A RESET ENDS EVERY OTHER SESSION. That is the whole reason the feature was pulled into
 *     R4: after it, an account holds live OAuth tokens to the company's channels, and a
 *     password change that leaves a stolen bearer token alive has not recovered anything.
 *     Tests that only assert "the password changed" go green against exactly that bug.
 *
 *  3. THE ANSWER TAKES THE SAME TIME TO ARRIVE. The silence in (1) is only worth having if
 *     the clock keeps it: a reply that comes back 30 ms later for a real account has said
 *     what the sentence would not. Padding is invisible to every assertion above, which is
 *     why it needs its own.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // THE CLOCK IS FAKED, THE FLOOR IS NOT. Both entry points pad themselves up to
        // `auth.passwords.timebox_ms` through `Timebox`, which sleeps via
        // `Illuminate\Support\Sleep` — so faking it keeps every pad OBSERVABLE (the
        // durations are recorded, and `whenFakingSleep` can inspect the process AT the
        // sleep) while a suite that would otherwise spend half a minute asleep runs at
        // full speed. The framework un-fakes it in its own teardown; nothing leaks.
        Sleep::fake();

        // SET, not inherited. `php artisan test` reads the developer's `.env` (there is no
        // `.env.testing`), so a test asserting on the size of the pad must name the value
        // it is asserting against or it passes according to what somebody last exported.
        config(['auth.passwords.timebox_ms' => 1000]);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────
    // FORGOT — the address is never confirmed or denied
    // ─────────────────────────────────────────────────────────────────────────────────────

    public function test_forgot_password_answers_identically_for_known_and_unknown_addresses(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'known@example.com']);

        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'known@example.com']);
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com']);

        $known->assertOk();
        $unknown->assertOk();

        // Byte-for-byte, not "both are 200": a differing message is the leak.
        $this->assertSame($known->getContent(), $unknown->getContent());
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());

        // …and the answer says nothing the broker said. `passwords.sent` asserts a send and
        // `passwords.user` denies the account; neither may reach the wire.
        $this->assertSame(__('passwords.requested'), $known->json('message'));
        $this->assertNotSame(__('passwords.sent'), $known->json('message'));
        $this->assertNotSame(__('passwords.user'), $unknown->json('message'));
    }

    public function test_forgot_password_mails_a_link_only_for_a_real_account(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'real@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'real@example.com'])->assertOk();
        $this->postJson('/api/auth/forgot-password', ['email' => 'ghost@example.com'])->assertOk();

        Mail::assertSent(PasswordResetMail::class, 1);
        Mail::assertSent(
            PasswordResetMail::class,
            fn (PasswordResetMail $mail) => $mail->hasTo($user->email)
        );
    }

    public function test_forgot_password_hides_the_per_address_cooldown(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'known@example.com']);

        $first = $this->postJson('/api/auth/forgot-password', ['email' => 'known@example.com']);
        $second = $this->postJson('/api/auth/forgot-password', ['email' => 'known@example.com']);

        // The broker refused the second link (60s cooldown) — but saying so would reveal both
        // that the account exists AND that somebody just asked for it.
        Mail::assertSent(PasswordResetMail::class, 1);
        $second->assertOk();
        $this->assertSame($first->getContent(), $second->getContent());
    }

    public function test_forgot_password_is_throttled_per_ip(): void
    {
        Mail::fake();

        // The route bucket is 5/minute. Distinct addresses, so this is the IP counter and not
        // the broker's per-address cooldown that is being exercised.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => "user{$i}@example.com"])
                ->assertOk();
        }

        $this->postJson('/api/auth/forgot-password', ['email' => 'user5@example.com'])
            ->assertStatus(429);
    }

    public function test_forgot_password_refuses_a_malformed_address_without_consulting_the_table(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'not-an-address'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        // A well-formed address that belongs to nobody must NOT be a validation failure —
        // that is what an `exists:users` rule would turn this endpoint into.
        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk();
    }

    public function test_forgot_password_still_finds_a_user_outside_a_stale_workspace_header(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach($owner->id);

        // A browser that was signed into another workspace still has `taskio_workspace` in
        // localStorage, so the SPA sends X-Workspace-Id on the PUBLIC reset screens too. If a
        // workspace became active here, User's WorkspaceMemberScope would hide every
        // non-member and the mail would silently never be sent. ResolveWorkspace no-ops
        // without an authenticated user; this pins that it stays that way.
        $stranger = User::factory()->create(['email' => 'stranger@example.com']);

        $this->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/auth/forgot-password', ['email' => 'stranger@example.com'])
            ->assertOk();

        Mail::assertSent(
            PasswordResetMail::class,
            fn (PasswordResetMail $mail) => $mail->hasTo($stranger->email)
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────────────
    // THE CLOCK — the second half of "the address is never confirmed or denied"
    // ─────────────────────────────────────────────────────────────────────────────────────

    public function test_every_outcome_of_both_endpoints_is_padded_up_to_the_configured_floor(): void
    {
        Mail::fake();
        $asked = User::factory()->create(['email' => 'asked@example.com']);
        $redeemer = User::factory()->create(['email' => 'redeemer@example.com']);

        // Far above what the work can cost at BCRYPT_ROUNDS=4, so "the pad happened" is not
        // a race against whichever machine runs this.
        config(['auth.passwords.timebox_ms' => 5000]);

        // A hit and a miss on the forgot form: the pair whose DURATIONS are the membership
        // test, once the wording stopped being one.
        $this->postJson('/api/auth/forgot-password', ['email' => 'asked@example.com'])->assertOk();
        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();

        // A refused link. The pad has to survive the thrown 422 — an exception that escapes
        // the timebox unpadded is the same oracle in another shape.
        $this->postJson('/api/auth/reset-password', [
            'token' => 'a-token-that-was-never-issued',
            'email' => 'asked@example.com',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertUnprocessable();

        // A real link, redeemed (its own forgot request pads too — hence five, not four).
        // THIS IS THE CASE THE FRAMEWORK'S TIMEBOX DOES NOT COVER: `PasswordBroker::reset()`
        // calls `returnEarly()` on success, so relying on the broker's floor would leave
        // exactly this branch unpadded.
        $token = $this->requestTokenFor($redeemer);
        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $redeemer->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        Sleep::assertSlept(
            fn (CarbonInterval $slept) => $slept->totalMilliseconds >= 4000,
            times: 5,
        );
    }

    public function test_nothing_sleeps_while_holding_a_transaction_this_flow_opened(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'idle@example.com']);

        // RefreshDatabase runs the whole test inside one transaction, so "none of ours open"
        // means "back at the level this test started from", not zero.
        $baseline = DB::transactionLevel();

        /** @var list<int> $levels */
        $levels = [];
        Sleep::whenFakingSleep(function () use (&$levels) {
            $levels[] = DB::transactionLevel();
        });

        // THE REFUSED ATTEMPT IS THE CASE THIS EXISTS FOR. It writes nothing, it is the one
        // an attacker gets, and it is repeatable at will — so a transaction opened around
        // the whole redemption would leave a Postgres connection "idle in transaction" for
        // a second per hostile request and the pool, not the password, is what runs out.
        $this->postJson('/api/auth/reset-password', [
            'token' => 'a-token-that-was-never-issued',
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertUnprocessable();

        // …and the successful one, where a transaction really is opened: it must close
        // before the pad, not around it.
        $token = $this->requestTokenFor($user);
        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->assertNotEmpty($levels, 'Nothing was padded at all, so this test pinned nothing.');
        $this->assertSame(
            [$baseline],
            array_values(array_unique($levels)),
            'Something slept while a transaction was open. Both the service timebox and the '
            . "broker's inner one sleep; neither may sit inside DB::transaction().",
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────────────
    // THE MAIL — language, and the link it carries
    // ─────────────────────────────────────────────────────────────────────────────────────

    public function test_reset_mail_is_written_in_the_language_the_account_chose(): void
    {
        Mail::fake();
        // Named explicitly rather than inherited: APP_LOCALE comes from the developer's .env
        // (it is `pl` on this installation), so a test that assumed a default would pass or
        // fail according to what somebody last switched on by hand. `app.default_locale` is
        // the twin of APP_LOCALE that `App::setLocale()` does not rewrite mid-request —
        // setting `app.locale` here would be overwritten by SetUserLocale before the read.
        config(['app.default_locale' => 'en']);

        User::factory()->create(['email' => 'polish@example.com', 'locale' => 'pl']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'polish@example.com'])->assertOk();

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) {
            $this->assertSame('pl', $mail->locale);
            $this->assertStringContainsString(
                __('passwords.mail.action', [], 'pl'),
                $mail->render()
            );

            return true;
        });
    }

    public function test_reset_mail_falls_back_to_the_app_locale_when_none_was_chosen(): void
    {
        Mail::fake();
        config(['app.default_locale' => 'en']);

        User::factory()->create(['email' => 'undecided@example.com', 'locale' => null]);

        $this->postJson('/api/auth/forgot-password', ['email' => 'undecided@example.com'])->assertOk();

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) {
            $this->assertSame('en', $mail->locale);

            return true;
        });
    }

    public function test_reset_mail_language_ignores_the_requesting_clients_locale(): void
    {
        Mail::fake();
        config(['app.default_locale' => 'en']);

        User::factory()->create(['email' => 'victim@example.com', 'locale' => null]);

        // Anybody may POST any address here. If X-Client-Locale reached the mail, a stranger
        // would choose the language of a letter delivered to somebody else's inbox.
        $this->withHeader('X-Client-Locale', 'pl')
            ->postJson('/api/auth/forgot-password', ['email' => 'victim@example.com'])
            ->assertOk();

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) {
            $this->assertSame('en', $mail->locale);

            return true;
        });
    }

    public function test_reset_mail_links_into_the_spa_reset_route(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'linked@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'linked@example.com'])->assertOk();

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use ($user) {
            $this->assertStringStartsWith(
                config('app.url') . '/next/reset-password?',
                $mail->resetUrl
            );
            $this->assertStringContainsString('token=', $mail->resetUrl);
            $this->assertStringContainsString(urlencode($user->email), $mail->resetUrl);

            return true;
        });
    }

    // ─────────────────────────────────────────────────────────────────────────────────────
    // RESET — the new password, and the end of every other session
    // ─────────────────────────────────────────────────────────────────────────────────────

    public function test_reset_with_a_mailed_link_changes_the_password_and_returns_a_working_session(): void
    {
        $user = User::factory()->create(['email' => 'resetter@example.com']);
        $token = $this->requestTokenFor($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['message', 'token', 'user' => ['id'], 'permissions', 'workspaces'])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('message', __('passwords.reset'));

        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));

        // The returned token is a real session, not decoration.
        $this->withHeader('Authorization', 'Bearer ' . $response->json('token'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        // …and the old password no longer opens the door.
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertUnprocessable();
    }

    public function test_reset_revokes_every_pre_existing_session(): void
    {
        $user = User::factory()->create(['email' => 'compromised@example.com']);

        // Two live sessions: the rightful owner's and the intruder's. This is the D5 case —
        // a stolen bearer token survives a password change unless something deletes the row.
        $stolen = $user->createToken('stolen')->plainTextToken;
        $other = $user->createToken('other-device')->plainTextToken;

        $token = $this->requestTokenFor($user);

        $fresh = $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk()->json('token');

        // Exactly one token survives: the one just issued.
        $this->assertSame(1, PersonalAccessToken::query()->where('tokenable_id', $user->id)->count());
        $this->assertNotNull(PersonalAccessToken::findToken($fresh));
        $this->assertNull(PersonalAccessToken::findToken($stolen));
        $this->assertNull(PersonalAccessToken::findToken($other));

        // Asserted over HTTP too, because "the row is gone" and "the request is refused" are
        // two claims and only the second one matters to the intruder.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$stolen}")
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_reset_rotates_the_remember_token(): void
    {
        $user = User::factory()->create(['email' => 'remembered@example.com']);
        $user->forceFill(['remember_token' => 'a-remembered-value'])->save();

        $token = $this->requestTokenFor($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->assertNotSame('a-remembered-value', $user->fresh()->remember_token);
    }

    public function test_reset_link_cannot_be_used_twice(): void
    {
        $user = User::factory()->create(['email' => 'twice@example.com']);
        $token = $this->requestTokenFor($user);

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ];

        $this->postJson('/api/auth/reset-password', $payload)->assertOk();

        $this->postJson('/api/auth/reset-password', [
            ...$payload,
            'password' => 'yet-another-password',
            'password_confirmation' => 'yet-another-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);

        // The second attempt changed nothing.
        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));
    }

    public function test_reset_link_expires(): void
    {
        $user = User::factory()->create(['email' => 'slow@example.com']);
        $token = $this->requestTokenFor($user);

        $this->travel((int) config('auth.passwords.users.expire') + 1)->minutes();

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_reset_does_not_reveal_whether_the_address_exists(): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        // The broker checks the USER before the TOKEN, so without the collapse these two
        // answer `passwords.user` and `passwords.token` respectively — an oracle on the
        // second endpoint that would undo the care taken on the first.
        $known = $this->postJson('/api/auth/reset-password', [
            'token' => 'a-token-that-was-never-issued',
            'email' => 'known@example.com',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $unknown = $this->postJson('/api/auth/reset-password', [
            'token' => 'a-token-that-was-never-issued',
            'email' => 'nobody@example.com',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $known->assertUnprocessable();
        $unknown->assertUnprocessable();
        $this->assertSame($known->getContent(), $unknown->getContent());
    }

    public function test_reset_enforces_the_same_password_rules_as_the_rest_of_the_app(): void
    {
        $user = User::factory()->create(['email' => 'weak@example.com']);
        $token = $this->requestTokenFor($user);

        // Too short (the app-wide min:8).
        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        // Mistyped confirmation.
        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-different-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        // Neither attempt spent the link.
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_reset_password_is_throttled_per_ip(): void
    {
        // The route bucket is 10/minute. Every attempt here is a dead link, so what is being
        // exercised is the IP counter and not any per-account state.
        $payload = [
            'token' => 'a-token-that-was-never-issued',
            'email' => 'nobody@example.com',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ];

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/reset-password', $payload)->assertUnprocessable();
        }

        $this->postJson('/api/auth/reset-password', $payload)->assertStatus(429);
    }

    public function test_the_two_endpoints_keep_separate_rate_limit_buckets(): void
    {
        Mail::fake();

        // THE THIRD ARGUMENT OF `throttle:…` IS LOAD-BEARING AND INVISIBLE. Without a key
        // prefix, ThrottleRequests signs an unauthenticated request as `domain|ip` — the
        // route plays no part — so EVERY throttled public route shares one counter per IP.
        //
        // This is the direction that detects it. Ten refused links fill the reset route's own
        // bucket (10/min); a shared counter would then be at 10, over the forgot route's limit
        // of 5, and the request below would 429 — locking somebody out of asking for a link
        // because a script was busy trying dead ones.
        $payload = [
            'token' => 'a-token-that-was-never-issued',
            'email' => 'nobody@example.com',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ];

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/reset-password', $payload)->assertUnprocessable();
        }

        $this->postJson('/api/auth/forgot-password', ['email' => 'still-allowed@example.com'])
            ->assertOk();
    }

    public function test_a_flood_of_link_requests_does_not_lock_out_somebody_redeeming_a_link(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'holder@example.com']);
        $token = $this->requestTokenFor($user);

        // Fill what is left of the forgot bucket (5/minute; the request above spent one).
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => "flood{$i}@example.com"])
                ->assertOk();
        }

        $this->postJson('/api/auth/forgot-password', ['email' => 'flood5@example.com'])
            ->assertStatus(429);

        // The person who already holds a mailed link is unaffected. Note this direction alone
        // would NOT catch a shared bucket: the limits are asymmetric (5 vs 10), so five spent
        // attempts stay under the reset route's ceiling either way. The test above is the one
        // that bites; this one states the property in the direction a user would feel it.
        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();
    }

    public function test_reset_invalidates_the_cached_permission_set(): void
    {
        $user = User::factory()->create(['email' => 'cached@example.com']);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        $this->actingAs($user)->postJson("/api/workspaces/{$workspace->id}/groups", [
            'name' => 'Editors',
            'user_ids' => [$user->id],
            'permissions' => ['view_tasks'],
        ])->assertCreated();

        $this->app['auth']->forgetGuards();

        $cache = app(AuthContextCache::class);
        $this->assertSame(['view_tasks'], $cache->permissions($user, $workspace->id));

        // Revoked straight in the table, bypassing the service that would invalidate on its
        // own — so the ONLY thing that can bust this cache is the reset.
        GroupPermission::query()->delete();
        $this->assertSame(
            ['view_tasks'],
            $cache->permissions($user, $workspace->id),
            'The cache was not warm, so what follows would pass with no invalidation at all.'
        );

        $token = $this->requestTokenFor($user);
        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        // A recovered account must not keep answering from a permission set compiled while
        // somebody else held it — every session was cut, and the compiled context goes too.
        $this->assertSame([], $cache->permissions($user->fresh(), $workspace->id));
    }

    public function test_reset_works_from_a_browser_carrying_a_stale_workspace_header(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach($owner->id);

        // The twin of the forgot-form case: a browser signed into another workspace still has
        // `taskio_workspace` in localStorage, so the SPA sends X-Workspace-Id on these PUBLIC
        // screens too — naming a workspace this account is NOT a member of. If that became
        // active, User's WorkspaceMemberScope would hide the account and the link would be
        // refused as if it were forged.
        $stranger = User::factory()->create(['email' => 'outsider@example.com']);
        $stolen = $stranger->createToken('stolen')->plainTextToken;

        $token = $this->requestTokenFor($stranger);

        $this->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/auth/reset-password', [
                'token' => $token,
                'email' => $stranger->email,
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])
            ->assertOk();

        // Everything landed on the CENTRAL database — the one this flow's three tables live
        // in — and not in whatever a resolved workspace would have pointed at.
        $this->assertTrue(Hash::check('a-brand-new-password', $stranger->fresh()->password));
        $this->assertNull(PersonalAccessToken::findToken($stolen));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $stranger->email]);
    }

    public function test_the_store_never_holds_the_mailed_token_itself(): void
    {
        $user = User::factory()->create(['email' => 'hashed@example.com']);
        $plain = $this->requestTokenFor($user);

        $stored = (string) DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->value('token');

        // A leaked database dump must not be a pile of usable reset links. The column holds a
        // bcrypt hash of the value in the mail: verifiable, not replayable.
        $this->assertNotSame('', $stored, 'No token row was stored at all.');
        $this->assertNotSame($plain, $stored);
        $this->assertStringNotContainsString($plain, $stored);
        $this->assertTrue(Hash::check($plain, $stored));
    }

    /**
     * Ask for a link the way a person does, and read the token back out of the mail that was
     * actually sent — so the test exercises the link we really deliver, not one minted beside
     * the flow. Returns the PLAINTEXT token (the store holds only its hash).
     */
    private function requestTokenFor(User $user): string
    {
        Mail::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        $token = null;

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$token) {
            parse_str((string) parse_url($mail->resetUrl, PHP_URL_QUERY), $query);
            $token = $query['token'] ?? null;

            return true;
        });

        $this->assertIsString($token, 'No reset token reached the mail.');

        return $token;
    }

    public function test_the_link_lifetime_two_frontend_strings_promise_is_still_the_configured_one(): void
    {
        // A TRIPWIRE, NOT A BEHAVIOUR TEST. The expiry itself is already covered by
        // `test_reset_link_expires`, which reads the config and so follows any change. These
        // two do not: they state the number in prose, in a catalog no backend test can see.
        //
        //   resources/js/next/app/i18n/en.ts → auth.forgot.sent.hint
        //     "The link is valid for 60 minutes and can be used once."
        //   resources/js/next/app/i18n/pl.ts → auth.forgot.sent.hint
        //     "Link jest ważny 60 minut i można go użyć raz."
        //
        // Changing `expire` without them leaves the app telling people a duration it no
        // longer honours — nothing else in either suite goes red. (The MAIL is safe: its
        // `:minutes` placeholder reads this same key.)
        $this->assertSame(
            60,
            (int) config('auth.passwords.users.expire'),
            'The reset link lifetime changed. Update auth.forgot.sent.hint in BOTH '
            . 'resources/js/next/app/i18n/en.ts and pl.ts — they name the old number in '
            . 'prose — and then this line.'
        );
    }

    public function test_the_broker_is_wired_to_the_central_users_table(): void
    {
        // Cheap guard against a config drift that would otherwise only show up as "the link
        // never works": the table this migration creates is the one the broker reads.
        $this->assertSame('password_reset_tokens', config('auth.passwords.users.table'));

        $user = User::factory()->create();
        Password::createToken($user);

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }
}
