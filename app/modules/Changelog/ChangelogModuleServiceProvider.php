<?php

namespace App\Modules\Changelog;

use App\Modules\Changelog\Managers\ChangelogManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ChangelogModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__ . '/routes/api.php');

        $this->app->singleton(ChangelogManager::class, function () {
            return new ChangelogManager();
        });
    }
}
