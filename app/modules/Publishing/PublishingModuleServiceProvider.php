<?php

namespace App\Modules\Publishing;

use App\Modules\Calendar\Services\CalendarSourceRegistry;
use App\Modules\Publishing\Adapters\DryRunPlatformAdapter;
use App\Modules\Publishing\Calendar\PublicationCalendarSource;
use App\Modules\Publishing\Console\DispatchDuePublicationsCommand;
use App\Modules\Publishing\Console\ReconcilePublicationsCommand;
use App\Modules\Publishing\Console\RefreshPlatformTokensCommand;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Models\PlatformConnection;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Models\PublicationAttempt;
use App\Modules\Publishing\OAuth\GoogleOAuthProvider;
use App\Modules\Publishing\OAuth\MetaOAuthProvider;
use App\Modules\Publishing\Policies\PlatformConnectionPolicy;
use App\Modules\Publishing\Policies\PublicationPolicy;
use App\Modules\Publishing\Services\OAuthProviderRegistry;
use App\Modules\Publishing\Services\PlatformAdapterRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The Publishing module: a state machine, an adapter registry, and one square on somebody else's grid.
 *
 * ORDERING, and why nothing here depends on it: both registries are BOUND in a register() — the adapter
 * registry in this one, the calendar source registry in the Calendar's — while every registration
 * happens in a boot(). Laravel runs all register() methods before any boot(), so no provider order can
 * make a registration land on a registry that does not exist yet, which is the failure mode that
 * otherwise loses an entry without a word.
 */
class PublishingModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton, and it must be: registration is a side effect on this instance, and a fresh
        // registry per resolve would leave every publication with no adapter for its destination.
        $this->app->singleton(PlatformAdapterRegistry::class);

        // The same argument, for the same reason, about a different question. TWO registries rather
        // than one because the two are not in bijection: `dry_run` has an adapter and no OAuth provider
        // (it publishes nothing, so there is no account to connect), while Instagram and Facebook are
        // two adapters served by ONE Meta dialect — one app, one consent screen, one token endpoint.
        $this->app->singleton(OAuthProviderRegistry::class);
    }

    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/routes/api.php');

        Gate::policy(Publication::class, PublicationPolicy::class);
        Gate::policy(PlatformConnection::class, PlatformConnectionPolicy::class);

        // The morph map is enforced app-wide, so a class used as ANY morph value must be in it. A
        // publication's calendar occurrences carry `publication` as their subject alias, and the alias
        // — never the FQCN — is what goes on the wire.
        //
        // `publication_attempt` and `platform_connection` are registered although nothing addresses
        // either polymorphically today: both models compose HasCreator, and a creator relation resolving
        // a class name it cannot find in the map is a runtime failure on a path nobody exercises until a
        // bot writes one — or, for a connection, until somebody opens the list screen on a workspace
        // where a workflow run authorized the account.
        Relation::enforceMorphMap([
            'publication' => Publication::class,
            'publication_attempt' => PublicationAttempt::class,
            'platform_connection' => PlatformConnection::class,
        ]);

        $this->registerPlatformAdapters();
        $this->registerOAuthProviders();
        $this->registerCalendarSource();

        if ($this->app->runningInConsole()) {
            $this->commands([
                RefreshPlatformTokensCommand::class,
                // B3. The due sweep is the ONLY path that starts a publish; the reconciliation sweep is
                // the only thing that recovers one a dead worker left behind. Both are scheduled in
                // routes/console.php and neither does anything until something is due.
                DispatchDuePublicationsCommand::class,
                ReconcilePublicationsCommand::class,
            ]);
        }
    }

    /**
     * The destinations this installation can publish to.
     *
     * B1 ships ONE, and it is a complete adapter rather than a stub — see
     * {@see DryRunPlatformAdapter} for why that distinction is load-bearing (the platform reviews are
     * demonstrated on it, and the whole suite drives it). YouTube, Instagram and Facebook join here in
     * B2/B3, each from this same method, each behind whatever credentials it needs.
     *
     * Lazy: real adapters will hold HTTP clients and credential resolvers, and building every one of
     * them on every boot to serve a request that never publishes anything is a cost nobody asked for.
     */
    private function registerPlatformAdapters(): void
    {
        $this->app->make(PlatformAdapterRegistry::class)->registerLazy(
            PublishingPlatform::DRY_RUN,
            fn (): DryRunPlatformAdapter => $this->app->make(DryRunPlatformAdapter::class),
        );
    }

    /**
     * The destinations an ACCOUNT can be authorized for — which is not the same list as the one above.
     *
     * `dry_run` is absent and always will be: it publishes nothing and has no account behind it, so
     * asking to connect one is refused at the HTTP door with a sentence rather than being quietly served.
     *
     * Instagram and Facebook share {@see MetaOAuthProvider}, constructed once per platform so each reads
     * its own scopes from `config/publishing.php`. They are one Meta application with one consent screen
     * and one token endpoint; registering them as two providers of one class is what keeps that fact from
     * becoming two copies of the dialect, free to drift.
     *
     * REGISTERED EVEN THOUGH NONE OF THEM IS CONFIGURED on this installation — the applications with
     * Google and Meta do not exist yet. Making registration conditional on credentials was the
     * alternative and it is worse: `has()` would then answer "we do not support Instagram" for a missing
     * environment variable, which is the same sentence for a product decision and for a typo.
     * `OAuthProvider::isConfigured()` is the separate question, asked at the door.
     */
    private function registerOAuthProviders(): void
    {
        $registry = $this->app->make(OAuthProviderRegistry::class);

        $registry->registerLazy(
            PublishingPlatform::YOUTUBE,
            fn (): GoogleOAuthProvider => new GoogleOAuthProvider(PublishingPlatform::YOUTUBE),
        );

        $registry->registerLazy(
            PublishingPlatform::FACEBOOK,
            fn (): MetaOAuthProvider => new MetaOAuthProvider(PublishingPlatform::FACEBOOK),
        );

        $registry->registerLazy(
            PublishingPlatform::INSTAGRAM,
            fn (): MetaOAuthProvider => new MetaOAuthProvider(PublishingPlatform::INSTAGRAM),
        );
    }

    /**
     * Publishing puts its scheduled items on the Calendar's grid — the FIFTH source, registered from
     * HERE so the Calendar module never has to name Publishing.
     *
     * The `bound()` guard is not defensive noise, and it is copied from the Tasks provider with its
     * reasoning intact. Without it, a build where the Calendar's provider is absent would still RESOLVE
     * a registry — the container happily auto-constructs an unbound concrete class — and this
     * registration would land on a throwaway instance. The calendar would then render without
     * publications and nothing anywhere would explain why. No Calendar module means no calendar to
     * register with, and that is a coherent state; a silently discarded registration is not.
     */
    private function registerCalendarSource(): void
    {
        if (!$this->app->bound(CalendarSourceRegistry::class)) {
            return;
        }

        $this->app->make(CalendarSourceRegistry::class)->registerLazy(
            PublicationCalendarSource::ID,
            fn (): PublicationCalendarSource => $this->app->make(PublicationCalendarSource::class),
        );
    }
}
