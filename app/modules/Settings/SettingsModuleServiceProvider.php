<?php

namespace App\Modules\Settings;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SettingsModuleServiceProvider extends ServiceProvider
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
    }
}
