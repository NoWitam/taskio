<?php

namespace App\Modules\Bot;

use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Models\BotAction;
use App\Modules\Bot\Policies\BotPolicy;
use App\Modules\Bot\Services\BotAuthorVoiceResolver;
use App\Modules\Bot\Services\BotSessionIdentityResolver;
use App\Modules\Bot\Tools\Support\BraveSearchProvider;
use App\Modules\Bot\Tools\Support\SearchProvider;
use App\Modules\Generator\Contracts\SessionAuthorIdentityResolver;
use App\Modules\Variables\Contracts\AuthorVoiceResolver;
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

        // The per-block ai-text AUTHOR seam: an author IS a bot, so this module owns the concrete and binds
        // it over the Variables default (a null object). UNCONDITIONAL bind — never bindIf — so the concrete
        // wins even when this provider registers AFTER the module that ships the default; Variables' side of
        // the deal is bindIf, so it cannot clobber this one either. The seam is therefore independent of
        // provider load ORDER, and both halves are pinned by BotModuleBoundaryTest.
        $this->app->bind(AuthorVoiceResolver::class, BotAuthorVoiceResolver::class);

        // The SESSION-AUTHOR seam (a workflow's `generate_content` step delegating its generation session to
        // a bot): the same inversion one layer up — the Generator owns the contract, this module owns the
        // concrete that knows an author IS a bot. UNCONDITIONAL bind for the same reason as above, and it is
        // the half that carries the seam when the provider order is REVERSED from today's; with today's
        // order (Bot registers BEFORE Generator) the load-bearing half is the Generator's `bindIf`, which is
        // what stops its default from clobbering this binding. Both halves pinned by BotModuleBoundaryTest.
        $this->app->bind(SessionAuthorIdentityResolver::class, BotSessionIdentityResolver::class);

        $this->commands([
            \App\Modules\Bot\Console\ReapStaleBotRunsCommand::class,
        ]);
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
