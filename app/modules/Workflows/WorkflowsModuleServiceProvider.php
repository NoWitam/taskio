<?php

namespace App\Modules\Workflows;

use App\Modules\Generator\Events\GenerationSessionUpdated;
use App\Modules\Variables\Contracts\AiTextGenerator;
use App\Modules\Variables\Contracts\ElementScopeResolver;
use App\Modules\Variables\Contracts\FunctionReferenceLookup;
use App\Modules\Workflows\Console\ReapStaleWorkflowRunsCommand;
use App\Modules\Workflows\Console\RunScheduledWorkflowsCommand;
use App\Modules\Workflows\Listeners\ResumeWaitingRunOnSessionTerminal;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Policies\WorkflowPolicy;
use App\Modules\Workflows\Services\GenerationSessionWaitResolver;
use App\Modules\Workflows\Services\WaitResolverRegistry;
use App\Modules\Workflows\Services\WorkflowAiTextService;
use App\Modules\Workflows\Services\WorkflowFunctionReferenceScanner;
use App\Modules\Workflows\Services\WorkflowRunContext;
use App\Modules\Workflows\Services\WorkflowVariableCatalogService;
use App\Modules\Workflows\Steps\GenerateContentStep;
use App\Modules\Workflows\Support\RealQueueConnection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
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

        // The queue connection that was active BEFORE a run job forced the `sync` driver — the named
        // escape hatch a SUSPENDING step uses to put its external work on the REAL queue. A singleton
        // like WorkflowRunContext (same set-around-the-run / clear-in-a-finally lifecycle).
        $this->app->singleton(RealQueueConnection::class);

        // The kind-keyed registry the waiting-run sweep asks "is this run's external work settled or
        // gone?". The ENGINE itself still knows no kinds — the registry is generic and a kind is
        // REGISTERED (see boot()), never hard-coded into the sweep. Singleton so those registrations
        // survive the request/worker.
        $this->app->singleton(WaitResolverRegistry::class);

        // The Variables module's PipelineValidator needs the element-scope subfield descent, which the
        // catalog owns (it also derives form-field / object-global subfields). Bound here — the side that
        // owns the concrete — so the Variables module keeps its one-way boundary (it never names a
        // Workflows class); see App\Modules\Variables\Contracts\ElementScopeResolver.
        $this->app->bind(ElementScopeResolver::class, WorkflowVariableCatalogService::class);

        // The Variables module's custom-function DELETE guard asks whether any WORKFLOW still references a
        // function; bound here (the side that owns the Workflow model) so Variables keeps its one-way
        // boundary. See App\Modules\Variables\Contracts\FunctionReferenceLookup.
        $this->app->bind(FunctionReferenceLookup::class, WorkflowFunctionReferenceScanner::class);

        // The shared VariableResolver EXECUTES an `@[ai-text]` directive through the Variables-side
        // AiTextGenerator contract; Workflows binds the thin per-run-budget decorator that delegates to
        // the shared Variables generator. Bound here (the side that owns the per-run budget) so Variables
        // keeps its one-way boundary. See AiTextGenerator.
        //
        // SCOPED, never a plain singleton: the container flushes scoped instances at every queue-job
        // boundary, so one pass over a run = one counter, shared by the resolver AND the step runner
        // (which seeds it from the run when resuming a parked run, and restores it around a nested
        // run). A process-wide singleton would leak one run's spend into the next.
        $this->app->scoped(WorkflowAiTextService::class);
        $this->app->bind(AiTextGenerator::class, WorkflowAiTextService::class);

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

        // The `generation_session` wait kind (R2 sub-stage 5). Registered HERE — not from the Generator's
        // provider, which is the contract's default posture — because the concrete must name BOTH sides and
        // the Generator is forbidden from naming Workflows. It still asks only through the Generator's
        // narrow automation seam.
        //
        // LAZILY, because this boot() runs on EVERY request: constructing the resolver eagerly would build
        // it plus the Generator's SessionAutomationService and GenerationSessionService for every request of
        // the application, when only the waiting-run sweep ever asks. The KIND is registered immediately, so
        // nothing about the sweep's lookup or its unregistered-kind fail-soft changes.
        $this->app->make(WaitResolverRegistry::class)->registerLazy(
            GenerateContentStep::WAIT_KIND,
            fn (): GenerationSessionWaitResolver => $this->app->make(GenerationSessionWaitResolver::class),
        );

        // The FAST path out of that wait: the Generator's terminal broadcast wakes the parked run instead of
        // it sitting until the next sweep. The sweep remains the correctness backstop (a reaper-settled
        // session does not broadcast) — see the listener. There is no app/Listeners discovery in this app,
        // so the binding is explicit.
        Event::listen(GenerationSessionUpdated::class, ResumeWaitingRunOnSessionTerminal::class);

        // `workflow_run` MUST be registered: HasCreator now stamps a workflow-run creator
        // polymorphically (creator_type = $run->getMorphClass()), and the repo enforces the
        // morph map — an unregistered class throws ClassMorphViolationException at write time.
        Relation::enforceMorphMap([
            'workflow' => Workflow::class,
            'workflow_run' => WorkflowRun::class,
        ]);
    }
}
