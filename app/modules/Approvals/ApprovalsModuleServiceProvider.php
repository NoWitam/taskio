<?php

namespace App\Modules\Approvals;

use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Approvals\Models\ApprovalProcess;
use App\Modules\Approvals\Policies\ApprovalPipelinePolicy;
use App\Modules\Approvals\Policies\ApprovalProcessPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ApprovalsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/routes/api.php');

        Gate::policy(ApprovalPipeline::class, ApprovalPipelinePolicy::class);
        Gate::policy(ApprovalProcess::class, ApprovalProcessPolicy::class);

        Relation::enforceMorphMap([
            'approval_pipeline' => ApprovalPipeline::class,
            'approval_process' => ApprovalProcess::class,
        ]);
    }
}
