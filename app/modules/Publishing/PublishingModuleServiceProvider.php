<?php

namespace App\Modules\Publishing;

use App\Modules\Calendar\Services\CalendarSourceRegistry;
use App\Modules\Publishing\Adapters\DryRunPlatformAdapter;
use App\Modules\Publishing\Calendar\PublicationCalendarSource;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Models\PublicationAttempt;
use App\Modules\Publishing\Policies\PublicationPolicy;
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
    }

    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/routes/api.php');

        Gate::policy(Publication::class, PublicationPolicy::class);

        // The morph map is enforced app-wide, so a class used as ANY morph value must be in it. A
        // publication's calendar occurrences carry `publication` as their subject alias, and the alias
        // — never the FQCN — is what goes on the wire.
        //
        // `publication_attempt` is registered although nothing addresses an attempt polymorphically
        // today: the model composes HasCreator, and a creator relation resolving a class name it cannot
        // find in the map is a runtime failure on a path nobody exercises until a bot writes one.
        Relation::enforceMorphMap([
            'publication' => Publication::class,
            'publication_attempt' => PublicationAttempt::class,
        ]);

        $this->registerPlatformAdapters();
        $this->registerCalendarSource();
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
