<?php

namespace Tests\Feature;

use App\Http\Middleware\SetUserLocale;
use App\Models\User;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Tests\TestCase;

/**
 * THE SERVER ANSWERS IN THE LANGUAGE THE USER CHOSE.
 *
 * `users.locale` was written by the switcher and read by nothing, so every `__()` on the server
 * rendered in `APP_LOCALE` regardless of who was asking. On a Polish installation that is invisible —
 * the bug only appears when somebody switches the interface to English and gets Polish sentences back
 * from the API: validation messages, domain refusals, the relation vocabulary's own labels.
 *
 * These tests exercise the MECHANISM rather than any one message, because the failure was app-wide and
 * a fix that happened not to fire would look exactly like a fix that did — nothing in the suite was
 * asserting server prose against a user's locale, which is precisely why the gap survived this long.
 */
class UserLocaleTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    /**
     * The relation vocabulary is a good probe: every label is a translated string from an enum.
     *
     * @param  array<string, string>  $headers  e.g. what the client says it is rendering
     */
    private function vocabularyLabel(User $user, Workspace $workspace, array $headers = []): string
    {
        app(TenantContext::class)->set($workspace);
        $base = KnowledgeBase::factory()->create(['workspace_id' => $workspace->id]);
        app(TenantContext::class)->clear();

        // `withHeaders()` MERGES INTO THE DEFAULTS AND KEEPS THEM for every later request in the same
        // test method. Without this flush a probe that once said "I am rendering Polish" would keep
        // saying it, and the test that checks a silent client falls back to the installation would be
        // answered by its own previous header — which is exactly how it first went green for the wrong
        // reason here.
        $this->flushHeaders();

        $body = $this->actingAs($user)
            ->withHeaders($headers + ['X-Workspace-Id' => $workspace->id])
            ->getJson("/api/knowledge/bases/{$base->id}")
            ->assertOk()
            ->json('data.relation_vocabulary');

        return collect($body)->firstWhere('id', 'member_of')['label'];
    }

    /** @return array<string, string> */
    private function rendering(string $locale): array
    {
        return [SetUserLocale::CLIENT_HEADER => $locale];
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- the mechanism ----------------------------------------------------------------

    public function test_a_user_who_chose_english_gets_english_from_the_server(): void
    {
        config()->set('app.locale', 'pl');

        $user = User::factory()->create(['locale' => 'en']);
        $workspace = $this->workspaceFor($user);

        $this->assertSame('is a member of', $this->vocabularyLabel($user, $workspace));

        // ...and the same for a validation message, which is the surface a user meets most often.
        $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/knowledge/bases', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'The name field is required.');
    }

    public function test_a_user_who_chose_polish_gets_polish_from_the_server(): void
    {
        // The INSTALLATION is English here, so a pass cannot come from the environment default —
        // it can only come from the user's own preference being read.
        config()->set('app.locale', 'en');

        $user = User::factory()->create(['locale' => 'pl']);
        $workspace = $this->workspaceFor($user);

        $this->assertSame('jest członkiem', $this->vocabularyLabel($user, $workspace));

        $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/knowledge/bases', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Pole name jest wymagane.');
    }

    /** Nobody logged in: the installation's own locale, never a browser header. */
    public function test_an_unauthenticated_request_uses_the_application_locale(): void
    {
        config()->set('app.locale', 'pl');

        $this->withHeader('Accept-Language', 'en-GB,en;q=0.9')
            ->postJson('/api/auth/login', ['email' => 'nobody@example.test'])
            ->assertStatus(422)
            // Polish, from the installation — the browser asked for English and was not consulted.
            ->assertJsonPath('errors.password.0', 'Pole password jest wymagane.');
    }

    /** Switching takes effect from the NEXT request — the middleware reads the column each time. */
    public function test_switching_locale_takes_effect_on_the_following_request(): void
    {
        config()->set('app.locale', 'pl');

        $user = User::factory()->create(['locale' => 'pl']);
        $workspace = $this->workspaceFor($user);

        $this->assertSame('jest członkiem', $this->vocabularyLabel($user, $workspace));

        $this->actingAs($user)->putJson('/api/user/locale', ['locale' => 'en'])->assertOk();

        $this->assertSame('is a member of', $this->vocabularyLabel($user->refresh(), $workspace));
    }

    // ---- the person who never chose ------------------------------------------------------

    /**
     * A NEW user has made no choice, so they follow the installation — not a default some old
     * migration guessed on their behalf.
     *
     * This is the regression the nullable column exists to prevent. The switcher's column carried
     * `default 'en'` while nothing read it, so the guess was inert and disagreed with a Polish
     * installation harmlessly. The moment the middleware started reading it, that inert guess would
     * have become a live instruction: every newly invited user handed English by an installation whose
     * every other surface is Polish.
     */
    public function test_a_user_who_never_chose_follows_the_installation(): void
    {
        config()->set('app.locale', 'pl');
        app()->setLocale('pl');

        $user = User::factory()->create();

        // THE MIGRATION'S WHOLE EFFECT, in one assertion: a fresh account records no choice at all,
        // where it used to record 'en' as though somebody had made one.
        $this->assertNull($user->locale, 'a fresh account records no choice at all');

        $workspace = $this->workspaceFor($user);

        $this->assertSame('jest członkiem', $this->vocabularyLabel($user, $workspace));
    }

    /**
     * ...and an absent choice follows whatever the installation IS, in either direction.
     *
     * This is the argument against the cheaper fix — defaulting the column to `config('app.locale')`
     * at insert. That value is frozen at registration, so re-configuring the installation later would
     * move new accounts and silently leave every existing one behind, one row at a time. An absent
     * choice has nothing to freeze.
     *
     * The two installations are simulated by moving the ACTIVE locale, not just the config key: the
     * framework reads `app.locale` once at boot, so setting the config alone proves nothing — my first
     * version of this test did exactly that and passed for the wrong reason on a Polish machine.
     */
    public function test_an_absent_choice_follows_the_installation_in_either_direction(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        config()->set('app.locale', 'pl');
        app()->setLocale('pl');
        $this->assertSame('jest członkiem', $this->vocabularyLabel($user, $workspace));

        config()->set('app.locale', 'en');
        app()->setLocale('en');
        $this->assertSame('is a member of', $this->vocabularyLabel($user, $workspace));
    }

    /** An EXPLICIT choice is the opposite: it survives the installation changing underneath it. */
    public function test_an_explicit_choice_survives_a_change_of_the_installation_locale(): void
    {
        config()->set('app.locale', 'pl');

        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $this->actingAs($user)->putJson('/api/user/locale', ['locale' => 'en'])->assertOk();

        $this->assertSame('is a member of', $this->vocabularyLabel($user->refresh(), $workspace));

        config()->set('app.locale', 'en');
        $this->assertSame('is a member of', $this->vocabularyLabel($user->refresh(), $workspace));

        config()->set('app.locale', 'pl');
        $this->assertSame('is a member of', $this->vocabularyLabel($user->refresh(), $workspace), 'a choice is not a guess');
    }

    /** Existing accounts are NOT migrated backwards — a stored value is left exactly as it was found. */
    public function test_a_stored_choice_is_left_untouched(): void
    {
        config()->set('app.locale', 'pl');

        $user = User::factory()->create();
        $user->forceFill(['locale' => 'en'])->save();

        $workspace = $this->workspaceFor($user);

        $this->assertSame('en', $user->fresh()->locale);
        $this->assertSame('is a member of', $this->vocabularyLabel($user, $workspace));
    }

    // ---- the language the client is rendering -------------------------------------------

    /**
     * THE APP-WIDE DEFECT: a Polish interface reading English answers.
     *
     * The frontend resolves its own locale from `localStorage` or the browser and only ever told the
     * server on a deliberate switch of the toggle. So a user with a Polish browser got a Polish client
     * and an English server, in the same window, from first login — and could not fix it from the UI,
     * because the switcher's early return made clicking the already-lit "PL" do nothing at all.
     *
     * The fix is not to persist the browser's guess (that would record a guess as a choice); it is to
     * let the client state the language it is RENDERING and honour that when nobody has chosen.
     */
    public function test_a_user_who_never_chose_gets_the_language_their_client_is_rendering(): void
    {
        // The installation is English, so a Polish answer can only have come from the client saying so.
        config()->set('app.locale', 'en');
        app()->setLocale('en');

        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $this->assertNull($user->locale, 'the premise: this user has chosen nothing');

        $this->assertSame(
            'jest członkiem',
            $this->vocabularyLabel($user, $workspace, $this->rendering('pl')),
        );

        // The surface a user meets most often, and the one that was reported.
        $this->actingAs($user)
            ->withHeaders($this->rendering('pl') + ['X-Workspace-Id' => $workspace->id])
            ->postJson('/api/knowledge/bases', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Pole name jest wymagane.');
    }

    /** Nothing about this WRITES the column: a rendered language is still not a choice. */
    public function test_the_language_the_client_renders_is_never_persisted_as_a_choice(): void
    {
        config()->set('app.locale', 'en');
        app()->setLocale('en');

        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $this->assertSame('is a member of', $this->vocabularyLabel($user, $workspace));

        $this->vocabularyLabel($user, $workspace, $this->rendering('pl'));

        $this->assertNull($user->fresh()->locale, 'a guess must never be recorded as a decision');

        // ...so a later client that says nothing follows the installation again, rather than the
        // language some earlier browser happened to render.
        //
        // The reset is the HARNESS, not the mechanism: this middleware only ever SETS a locale, it
        // never restores one, because in production every request boots its own process. Inside one
        // test method the requests share an application, so the previous probe's `pl` is still on the
        // translator and would answer this one. The tests above do the same for the same reason.
        app()->setLocale('en');

        $this->assertSame('is a member of', $this->vocabularyLabel($user, $workspace));
    }

    /** A CHOICE outranks what the client renders — that is the whole reason the header sits below it. */
    public function test_a_stored_choice_beats_the_language_the_client_renders(): void
    {
        config()->set('app.locale', 'en');
        app()->setLocale('en');

        $user = User::factory()->create(['locale' => 'pl']);
        $workspace = $this->workspaceFor($user);

        $this->assertSame(
            'jest członkiem',
            $this->vocabularyLabel($user, $workspace, $this->rendering('en')),
            'a client rendering English cannot overrule a user who chose Polish',
        );
    }

    /**
     * THE SAME RULE, POINTED AT THE ACCOUNTS THAT ACTUALLY EXIST — and it is a known limitation.
     *
     * `locale` carried `default 'en'` from the day the column was added until it was made nullable, and
     * that migration deliberately did NOT backfill: it could not tell a stamp from a preference. So on
     * this installation every existing row holds 'en' while `APP_LOCALE` is 'pl' — which is the state
     * the app-wide "server answers in English" report came from, and this test is what it looks like.
     *
     * The client header does not rescue those accounts, ON PURPOSE: nothing here may second-guess a
     * stored value, or "an explicit choice wins" stops meaning anything. They are corrected either by
     * the user re-asserting the language in the switcher (which now writes the column even when the
     * language is already the active one) or by an owner-approved data decision to null the stamps.
     */
    public function test_a_stamped_english_row_still_answers_in_english(): void
    {
        config()->set('app.locale', 'pl');
        app()->setLocale('pl');

        $user = User::factory()->create();
        $user->forceFill(['locale' => 'en'])->save(); // as the pre-nullable default left it

        $workspace = $this->workspaceFor($user);

        $this->assertSame(
            'is a member of',
            $this->vocabularyLabel($user, $workspace, $this->rendering('pl')),
            'a stored value is honoured even when it was never chosen — the fix for that is a choice, not a guess',
        );

        // ...and re-asserting the language IS the fix, from the same client, in one request.
        $this->actingAs($user)->putJson('/api/user/locale', ['locale' => 'pl'])->assertOk();

        $this->assertSame(
            'jest członkiem',
            $this->vocabularyLabel($user->refresh(), $workspace, $this->rendering('pl')),
        );
    }

    /**
     * PRE-AUTH, and deliberately so: the login screen renders in one language and its refusals now
     * arrive in the same one. Somebody who has never logged in has certainly never chosen.
     *
     * This is a marked behaviour change to unauthenticated responses. Note what it is NOT: the browser
     * is still not consulted — see the Accept-Language test above, which passes unchanged.
     */
    public function test_an_unauthenticated_request_follows_the_client_that_declares_its_language(): void
    {
        config()->set('app.locale', 'en');
        app()->setLocale('en');

        $this->withHeaders($this->rendering('pl'))
            ->postJson('/api/auth/login', ['email' => 'nobody@example.test'])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Pole password jest wymagane.');
    }

    // ---- the guards --------------------------------------------------------------------

    /**
     * A locale outside the supported set is IGNORED, not applied.
     *
     * The column can hold anything a past migration or a direct write put there, and `setLocale('xx')`
     * silently renders every key as its own name — an entire API answering in `knowledge.relation_types.member_of`.
     */
    public function test_an_unsupported_stored_locale_is_ignored(): void
    {
        config()->set('app.locale', 'pl');

        $user = User::factory()->create();
        $user->forceFill(['locale' => 'de'])->save();
        $workspace = $this->workspaceFor($user);

        $this->assertSame('jest członkiem', $this->vocabularyLabel($user, $workspace));
    }

    /**
     * ...and being ignored means falling through to the CLIENT, not past it to the installation.
     *
     * An unusable stored value is not a preference to respect — there is no effective choice to
     * override, so the language on the screen wins exactly as it does for a null column. Without this
     * the ordering has an unpinned branch, and the middleware's docblock would be describing a rule the
     * code does not quite follow.
     */
    public function test_an_unsupported_stored_locale_falls_through_to_the_client(): void
    {
        config()->set('app.locale', 'en');
        app()->setLocale('en');

        $user = User::factory()->create();
        $user->forceFill(['locale' => 'de'])->save();
        $workspace = $this->workspaceFor($user);

        $this->assertSame(
            'jest członkiem',
            $this->vocabularyLabel($user, $workspace, $this->rendering('pl')),
        );
    }

    /**
     * The header is USER INPUT and gets the same guard as the column — no softer.
     *
     * Anything on that wire can be anything: a stale client, a proxy, somebody with curl. A junk value
     * changes NOTHING; it falls through to the installation exactly as an unsupported column does.
     *
     * The near-misses matter as much as the junk: `pl-PL` and `PL` are NOT accepted. Folding them in
     * would be the start of the content-negotiation layer this deliberately does not have, and our own
     * client sends the exact strings from `config('app.supported_locales')`.
     */
    public function test_an_unsupported_client_locale_changes_nothing(): void
    {
        config()->set('app.locale', 'en');
        app()->setLocale('en');

        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        foreach (['de', 'pl-PL', 'PL', ' pl', '', 'knowledge.relation_types.member_of', '../../etc'] as $junk) {
            $this->assertSame(
                'is a member of',
                $this->vocabularyLabel($user, $workspace, $this->rendering($junk)),
                "a client claiming to render '{$junk}' must not move the translator",
            );
        }
    }

    /** ...and it cannot smuggle a locale past the writer either: the column is only written by choice. */
    public function test_the_client_header_does_not_write_the_column(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeaders($this->rendering('pl'))
            ->putJson('/api/user/locale', ['locale' => 'en'])
            ->assertOk();

        $this->assertSame('en', $user->fresh()->locale, 'the body is the choice; the header is not');
    }

    public function test_the_write_accepts_only_supported_locales(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)->putJson('/api/user/locale', ['locale' => 'de'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('locale');

        foreach (SetUserLocale::supported() as $locale) {
            $this->actingAs($user)->putJson('/api/user/locale', ['locale' => $locale])->assertOk();
        }
    }

    /** The list that steers the translator and the list the writer validates against are ONE list. */
    public function test_the_supported_set_comes_from_config(): void
    {
        $this->assertSame(config('app.supported_locales'), SetUserLocale::supported());
    }

    // ---- ordering + reach ----------------------------------------------------------------

    /**
     * THE HEADER REACHES THE API GROUP AND NOTHING ELSE.
     *
     * Since a client-supplied value now steers the translator, where that value is READ is part of the
     * contract. The `web` group serves the SPA shell — a Blade view whose `<html lang>` comes from
     * `app()->getLocale()` — and it must keep answering with the installation's locale: widening this
     * middleware to `web` would let an arbitrary request header choose the language of server-rendered
     * pages, and (the day any of it is translated) of mail rendered during such a request.
     *
     * A structural pin rather than a prose assertion, because the failure mode is a one-line
     * `appendToGroup('web', ...)` that no user-visible test would notice.
     */
    public function test_the_locale_middleware_is_not_applied_to_the_web_group(): void
    {
        $router = app(Router::class);
        $route = $router->getRoutes()->getByName('next');

        $this->assertNotNull($route, 'the SPA shell route is the web group in practice');

        $this->assertNotContains(
            SetUserLocale::class,
            $router->gatherRouteMiddleware($route),
            'a client-supplied locale must not steer server-rendered pages',
        );
    }

    /**
     * It reads the resolved user, so it has to run after Authenticate — the same dependency
     * ResolveWorkspace has, and the reason middleware order in this repo is load-bearing.
     */
    public function test_the_locale_middleware_runs_after_authentication(): void
    {
        $router = app(Router::class);
        $route = $router->getRoutes()->getByName('knowledge.bases.show');

        $this->assertNotNull($route);

        $middleware = $router->gatherRouteMiddleware($route);

        // Matched by PREFIX: the applied entry is the parameterised `Authenticate:sanctum`, and an
        // exact ::class comparison silently finds nothing — which would make this pin pass by
        // accident on the day the middleware moved.
        $auth = $this->indexOfPrefix($middleware, Authenticate::class);
        $locale = array_search(SetUserLocale::class, $middleware, true);

        $this->assertNotFalse($locale, 'SetUserLocale must be applied to the api group. Got: ' . implode(', ', $middleware));
        $this->assertNotNull($auth, 'Authenticate must be present. Got: ' . implode(', ', $middleware));
        $this->assertTrue(
            $auth < $locale,
            "Authenticate (#{$auth}) must run BEFORE SetUserLocale (#{$locale}) — it reads the resolved user.\nOrder: " . implode(', ', $middleware),
        );
    }

    /** @param  array<int, string>  $middleware */
    private function indexOfPrefix(array $middleware, string $class): ?int
    {
        foreach ($middleware as $index => $entry) {
            if ($entry === $class || str_starts_with($entry, $class . ':')) {
                return $index;
            }
        }

        return null;
    }

    /**
     * `accountLocale()` — the one rule for "which language does this ACCOUNT read letters in", pinned
     * where the rule lives rather than only in its consumers' suites. The two configs are DIVERGENT in
     * every case below, because `App::setLocale()` writes `config('app.locale')` (ADR-0053 addendum):
     * on a machine where both configs agree, a fallback read from the wrong one is invisible — which is
     * exactly how the D4 review's locale mutation stayed green against an entire consumer suite.
     */
    public function test_account_locale_prefers_the_choice_and_falls_back_to_the_installation(): void
    {
        config(['app.locale' => 'en', 'app.default_locale' => 'pl']);

        $user = User::factory()->make(['locale' => null]);
        $this->assertSame('pl', SetUserLocale::accountLocale($user), 'no choice → the INSTALLATION default, never app.locale');

        $user->locale = 'zz';
        $this->assertSame('pl', SetUserLocale::accountLocale($user), 'an unsupported choice falls back the same way');

        $user->locale = 'en';
        $this->assertSame('en', SetUserLocale::accountLocale($user), 'a supported choice wins over both configs');
    }
}
