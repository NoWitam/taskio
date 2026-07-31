<?php

namespace App\Modules\Generator;

use App\Modules\Generator\Contracts\SessionAuthorIdentityResolver;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Models\Template;
use App\Modules\Generator\Policies\GenerationSessionPolicy;
use App\Modules\Generator\Policies\TemplatePolicy;
use App\Modules\Generator\Services\GenerationSessionExecutor;
use App\Modules\Generator\Services\GeneratorAiTextService;
use App\Modules\Generator\Services\RecipeAuthorVoiceSnapshotter;
use App\Modules\Generator\Services\SessionImageBudget;
use App\Modules\Generator\Services\TemplateRenderService;
use App\Modules\Generator\Support\CreativeDirectionContext;
use App\Modules\Generator\Support\NoOpAiTextGenerator;
use App\Modules\Generator\Support\NullSessionAuthorIdentityResolver;
use App\Modules\Variables\Services\OperationExecutor;
use App\Modules\Variables\Services\OperationResolver;
use App\Modules\Variables\Services\VariableResolver;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The Generator module owns generation TEMPLATES and generation SESSIONS. It CONSUMES the Variables
 * module — the type system, the shared catalog composition (VariableCatalog), and the shared
 * interpolation engine (VariableResolver) — and imports NOTHING from Workflows: a one-way boundary
 * mirroring Variables → (nothing), pinned by GeneratorModuleBoundaryTest.
 *
 * Registered AFTER VariablesModuleServiceProvider in bootstrap/providers.php so the load order mirrors
 * the dependency direction (Generator depends on Variables). The engine + catalog classes are plain
 * concretes the container auto-resolves; the only wiring here is:
 *   - the template PREVIEW resolver: the SHARED VariableResolver bound with a NO-OP AiTextGenerator ONLY
 *     where TemplateRenderService needs it — so `@[ai-text]` renders inert in a preview.
 *   - the SESSION EXECUTOR resolver: the SAME shared VariableResolver, but bound with the REAL
 *     GeneratorAiTextService ONLY where GenerationSessionExecutor needs it — so a live run generates real
 *     ai-text (budgeted + metered). Both bindings are CONTEXTUAL (not app-wide), so the app-wide
 *     `AiTextGenerator → WorkflowAiTextService` binding is untouched and the boundary holds (each factory
 *     names only Variables/Generator classes, never a Workflows one).
 *
 *     NOTE the two bindings compose deliberately: the creative-direction derivation asks the container for
 *     TemplateRenderService, so it renders its input through the NO-OP (preview) resolver — a nested
 *     `@[ai-text]` becomes a `[AI: …]` placeholder instead of a second billed call — while the run it
 *     directs keeps using the REAL generator.
 *   - the ambient CreativeDirectionContext singleton.
 *   - the Template + GenerationSession policy gates, and the `generation_session` morph alias.
 */
class GeneratorModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Lifecycle reaper (R2 sub-stage 2d): stale-generating recovery + retention (trash → purge) + blob GC,
        // swept across the shared DB and every own-database tenant. Scheduled in routes/console.php.
        $this->commands([
            \App\Modules\Generator\Console\ReapGenerationSessionsCommand::class,
        ]);

        // The SESSION-AUTHOR seam: an automation may order its generation session DELEGATED to an author,
        // and this module must not learn what an author is. The DEFAULT is the null object (nothing ever
        // resolves, so an installation without the author module refuses to generate a delegated session
        // rather than quietly publishing an anonymous one); the module that owns authors binds the real
        // resolver over it.
        //
        // bindIf — NOT bind — ON PURPOSE, and with TODAY'S provider order this is the LOAD-BEARING half:
        // bootstrap/providers.php registers Bot BEFORE Generator, so the concrete is already bound when this
        // runs and only `bindIf` leaves it alone. The unconditional bind on the Bot side is what makes the
        // REVERSE order equally safe, so the file order in bootstrap/providers.php stays a free choice
        // rather than a silent, load-bearing dependency. Both halves are pinned by BotModuleBoundaryTest.
        $this->app->bindIf(SessionAuthorIdentityResolver::class, NullSessionAuthorIdentityResolver::class);

        // The AMBIENT CREATIVE-DIRECTION context (the direction layer), bound with the SAME container
        // posture as the Variables MeterContext / AiVoiceContext: a shared instance the session executor
        // sets around a run and clears in the same finally, which the DEEP `@[ai-text]` seam
        // (GeneratorAiTextService, unreachable by parameter from the executor) reads. Empty on every path
        // that never sets it, so behavior is byte-preserved when the layer is off.
        $this->app->singleton(CreativeDirectionContext::class);

        // The RUN's image-call ledger, bound with the SAME ambient posture as the contexts above: the session
        // executor binds it around a render and the DEEP image seam (ImageChainExecutor, unreachable by
        // parameter from the executor) reads it. It MUST be a singleton — the two collaborators are resolved
        // independently, and two instances would leave the chain executor permanently unbound, silently
        // falling back to per-instance counters. That failure mode is invisible in a single-job run and
        // catastrophic in a fanned-out one: every frame job would get its own full image budget.
        $this->app->singleton(SessionImageBudget::class);

        // Template PREVIEW: inert `@[ai-text]` (a labeled placeholder, no real AI).
        $this->app->when(TemplateRenderService::class)
            ->needs(VariableResolver::class)
            ->give(fn ($app) => new VariableResolver(
                $app->make(OperationExecutor::class),
                new NoOpAiTextGenerator,
                $app->make(OperationResolver::class),
            ));

        // Recipe AUTHOR-VOICE snapshot: a pure SCAN of the recipe's strings, never a generation. Bound with
        // the same NO-OP generator the preview uses, so freezing the authors of a recipe can never make (or
        // bill) an AI call — not even if the shared walk grew a resolving path.
        $this->app->when(RecipeAuthorVoiceSnapshotter::class)
            ->needs(VariableResolver::class)
            ->give(fn ($app) => new VariableResolver(
                $app->make(OperationExecutor::class),
                new NoOpAiTextGenerator,
                $app->make(OperationResolver::class),
            ));

        // Generation SESSION run: the REAL, budgeted, metered generator. Resolved fresh with the resolver
        // per executor build, so the per-session ai-text call budget scopes to one run.
        $this->app->when(GenerationSessionExecutor::class)
            ->needs(VariableResolver::class)
            ->give(fn ($app) => new VariableResolver(
                $app->make(OperationExecutor::class),
                $app->make(GeneratorAiTextService::class),
                $app->make(OperationResolver::class),
            ));
    }

    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/routes/api.php');

        Gate::policy(Template::class, TemplatePolicy::class);
        Gate::policy(GenerationSession::class, GenerationSessionPolicy::class);

        // `generation_session` is registered so a session stamped as a polymorphic CREATOR (a future
        // workflow_run/bot-driven run, or a session that creates records in 2c/2d) resolves under the
        // app-wide enforced morph map. Merges with the other modules' aliases (enforceMorphMap is additive).
        Relation::enforceMorphMap([
            'generation_session' => GenerationSession::class,
        ]);
    }
}
