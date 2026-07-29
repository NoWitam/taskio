<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\Contracts\WaitResolver;
use App\Modules\Workflows\Enums\WaitStatus;
use App\Modules\Workflows\Models\WorkflowRun;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The kind-keyed registry of {@see WaitResolver}s — the ONLY thing the waiting-run sweep asks about
 * external work, and the reason the engine names no feature module. A singleton (see the module
 * provider); a feature module registers its resolver from its own provider's boot():
 *
 *     $this->app->make(WaitResolverRegistry::class)->register(new SomeWaitResolver(...));
 *
 * Workflows itself registers NOTHING: shipped empty, the engine is fully generic.
 *
 * …or LAZILY, when constructing the resolver would drag a whole feature module's service graph into every
 * request just to register a kind the sweep may never ask about:
 *
 *     $this->app->make(WaitResolverRegistry::class)
 *         ->registerLazy(SomeStep::WAIT_KIND, fn () => $this->app->make(SomeWaitResolver::class));
 *
 * The KIND is known immediately either way (so {@see kinds()} and the sweep's "unregistered kind" fail-soft
 * are unaffected); only the CONSTRUCTION is deferred, and it happens at most once per process.
 *
 * FAIL-SOFT BY DESIGN. Both "no resolver for this kind" and "the resolver threw" resolve to
 * {@see WaitStatus::PENDING}, never GONE:
 *  - an UNREGISTERED kind is a deploy/config fault (a provider not booted, a rename mid-deploy), NOT
 *    evidence that the external work vanished — failing every such run instantly would destroy live
 *    work over a transient misregistration;
 *  - a THROWING resolver (its own DB unreachable) must not abort the sweep for the other kinds.
 * Neither case can strand a run: `workflows.wait_timeout` still bounds the wait and fails it with a
 * clear timeout message. Both are logged (run id + kind only — never the wait payload).
 */
class WaitResolverRegistry
{
    /** @var array<string, WaitResolver|Closure(): WaitResolver> */
    private array $resolvers = [];

    public function register(WaitResolver $resolver): void
    {
        $this->resolvers[$resolver->kind()] = $resolver;
    }

    /**
     * Register $kind with a factory instead of an instance. The factory runs the FIRST time something asks
     * for that kind and its result is memoized, so a registration made in a provider's boot() costs nothing
     * on a request that never reaches the wait sweep.
     *
     * @param  Closure(): WaitResolver  $factory
     */
    public function registerLazy(string $kind, Closure $factory): void
    {
        $this->resolvers[$kind] = $factory;
    }

    public function for(string $kind): ?WaitResolver
    {
        $resolver = $this->resolvers[$kind] ?? null;

        if ($resolver instanceof Closure) {
            // Memoized in place: one construction per process, and every later lookup (including the
            // per-run lookups the sweep makes) is a plain array read.
            $resolver = $this->resolvers[$kind] = $resolver();
        }

        return $resolver;
    }

    /** @return array<int, string> the registered kinds (diagnostics/tests). */
    public function kinds(): array
    {
        return array_keys($this->resolvers);
    }

    /**
     * The status of ONE run's wait. Unknown kind / thrown resolver → PENDING (see the class note).
     *
     * @param  array<string, mixed>  $waitingOn
     */
    public function statusFor(string $kind, WorkflowRun $run, array $waitingOn): WaitStatus
    {
        $resolver = $this->for($kind);

        if ($resolver === null) {
            Log::warning('Workflow wait has no registered resolver; leaving it waiting until the wait timeout.', [
                'run_id' => $run->id,
                'kind' => $kind,
            ]);

            return WaitStatus::PENDING;
        }

        try {
            return $resolver->status($run, $waitingOn);
        } catch (Throwable $e) {
            Log::error('Workflow wait resolver threw; leaving the run waiting.', [
                'run_id' => $run->id,
                'kind' => $kind,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return WaitStatus::PENDING;
        }
    }
}
