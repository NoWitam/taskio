<?php

namespace App\Modules\Disk;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class DiskModuleServiceProvider extends ServiceProvider
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
