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

    /** The relation vocabulary is a good probe: every label is a translated string from an enum. */
    private function vocabularyLabel(User $user, Workspace $workspace): string
    {
        app(TenantContext::class)->set($workspace);
        $base = KnowledgeBase::factory()->create(['workspace_id' => $workspace->id]);
        app(TenantContext::class)->clear();

        $body = $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson("/api/knowledge/bases/{$base->id}")
            ->assertOk()
            ->json('data.relation_vocabulary');

        return collect($body)->firstWhere('id', 'member_of')['label'];
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

    // ---- ordering ----------------------------------------------------------------------

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
}
