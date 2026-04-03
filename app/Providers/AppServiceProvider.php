<?php

namespace App\Providers;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Observers\FormObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Form::observe(FormObserver::class);
    }
}
