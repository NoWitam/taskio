<?php

namespace App\Modules\FilterTabs;

use App\Modules\FilterTabs\Models\FilterTab;
use App\Modules\FilterTabs\Policies\FilterTabPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class FilterTabsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(FilterTab::class, FilterTabPolicy::class);

        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/routes/api.php');
    }
}
