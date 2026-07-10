<?php

namespace App\Modules\Workflows;

use App\Modules\Workflows\Console\ReapStaleWorkflowRunsCommand;
use App\Modules\Workflows\Console\RunScheduledWorkflowsCommand;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Policies\WorkflowPolicy;
use App\Modules\Workflows\Services\WorkflowRunContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class WorkflowsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // In-process holder for the currently-executing run — a singleton so the step
        // runner and (Batch 3) the trigger dispatcher share one instance per request/worker.
        $this->app->singleton(WorkflowRunContext::class);

        $this->commands([
            ReapStaleWorkflowRunsCommand::class,
            RunScheduledWorkflowsCommand::class,
        ]);
    }

    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/routes/api.php');

        Gate::policy(Workflow::class, WorkflowPolicy::class);

        Relation::enforceMorphMap([
            'workflow' => Workflow::class,
        ]);
    }
}
