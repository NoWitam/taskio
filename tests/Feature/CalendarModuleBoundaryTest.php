<?php

namespace Tests\Feature;

use App\Modules\Calendar\Contracts\CalendarSource;
use App\Modules\Calendar\DTOs\CalendarOccurrence;
use App\Modules\Calendar\DTOs\CalendarSourceResult;
use App\Modules\Calendar\DTOs\CalendarWindow;
use App\Modules\Calendar\Enums\CalendarColor;
use App\Modules\Calendar\Services\CalendarQueryService;
use App\Modules\Calendar\Services\CalendarSourceRegistry;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Architectural pin for the Calendar module boundary (mirrors KnowledgeModuleBoundaryTest, the template
 * for every shared layer in this codebase).
 *
 * THE CALENDAR KNOWS NOBODY. It owns a contract and a registry; the modules with something to show
 * implement the contract in their OWN namespace and register themselves from their own provider. The
 * acceptance bar the owner set for this chapter is concrete, so it is tested rather than described:
 *
 *     adding R4 Publishing as a fourth source must not require changing one line under
 *     app/modules/Calendar.
 *
 * That is not a style preference. A calendar is the one screen every future module wants a square on,
 * so it is the module most likely to accumulate a dependency on each of them in turn — until it is the
 * thing that must be edited for any of them to change, and can be reasoned about only by reading all of
 * them.
 *
 * The scan is LITERAL over the file bytes, so it also catches a forbidden class named only in a comment
 * or docblock. That is deliberate: a docblock reference is exactly how a real import starts, and it is
 * the form a reviewer is most likely to wave through.
 *
 * ONE THING THE BYTES DO NOT SAY BY THEMSELVES. A namespace inside a double-quoted PHP string is written
 * with DOUBLED backslashes — `app("App\\Modules\\Tasks\\Models\\Task")` — so the file bytes spell
 * `App\\Modules\\Tasks` and a needle spelled with single ones never matched it. That is not a theoretical
 * gap: it is the shortest way to reach a forbidden module (a container lookup by class name), and it
 * sailed through every test in this file. Backslash RUNS are therefore collapsed before the scan
 * ({@see normalize}), so the needle means the class no matter how many layers of escaping the author
 * wrapped it in.
 */
class CalendarModuleBoundaryTest extends TestCase
{
    /**
     * No file under app/modules/Calendar may name a SOURCE module. The forbidden list is every module
     * that has, or plausibly will have, something to put on a grid.
     */
    public function test_calendar_module_names_no_source_module(): void
    {
        $root = app_path('modules/Calendar');
        $this->assertDirectoryExists($root);

        $forbidden = [
            'App\\Modules\\Tasks',
            'App\\Modules\\Workflows',
            'App\\Modules\\Generator',
            'App\\Modules\\Bot',
            'App\\Modules\\Forms',
            'App\\Modules\\Approvals',
            'App\\Modules\\Disk',
            'App\\Modules\\Knowledge',
            // The module this test's headline scenario is ABOUT. Listed before it exists, because an
            // allowlist-by-omission would have waved through the one import the whole exercise is meant
            // to prevent.
            'App\\Modules\\Publishing',
            // Workspaces is not a source, but the Calendar does need the active workspace's timezone.
            // It takes it through App\Tenancy\TenantContext — shared infrastructure every module stands
            // on — and never by naming the Workspaces module. Pinning that keeps the carve-out narrow:
            // "reads a workspace setting through tenancy" must not drift into "imports Workspaces".
            'App\\Modules\\Workspaces',
        ];

        $scanned = 0;

        /** @var iterable<\SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $scanned++;
            $source = $this->normalize((string) file_get_contents($file->getPathname()));

            foreach ($forbidden as $module) {
                $this->assertStringNotContainsString(
                    $module,
                    $source,
                    $file->getPathname() . ' must not name ' . $module . ' — the Calendar knows only its contract.'
                );
            }
        }

        // Guards the scan itself: a module that was moved, renamed or emptied would otherwise make this
        // test pass vacuously forever.
        $this->assertGreaterThan(0, $scanned, 'expected to scan the Calendar module source files');
    }

    /**
     * Collapse every RUN of backslashes to one, so `App\\Modules\\Tasks` (a namespace inside a
     * double-quoted string) and `App\Modules\Tasks` (an import) are the same thing to the scan.
     *
     * Collapsing runs rather than halving them is deliberate — it is stable under any depth of escaping,
     * including a namespace that has been through two layers of it. The scan only ever gets MORE
     * sensitive as a result, which is the correct direction for a guard to be wrong in.
     */
    private function normalize(string $source): string
    {
        return (string) preg_replace('/\\\\+/', '\\', $source);
    }

    /**
     * The bar itself, executed: a brand-new source — standing in for R4 Publishing — registers from
     * OUTSIDE the Calendar module and appears on the calendar, with no Calendar file involved.
     */
    public function test_a_new_source_can_join_without_touching_the_calendar_module(): void
    {
        $registry = app(CalendarSourceRegistry::class);

        $registry->registerLazy('publication', fn (): CalendarSource => new class implements CalendarSource
        {
            public function id(): string
            {
                return 'publication';
            }

            public function label(): string
            {
                return 'Publications';
            }

            public function occurrences(CalendarWindow $window): CalendarSourceResult
            {
                return CalendarSourceResult::complete([
                    CalendarOccurrence::timed(
                        id: 'publication:abc',
                        source: 'publication',
                        subjectType: 'publication',
                        subjectId: 'abc',
                        startsAt: $window->startsAt()->addHours(9),
                        endsAt: null,
                        title: 'A post goes out',
                        color: CalendarColor::PRIMARY,
                    ),
                ]);
            }
        });

        $result = app(CalendarQueryService::class)->occurrences(new CalendarWindow(
            startDate: '2026-08-01',
            endDate: '2026-08-07',
            timezone: 'UTC',
            sources: ['publication'],
        ));

        $this->assertCount(1, $result->occurrences);
        $this->assertSame('publication', $result->occurrences[0]->source);
        $this->assertSame([], $result->unavailableSources);

        // It joins the filter CATALOGUE too — carrying its own translated name, so the calendar screen
        // can render a chip for a source it has never heard of. That is the half of "no frontend change
        // per source" that a contract alone would not deliver.
        $this->assertArrayHasKey('publication', $result->sources);
        $this->assertSame('Publications', $result->sources['publication']);
    }

    /**
     * The registry is BOUND in Calendar's register() and written to from every source module's boot().
     * Laravel runs all register() methods before any boot(), which is what makes provider ORDER a
     * readability choice rather than a live dependency — the failure mode being designed out is a source
     * that registers into a registry that does not exist yet and vanishes without a word.
     *
     * `event` (R3 B3) is the Calendar's OWN subject and registers from the Calendar's own boot(). That
     * is the same rule the others follow — the mapping lives with the module that owns the subject —
     * not an exception to it, and it goes through the identical public registry method rather than a
     * privileged local path.
     */
    public function test_every_shipped_source_registered_itself(): void
    {
        $ids = app(CalendarSourceRegistry::class)->ids();

        sort($ids);

        $this->assertSame(['event', 'task', 'workflow_run', 'workflow_schedule'], $ids);
    }

    /**
     * A lazily registered source must not be CONSTRUCTED until something asks for it: this boot runs on
     * every request of the application, and building every module's source (plus its dependencies) to
     * serve a request that never opens a calendar is a cost nobody asked for.
     */
    public function test_sources_are_registered_lazily(): void
    {
        $registry = app(CalendarSourceRegistry::class);

        $constructed = false;

        $registry->registerLazy('lazy_probe', function () use (&$constructed): CalendarSource {
            $constructed = true;

            return new class implements CalendarSource
            {
                public function id(): string
                {
                    return 'lazy_probe';
                }

                public function label(): string
                {
                    return 'Lazy probe';
                }

                public function occurrences(CalendarWindow $window): CalendarSourceResult
                {
                    return CalendarSourceResult::complete([]);
                }
            };
        });

        $this->assertTrue($registry->has('lazy_probe'));
        $this->assertFalse($constructed, 'registering a source must not construct it');

        $registry->resolve('lazy_probe');

        $this->assertTrue($constructed);
    }

    /**
     * The registration KEY drives filtering, validation and labelling; the occurrence's own `source`
     * field comes from the source. Let the two disagree and the source produces occurrences that no
     * filter can ever select — so the disagreement is refused at resolve time instead.
     */
    public function test_a_source_registered_under_an_id_it_does_not_claim_is_refused(): void
    {
        $registry = app(CalendarSourceRegistry::class);

        $registry->registerLazy('claimed_id', fn (): CalendarSource => new class implements CalendarSource
        {
            public function id(): string
            {
                return 'actual_id';
            }

            public function label(): string
            {
                return 'Confused';
            }

            public function occurrences(CalendarWindow $window): CalendarSourceResult
            {
                return CalendarSourceResult::complete([]);
            }
        });

        $this->assertNull($registry->resolve('claimed_id'));
    }
}
