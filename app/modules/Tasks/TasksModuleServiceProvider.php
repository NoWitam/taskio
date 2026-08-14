<?php

namespace App\Modules\Tasks;

use App\Modules\Calendar\Services\CalendarSourceRegistry;
use App\Modules\Tasks\Calendar\TaskDeadlineCalendarSource;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Policies\TaskPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TasksModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/routes/api.php');

        Gate::policy(Task::class, TaskPolicy::class);

        Relation::enforceMorphMap([
            'task' => Task::class,
        ]);

        // R3 Calendar — Tasks puts its own deadlines on the grid. Registered from HERE, in boot(), so
        // the Calendar module never has to name Tasks: the registry is bound in Calendar's register(),
        // and Laravel runs every register() before any boot(), which makes provider ORDER irrelevant to
        // whether this lands. Lazy so nothing is constructed for a request that never opens a calendar.
        //
        // The `bound()` guard is not defensive noise. Without it, a build where Calendar's provider is
        // absent would still RESOLVE a registry — the container happily auto-constructs an unbound
        // concrete class — and every registration would land on a throwaway instance. The calendar
        // would then render empty with nothing anywhere explaining why. No Calendar module means no
        // calendar to register with, and that is a coherent state; a silently discarded registration
        // is not.
        if ($this->app->bound(CalendarSourceRegistry::class)) {
            $this->app->make(CalendarSourceRegistry::class)->registerLazy(
                TaskDeadlineCalendarSource::ID,
                fn (): TaskDeadlineCalendarSource => $this->app->make(TaskDeadlineCalendarSource::class),
            );
        }
    }
}
