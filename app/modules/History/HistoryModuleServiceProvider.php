<?php

namespace App\Modules\History;

use App\Modules\History\Managers\HistoryManager;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HistoryModuleServiceProvider extends ServiceProvider
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

        App::scoped(HistoryManager::class, function () {
            return new HistoryManager();
        });
    }
}
