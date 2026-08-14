<?php

namespace App\Modules\Calendar\Services;

use App\Modules\Calendar\Contracts\CalendarSource;
use App\Modules\Calendar\DTOs\CalendarOccurrence;
use App\Modules\Calendar\DTOs\CalendarSourceResult;
use App\Modules\Calendar\DTOs\CalendarTruncation;
use App\Modules\Calendar\DTOs\CalendarWindow;
use App\Modules\Calendar\Enums\CalendarUnavailableReason;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Who can put something on the calendar. Mirrors WaitResolverRegistry (the established registry shape
 * in this codebase) down to the in-place memoization.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * PROVIDER ORDER IS NOT A DEPENDENCY HERE, AND THAT IS BY CONSTRUCTION
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A registry that modules write into invites exactly one recurring bug: the writer boots before the
 * registry exists and its entry vanishes without a word. The fix is structural, not documentary — the
 * registry is BOUND (as a singleton) in CalendarModuleServiceProvider::register(), while every source
 * registers itself in its own provider's boot(). Laravel runs all register() methods before any boot(),
 * so a source can never run first. bootstrap/providers.php still lists Calendar ahead of Tasks and
 * Workflows for readability, but nothing depends on it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * FAIL-SOFT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A calendar aggregates independent modules, so its failure mode has to be independent too: a source
 * that throws is logged (id + window, never subject content) and SKIPPED, and the rest of the grid
 * renders. One module's bad migration must not be able to blank a screen that is mostly other modules'
 * data. The skip is not silent to the caller either — {@see occurrencesFor()} answers with a
 * {@see CalendarUnavailableReason} so the response can name the sources it could not reach AND say WHY.
 *
 * The three failure modes below were always distinguished HERE and then flattened at the boundary into
 * a bare list of ids. They are no longer: the reason rides out with the id, because "retry" and "do not
 * bother" are different advice and the user was being given neither.
 */
class CalendarSourceRegistry
{
    /** @var array<string, CalendarSource|Closure(): CalendarSource> */
    private array $sources = [];

    public function register(CalendarSource $source): void
    {
        $this->warnOnOverwrite($source->id());

        $this->sources[$source->id()] = $source;
    }

    /**
     * Register a source WITHOUT constructing it. The overwhelmingly common request touches a couple of
     * sources; building every module's source (and its dependencies) on every boot to answer one of
     * them is a cost nobody asked for.
     *
     * @param  Closure(): CalendarSource  $factory
     */
    public function registerLazy(string $id, Closure $factory): void
    {
        $this->warnOnOverwrite($id);

        $this->sources[$id] = $factory;
    }

    /** Registered source ids, in registration order. */
    public function ids(): array
    {
        return array_keys($this->sources);
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->sources);
    }

    /**
     * Resolve a source, memoizing the constructed instance in place of its factory. Returns null when
     * the id is unknown OR when constructing it threw — a source whose factory explodes is treated the
     * same as one whose query does: reported, skipped, survivable.
     */
    public function resolve(string $id): ?CalendarSource
    {
        $resolved = $this->resolveOrReason($id);

        return $resolved instanceof CalendarSource ? $resolved : null;
    }

    /**
     * The same resolution, keeping the REASON it failed instead of collapsing every failure to null.
     *
     * All three ways of not coming up are {@see CalendarUnavailableReason::NOT_CONSTRUCTED}: an unknown
     * id, a factory that threw, and a source registered under an id it does not claim. They differ in
     * cause and are logged apart, but they give a caller the same advice — this will not be different
     * next time — and the wire vocabulary answers the caller's question, not the maintainer's.
     */
    private function resolveOrReason(string $id): CalendarSource|CalendarUnavailableReason
    {
        $source = $this->sources[$id] ?? null;

        if ($source === null) {
            return CalendarUnavailableReason::NOT_CONSTRUCTED;
        }

        if ($source instanceof Closure) {
            try {
                $built = $source();

                // The registration KEY is what filters, validates and labels; the occurrence's own
                // `source` field comes from the source itself. Let the two disagree and you get
                // occurrences no filter can ever select, with nothing anywhere saying why.
                if ($built->id() !== $id) {
                    Log::error('Calendar source was registered under an id it does not claim.', [
                        'registered_as' => $id,
                        'claims' => $built->id(),
                    ]);

                    return CalendarUnavailableReason::NOT_CONSTRUCTED;
                }

                // Assigned only on success, so a transient construction failure does not poison the
                // slot for the rest of the process.
                $source = $this->sources[$id] = $built;
            } catch (Throwable $e) {
                Log::error('Calendar source could not be constructed.', [
                    'source' => $id,
                ] + $this->describe($e));

                return CalendarUnavailableReason::NOT_CONSTRUCTED;
            }
        }

        return $source;
    }

    /**
     * A source's translated label, or null when it is unknown/unavailable.
     */
    public function labelFor(string $id): ?string
    {
        try {
            return $this->resolve($id)?->label();
        } catch (Throwable $e) {
            Log::error('Calendar source label could not be resolved.', [
                'source' => $id,
            ] + $this->describe($e));

            return null;
        }
    }

    /**
     * What one source puts in the window, with its own account of what it left out.
     *
     * @return CalendarSourceResult|CalendarUnavailableReason a reason — and only a reason — means "this
     *                                                        source could not answer". An EMPTY result
     *                                                        means it answered nothing, which is an
     *                                                        entirely different statement and one the
     *                                                        response reports differently.
     */
    public function occurrencesFor(string $id, CalendarWindow $window): CalendarSourceResult|CalendarUnavailableReason
    {
        $source = $this->resolveOrReason($id);

        if ($source instanceof CalendarUnavailableReason) {
            return $source;
        }

        try {
            $result = $source->occurrences($window);

            // THE RETURN VALUE IS GUARDED, NOT JUST THE CALL. Catching only what a source THROWS is a
            // fail-soft that covers the easy half: a source that returns the wrong shape does not throw
            // here — it throws later, in the query service's sort, outside every handler, and takes the
            // whole calendar down with a 500. Since the module's entire promise is that a stranger can
            // add a source without reading Calendar code, that stranger's type error must not be able to
            // blank the modules that were working. The declared return type catches the coarsest
            // mistakes; these checks catch a well-typed result full of the wrong things.
            foreach ($result->occurrences as $occurrence) {
                if (!$occurrence instanceof CalendarOccurrence) {
                    Log::error('Calendar source returned something that is not an occurrence.', [
                        'source' => $id,
                        'got' => get_debug_type($occurrence),
                    ]);

                    return CalendarUnavailableReason::MALFORMED;
                }
            }

            foreach ($result->truncations as $truncation) {
                if (!$truncation instanceof CalendarTruncation) {
                    Log::error('Calendar source returned something that is not a truncation.', [
                        'source' => $id,
                        'got' => get_debug_type($truncation),
                    ]);

                    return CalendarUnavailableReason::MALFORMED;
                }
            }

            return $result;
        } catch (Throwable $e) {
            Log::error('Calendar source failed and was skipped.', [
                'source' => $id,
                'from' => $window->startDate,
                'to' => $window->endDate,
                'timezone' => $window->timezone,
            ] + $this->describe($e));

            return CalendarUnavailableReason::FAILED;
        }
    }

    /**
     * A source that fails must be findable, not merely counted: the message alone says what went wrong
     * and never where.
     *
     * @return array<string, mixed>
     */
    private function describe(Throwable $e): array
    {
        return [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'at' => $e->getFile() . ':' . $e->getLine(),
        ];
    }

    /**
     * Two modules claiming one id is a configuration error that would otherwise resolve itself silently
     * in favour of whichever provider booted last.
     */
    private function warnOnOverwrite(string $id): void
    {
        if (array_key_exists($id, $this->sources)) {
            Log::warning('Calendar source id was registered twice; the later registration wins.', [
                'source' => $id,
            ]);
        }
    }
}
