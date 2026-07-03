<?php

namespace App\Modules\Bot;

use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Models\BotAction;
use App\Modules\Bot\Policies\BotPolicy;
use App\Modules\Bot\Tools\Support\BraveSearchProvider;
use App\Modules\Bot\Tools\Support\SearchProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BotModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Web-search provider. Only 'brave' ships today; the config key documents the
        // seam for future providers. Tests fake the outbound HTTP (Http::fake).
        $this->app->bind(SearchProvider::class, function () {
            return match (config('ai.search.provider')) {
                default => new BraveSearchProvider,
            };
        });
    }

    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/routes/api.php');

        Gate::policy(Bot::class, BotPolicy::class);

        Relation::enforceMorphMap([
            'bot' => Bot::class,
            'bot_action' => BotAction::class,
        ]);
    }
}
