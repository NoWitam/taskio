<?php

namespace App\Modules\Forms;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Policies\FormPolicy;
use App\Modules\Forms\Policies\FormSubmissionPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class FormsModuleServiceProvider extends ServiceProvider
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

        Relation::enforceMorphMap([
            'form' => Form::class,
            'form_submission' => FormSubmission::class,
        ]);

        // Register policies
        Gate::policy(Form::class, FormPolicy::class);
        Gate::policy(FormSubmission::class, FormSubmissionPolicy::class);
    }
}
