<?php

namespace App\Modules\Variables;

use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Models\Constant;
use App\Modules\Variables\Models\CustomFunction;
use App\Modules\Variables\Policies\ConstantPolicy;
use App\Modules\Variables\Policies\CustomFunctionPolicy;
use App\Modules\Variables\Support\AiVoiceContext;
use App\Modules\Variables\Support\LedgerMeteredAiCall;
use App\Modules\Variables\Support\MeterContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The Variables module owns the extracted variable TYPE SYSTEM (VariableType, the operation
 * vocabulary + arg descriptors), the pipeline OPERATION ENGINE (OperationExecutor,
 * PipelineValidator), and (Phase 2) the CONSTANTS persistence. It is a LOWER layer than Workflows:
 * Workflows depends on Variables, and Variables imports NOTHING from Workflows (a one-way boundary —
 * see the module extraction ADR).
 *
 * Registered BEFORE WorkflowsModuleServiceProvider in bootstrap/providers.php so the load order
 * mirrors the dependency direction. The engine classes (OperationExecutor, PipelineValidator) are
 * plain concretes the container auto-resolves, and the one inverted dependency the validator needs
 * (ElementScopeResolver) is bound by the Workflows provider — the side that owns the concrete —
 * so this module never references a Workflows class. Phase 2 adds the Constants CRUD routes + the
 * ConstantPolicy; Phase 3a adds the custom Functions persistence (CRUD routes + CustomFunctionPolicy)
 * and the write-side definition validator (types + args + body pipeline + acyclic reference graph).
 */
class VariablesModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The AMBIENT AI-spend session context (mirrors TenantContext): a singleton a generation
        // Session sets around its execution so the meter can attribute per-session spend. Empty for
        // the workflow/disk spenders (their usage events carry a null session).
        $this->app->singleton(MeterContext::class);

        // The AMBIENT AI-text VOICE context (R2 sub-stage 3), bound with the SAME container posture as
        // MeterContext (a shared instance the executor sets around a delegated run and the ai-text seam
        // reads). Empty for every non-delegated path, so existing behavior is byte-preserved.
        $this->app->singleton(AiVoiceContext::class);

        // The shared "metered AI call" seam (D7): the real token-LEDGER meter (R2 sub-stage 2a) is
        // bound OVER the shipped pass-through. It gates BEFORE spend on the workspace's calendar-month
        // token cap (0 = disabled, so existing behavior is byte-preserved by default) and records each
        // spend. Tests bind PassthroughMeteredAiCall when they want no metering.
        $this->app->singleton(MeteredAiCall::class, LedgerMeteredAiCall::class);
    }

    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/routes/api.php');

        Gate::policy(Constant::class, ConstantPolicy::class);
        Gate::policy(CustomFunction::class, CustomFunctionPolicy::class);
    }
}
