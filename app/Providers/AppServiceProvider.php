<?php

namespace App\Providers;

use App\Tenancy\QueueTenancy;
use App\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Carry the active workspace across the queue boundary for ALL jobs, so
        // tenant-aware models created in a worker get the correct workspace_id.
        QueueTenancy::register();
    }
}
