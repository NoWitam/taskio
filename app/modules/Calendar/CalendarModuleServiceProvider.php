<?php

namespace App\Modules\Calendar;

use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Policies\CalendarEventPolicy;
use App\Modules\Calendar\Services\CalendarSourceRegistry;
use App\Modules\Calendar\Sources\EventCalendarSource;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The Calendar module: a CONTRACT and a REGISTRY, ONE subject of its own, and nothing that knows what
 * a task or a workflow is.
 *
 * The subject of its own is the EVENT (R3 B3) — the annotation somebody writes on the timeline because
 * nothing else in the product would record it. It does not weaken the rule below: the mapping from an
 * event to an occurrence lives here because the EVENT lives here, which is the same rule the other
 * sources follow, not an exception to it. What must stay true is that no source belonging to another
 * module is ever named in this directory.
 *
 * Everything the grid shows arrives through {@see \App\Modules\Calendar\Contracts\CalendarSource},
 * implemented by the module that OWNS the subject and registered from that module's own provider. The
 * mapping from a task to an occurrence lives under app/modules/Tasks/Calendar; the schedule and run
 * mappings live under app/modules/Workflows/Calendar. None of them is named here.
 *
 * The bar this buys, and the one CalendarModuleBoundaryTest enforces: adding R4 Publishing as a fourth
 * source must not change a single line under app/modules/Calendar.
 *
 * ORDERING: the registry is bound in register(); sources register themselves in boot(). Laravel runs
 * every register() before any boot(), so no provider order can make a source register into a registry
 * that does not exist yet. bootstrap/providers.php lists Calendar before its source modules for
 * readability, not because anything depends on it.
 */
class CalendarModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton, and it must be: registration is a side effect on this instance, and a fresh
        // registry per resolve would hand the query service an empty calendar.
        $this->app->singleton(CalendarSourceRegistry::class);
    }

    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/routes/api.php');

        Gate::policy(CalendarEvent::class, CalendarEventPolicy::class);

        // The morph map is enforced app-wide, so a class used as ANY morph value must be in it. An
        // event's own occurrences carry `calendar_event` as their subject alias, and the alias — never
        // the FQCN — is what goes on the wire: a leaked namespace in a public payload freezes a
        // refactor out of the codebase.
        Relation::enforceMorphMap([
            'calendar_event' => CalendarEvent::class,
        ]);

        // The Calendar's OWN source, registered exactly like a foreign module's: in boot(), lazily,
        // through the same public registry method. Nothing here gets a shortcut for being local —
        // if owning a subject earned privileged access to the registry, the registry would stop
        // being the thing every source has to fit through.
        $this->app->make(CalendarSourceRegistry::class)->registerLazy(
            EventCalendarSource::ID,
            fn (): EventCalendarSource => $this->app->make(EventCalendarSource::class),
        );
    }
}
