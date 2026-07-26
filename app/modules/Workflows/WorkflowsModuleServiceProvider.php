<?php

namespace App\Modules\Workflows;

use App\Modules\Variables\Contracts\ElementScopeResolver;
use App\Modules\Variables\Contracts\FunctionReferenceLookup;
use App\Modules\Workflows\Console\ReapStaleWorkflowRunsCommand;
use App\Modules\Workflows\Console\RunScheduledWorkflowsCommand;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Policies\WorkflowPolicy;
use App\Modules\Workflows\Services\WorkflowFunctionReferenceScanner;
use App\Modules\Workflows\Services\WorkflowRunContext;
use App\Modules\Workflows\Services\WorkflowVariableCatalogService;
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

        // The Variables module's PipelineValidator needs the element-scope subfield descent, which the
        // catalog owns (it also derives form-field / object-global subfields). Bound here — the side that
        // owns the concrete — so the Variables module keeps its one-way boundary (it never names a
        // Workflows class); see App\Modules\Variables\Contracts\ElementScopeResolver.
        $this->app->bind(ElementScopeResolver::class, WorkflowVariableCatalogService::class);

        // The Variables module's custom-function DELETE guard asks whether any WORKFLOW still references a
        // function; bound here (the side that owns the Workflow model) so Variables keeps its one-way
        // boundary. See App\Modules\Variables\Contracts\FunctionReferenceLookup.
        $this->app->bind(FunctionReferenceLookup::class, WorkflowFunctionReferenceScanner::class);

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

        // `workflow_run` MUST be registered: HasCreator now stamps a workflow-run creator
        // polymorphically (creator_type = $run->getMorphClass()), and the repo enforces the
        // morph map — an unregistered class throws ClassMorphViolationException at write time.
        Relation::enforceMorphMap([
            'workflow' => Workflow::class,
            'workflow_run' => WorkflowRun::class,
        ]);
    }
}
