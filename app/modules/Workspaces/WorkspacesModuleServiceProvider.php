<?php

namespace App\Modules\Workspaces;

use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Policies\WorkspacePolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class WorkspacesModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->commands([
            \App\Modules\Workspaces\Console\ProvisionWorkspace::class,
        ]);
    }

    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/routes/api.php');

        Relation::enforceMorphMap([
            'workspace' => Workspace::class,
        ]);

        Gate::policy(Workspace::class, WorkspacePolicy::class);
    }
}
