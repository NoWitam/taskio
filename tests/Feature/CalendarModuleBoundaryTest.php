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
     * THE CEILING: the Calendar may name its OWN classes and NOTHING ELSE under `App\` except the
     * namespaces listed below, each with a written reason.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * WHY THIS EXISTS ALONGSIDE THE TEST ABOVE — it is a TIGHTENING, not a relaxation
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * The test above is a DENYLIST. It names the modules the Calendar must not touch, and everything
     * it does not name is permitted — by omission. `App\Support\*` passes it today for exactly that
     * reason: not because anybody weighed it, but because nobody wrote it down. That is the
     * "allowed-because-unforbidden" mechanism ADR-0051 criticises in its own text, sitting in the test
     * meant to enforce the ADR.
     *
     * Two concrete costs of leaving it that way:
     *   - a module invented next year (`App\Modules\Publishing` was only on the denylist because
     *     somebody thought ahead; the next one will not be) is permitted the day it is created;
     *   - a whole namespace tree — `App\Support`, `App\Services`, anything — can grow a dependency
     *     edge into the Calendar with nobody ever making a decision about it.
     *
     * After this test, the door is still open where it needs to be, but it is NARROW and every widening
     * has to be argued for HERE, in writing, by the person doing it. That is the entire point, and it
     * is why the list carries reasons rather than just prefixes.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * A CEILING, NOT AN INVENTORY
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * An entry that nothing currently uses is NOT asserted stale, and that is deliberate — it is the
     * opposite of the rule the calendar-event fence applies to ITS allowlist. The difference is what
     * each list grants. The fence's list exempts a FILE PATH, so a stale entry silently hands an
     * exemption to whatever file takes that path next; that has to rot-check. This list is a standing
     * architectural decision about a namespace, so an unused entry grants nothing that was not already
     * reasoned about, and requiring "already used" would make it impossible to state a decision
     * BEFORE the code that relies on it — which is exactly what `App\Support\Recurrence` is here to do.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * A TRAILING BACKSLASH IS PART OF THE ENTRY, NOT TYPOGRAPHY
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * An entry ENDING in `\` is a NAMESPACE and matches by prefix — that is the point of granting one.
     * An entry that does not is a CLASS and matches EXACTLY, because a class name is not a namespace
     * and prefix-matching one grants every name that merely starts with it: the single class entry
     * below (`App\Http\Controllers\Controller`) would otherwise also admit `ControllerFactory`,
     * `ControllerBase`, or an `App\Http\Controllers\ControllerHelpers\` tree nobody argued for. The
     * distinction is enforced by {@see allows()} and probed by
     * {@see test_the_allowlist_matcher_does_not_prefix_match_a_class_entry}, not left to a reader
     * noticing the backslash. This is the same discipline the recurrence layer's global-import list
     * applies for the same reason: a category ("anything starting with…") is not a permission.
     *
     * @var array<string, string>
     */
    private const ALLOWED_FOREIGN_NAMESPACES = [
        // The shared cadence engine extracted in R3 B1. LISTED BEFORE IT IS USED, on purpose: recurring
        // calendar events are the next chapter, and the whole reason the engine was moved OUT of
        // Workflows and DOWN into App\Support was so the Calendar could use it without naming Workflows.
        // Its own guard (RecurrenceLayerBoundaryTest) keeps it a pure function of (descriptor, anchor)
        // with no executive surface, which is what makes this edge safe to grant in advance rather than
        // a hole to be discovered later.
        'App\\Support\\Recurrence\\' => 'the shared recurrence engine — the layer BELOW both Calendar and Workflows',

        // Central persistence primitives. `AbstractModel` is the base every model in this codebase
        // extends; `User` is the subject every Policy takes. Neither belongs to a module.
        'App\\Models\\' => 'the central model base and the User a Policy authorises',
        'App\\Traits\\' => 'HasCreator / TenantAware — the model concerns every module composes',

        // Tenancy. The Calendar reads the active workspace's TIMEZONE, and this is the sanctioned route
        // for it: through shared infrastructure, never by naming the Workspaces module. Keeping the
        // grant at `App\Tenancy` is what stops "reads a workspace setting" drifting into "imports
        // Workspaces" — the denylist above pins the other half of that same carve-out.
        'App\\Tenancy\\' => 'TenantContext — the workspace timezone, without naming the Workspaces module',

        // Shared authorization + HTTP plumbing the module plugs into rather than reimplements.
        'App\\Policies\\Concerns\\' => 'ChecksRecordOwnership — the app-wide ownership rule',
        'App\\Http\\Controllers\\Controller' => 'the base controller every module controller extends',
        'App\\Http\\Middleware\\' => 'RequireWorkspace, applied to the module\'s own route group',
        'App\\Http\\Resources\\' => 'CreatorResource — the app-wide shape of "who made this"',
    ];

    /**
     * Nothing under `App\` outside {@see ALLOWED_FOREIGN_NAMESPACES} may be named from the Calendar.
     *
     * IF YOU ARE HERE BECAUSE THIS TEST FAILED, in order of likelihood:
     *   1. You imported a MODULE. Do not. Implement `CalendarSource` in THAT module's namespace and
     *      register it from that module's provider — `test_a_new_source_can_join_without_touching_the
     *      _calendar_module` below is the executable proof that this works.
     *   2. You imported shared infrastructure that genuinely belongs to no module. Add a prefix above
     *      WITH A REASON. Keep it as narrow as the thing you need: `App\Support\Recurrence\`, not
     *      `App\Support\`.
     *   3. You wrote a class name in a docblock. Same rules — the scan is literal over the bytes,
     *      because a docblock reference is exactly how a real import starts.
     */
    public function test_the_calendar_names_only_allowlisted_foreign_namespaces(): void
    {
        $root = app_path('modules/Calendar');
        $this->assertDirectoryExists($root);

        $scanned = 0;
        $foreign = 0;
        $violations = [];

        /** @var iterable<\SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $scanned++;
            $relative = str_replace(str_replace('\\', '/', base_path()) . '/', '', str_replace('\\', '/', $file->getPathname()));
            $source = $this->normalize((string) file_get_contents($file->getPathname()));

            preg_match_all('/(?<![A-Za-z0-9_])App\\\\[A-Za-z0-9_\\\\]+/', $source, $matches);

            foreach (array_unique($matches[0]) as $reference) {
                // The module's own namespace. `App\Modules\Calendar` itself (the provider's
                // `namespace` line) counts, as does anything beneath it.
                if ($reference === 'App\\Modules\\Calendar' || str_starts_with($reference, 'App\\Modules\\Calendar\\')) {
                    continue;
                }

                $foreign++;

                if ($this->allows($reference)) {
                    continue;
                }

                $violations[] = $relative . ' names ' . $reference;
            }
        }

        $this->assertSame(
            [],
            $violations,
            'The Calendar named something outside its own module that nobody has argued for. The Calendar '
            . 'knows NOBODY: it owns a contract and a registry, and the modules with something to show '
            . 'implement that contract in THEIR namespace. If what you need is genuinely shared '
            . 'infrastructure, add the narrowest possible prefix to ALLOWED_FOREIGN_NAMESPACES with a '
            . "reason. Offenders:\n  - " . implode("\n  - ", $violations)
        );

        // ANTI-VACUITY, in two parts, because this guard has two ways to become a no-op. A moved module
        // makes it scan nothing; a broken extraction regex makes it scan everything and FIND nothing —
        // and the second failure mode looks exactly like success.
        $this->assertGreaterThan(0, $scanned, 'expected to scan the Calendar module source files');
        $this->assertGreaterThan(
            0,
            $foreign,
            'the scan found no foreign App\\ reference at all — the Calendar demonstrably has several '
            . '(TenantContext, User, AbstractModel), so the extraction is broken and this test is passing '
            . 'on an empty set'
        );
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
     * Whether a fully-qualified name is covered by the allowlist, with the entry's own shape deciding
     * HOW it is matched: a namespace (trailing `\`) matches by prefix, a class matches exactly. See
     * the ALLOWED_FOREIGN_NAMESPACES docblock for why a class entry must not prefix-match.
     *
     * A namespace entry also matches the namespace itself with the trailing backslash trimmed, so a
     * bare `namespace App\Support\Recurrence;` line satisfies the `App\Support\Recurrence\` grant.
     */
    private function allows(string $reference): bool
    {
        foreach (array_keys(self::ALLOWED_FOREIGN_NAMESPACES) as $entry) {
            if (!str_ends_with($entry, '\\')) {
                if ($reference === $entry) {
                    return true;
                }

                continue;
            }

            if ($reference === rtrim($entry, '\\') || str_starts_with($reference, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * THE MATCHER ITSELF, PROBED — because every other assertion in this file is only as strong as it.
     *
     * A class entry that prefix-matched would hand a silent grant to every name that merely starts
     * with it, and nothing about the allowlist would look wrong: the entry a reader checks would be
     * the one they expect to see. The probe below is what makes the difference between "matches by
     * prefix" and "matches exactly" a fact rather than an intention.
     */
    public function test_the_allowlist_matcher_does_not_prefix_match_a_class_entry(): void
    {
        // The class entry itself is allowed...
        $this->assertTrue($this->allows('App\\Http\\Controllers\\Controller'));

        // ...and NOTHING that merely starts with it is.
        $this->assertFalse($this->allows('App\\Http\\Controllers\\ControllerFactory'));
        $this->assertFalse($this->allows('App\\Http\\Controllers\\Controllers\\Anything'));
        $this->assertFalse($this->allows('App\\Http\\Controllers\\ControllerHelpers\\Secret'));

        // A NAMESPACE entry keeps matching by prefix — that is what granting a namespace means.
        $this->assertTrue($this->allows('App\\Support\\Recurrence\\ScheduleEngine'));
        $this->assertTrue($this->allows('App\\Support\\Recurrence'));
        $this->assertFalse($this->allows('App\\Support\\RecurrenceExtras\\Thing'));
        $this->assertFalse($this->allows('App\\Support\\Pagination\\StagedCursorPaginator'));

        // Anything nobody argued for stays out.
        $this->assertFalse($this->allows('App\\Modules\\Workflows\\Models\\Workflow'));
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
     *
     * `publication` (R4 B1) is the headline scenario of this whole file, arrived: a source from a module
     * that did not exist when the Calendar was written, joining without a line changing under
     * app/modules/Calendar. THIS ASSERTION IS THE ONLY THING R4 EDITED OUTSIDE ITS OWN MODULE, and it is
     * an INVENTORY — a list of what ships — rather than Calendar code. Every other test in this file
     * passed unchanged, including the two scans that read the Calendar's file bytes.
     */
    public function test_every_shipped_source_registered_itself(): void
    {
        $ids = app(CalendarSourceRegistry::class)->ids();

        sort($ids);

        $this->assertSame(['event', 'publication', 'task', 'workflow_run', 'workflow_schedule'], $ids);
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
