<?php

namespace Tests\Feature;

use App\Support\Recurrence\CompiledSchedule;
use App\Support\Recurrence\LegacyScheduleUpgrader;
use App\Support\Recurrence\ScheduleCompiler;
use App\Support\Recurrence\ScheduleEngine;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Tests\TestCase;

/**
 * THE SHARED RECURRENCE LAYER, PINNED.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS FILE EXISTS — read before editing anything in it
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * B1 moved the cadence engine out of the Workflows module and into `app/Support/Recurrence`
 * ({@see ScheduleEngine}, {@see ScheduleCompiler}, {@see CompiledSchedule},
 * {@see LegacyScheduleUpgrader} and the Schedule* enums). That move had exactly ONE reason: the
 * Calendar needs the same cadence arithmetic for recurring events, and
 * {@see CalendarModuleBoundaryTest} forbids the Calendar from naming Workflows. A layer BELOW both is
 * the only place the two can share an engine without one naming the other.
 *
 * As shipped, nothing enforces that. Today the layer is clean by CONVENTION — one `use` statement
 * pointing back at `App\Modules\Workflows`, added by an author who needed "just the workflow's
 * timezone", and the only reason the layer exists is quietly gone: the Calendar can no longer depend
 * on it without transitively depending on Workflows. Nothing would go red. That is what this file is
 * for.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * TWO PROPERTIES, GUARDED TOGETHER
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *   1. THE LAYER NAMES NO MODULE.  Not Workflows, not Calendar, not any future one. `App\Modules` in
 *      any spelling is a violation — see {@see test_the_recurrence_layer_names_no_module} — and there
 *      is NO EXEMPTION LIST, not even for a docblock cross-reference.
 *
 *      That was a decision, not an oversight, and it was taken the hard way round: this guard's first
 *      run found four foreign names in the engine's own docblock (the module facade that kept the
 *      impure half, plus the two sibling layers it cites as placement precedent). The obvious answer
 *      was a narrow carve-out — per exact name, comments only, rot-checked. It was built, and then
 *      deleted, because a list of four teaches the next author that adding a fifth is a normal move,
 *      and the fifth will have an argument every bit as good as these four had. A list of length zero
 *      cannot grow. The DOCBLOCK was reworded instead: it still says the module's schedule-service
 *      facade keeps the arming and the CAS claim, it just does not spell the namespace. The reader
 *      loses nothing; the guard gains having nothing to forgive.
 *
 *      Stated POSITIVELY as well, in {@see test_the_recurrence_layer_names_only_allowlisted_namespaces},
 *      because a denylist only forbids what somebody thought of — and there is a hole it would have
 *      missed: `App\Support\Pagination`, a SIBLING of this layer, imports Eloquent. "Lives in
 *      App\Support" means "is not a module". It does not mean "is pure".
 *
 *   2. THE LAYER HAS NO EXECUTIVE SURFACE.  No Eloquent, no `DB::`, no queue, no `dispatch`, no
 *      write of any kind, and NO PUBLIC METHOD THAT ACCEPTS A MODEL — the last one checked by
 *      REFLECTION over real signatures rather than by reading bytes, because a signature is a fact
 *      and a byte is a spelling.
 *
 * The second property is NOT decoration, and it is the half most likely to be deleted by somebody who
 * thinks it is. It is THE FIFTH DOOR of the calendar-event fence ({@see CalendarEventFenceTest},
 * ADR-0051 D4: *a calendar event is an annotation; nothing ever executes because one exists*). That
 * fence has four doors, and all four are about the CALENDAR MODULE — the table, the model, the read
 * source, the trigger vocabulary. None of them looks at `app/Support`. So a shared layer that could
 * execute is a way to make a calendar row do something WITHOUT BREAKING A SINGLE RULE WE HAVE TODAY:
 * the Calendar calls the recurrence layer (legally, once B3 lands), the recurrence layer queries or
 * dispatches (nothing forbids it), and the Calendar has become a scheduler by proxy. Keeping the
 * layer to a pure function of (descriptor, anchor) is what makes "the Calendar cannot execute
 * anything" a property of the code rather than of everybody's good intentions.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT THE SCANS HERE KNOW THAT EARLIER SCANS DID NOT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Both weaknesses below were found by probing existing guards, not by review:
 *
 *   ESCAPING     a namespace inside a double-quoted PHP string is written with DOUBLED backslashes —
 *                `app("App\\Modules\\Workflows\\Models\\Workflow")` — so the file bytes spell
 *                `App\\Modules` and a needle spelled with single ones never matched. That is the
 *                SHORTEST route to a forbidden class (a container lookup by class-name string) and it
 *                sailed through the boundary scans until backslash RUNS were collapsed first
 *                ({@see collapse}). Collapsing runs rather than halving them is stable under any
 *                depth of escaping and only ever makes the scan MORE sensitive — the correct
 *                direction for a guard to be wrong in.
 *
 *   BOUNDARIES   matching a needle as a run of characters makes `DB` match `MyDB` and makes every
 *                prefix a false positive of its own extension. {@see names} requires a non-identifier
 *                character on each side, so each needle means itself. Needles that already END in a
 *                non-identifier character (`DB::`, `event(`) skip the right-hand boundary, which is
 *                the whole reason they can be written that way at all.
 *
 * ANTI-VACUITY, everywhere. A guard that silently scans nothing passes forever and is strictly worse
 * than no guard: it converts an unprotected invariant into one everybody believes is protected. Every
 * scan below therefore also asserts that it FOUND the engine — by resolving the four load-bearing
 * classes through reflection and demanding their files be among the ones it read. A rename, a move or
 * a broken iterator turns this file red instead of green-and-empty.
 */
class RecurrenceLayerBoundaryTest extends TestCase
{
    /**
     * The layer's root. Everything below it is in scope — every file, every line, comments included,
     * with NO exception list of any kind. See the class docblock for why the four exemptions this
     * guard was born with were deleted rather than narrowed.
     */
    private const LAYER = 'Support/Recurrence';

    /**
     * The four classes the whole extraction was ABOUT. Named explicitly so every scan in this file can
     * prove it actually looked at them: this is the anti-vacuity anchor, and it is deliberately
     * spelled as class references (which fail at autoload) rather than as paths (which fail silently).
     *
     * @var array<int, class-string>
     */
    private const LOAD_BEARING = [
        ScheduleEngine::class,
        ScheduleCompiler::class,
        CompiledSchedule::class,
        LegacyScheduleUpgrader::class,
    ];

    /**
     * EVERY WAY TO EXECUTE SOMETHING, as this codebase spells them.
     *
     * The list is about CAPABILITY, not style. Each entry is something that could make the layer do
     * work in the world — read or write a row, put a job on a queue, touch the filesystem, reach the
     * network, fire a listener, or read ambient tenant state — and therefore something a consumer
     * could reach THROUGH the layer.
     *
     * `Illuminate\Support\Facades` is forbidden WHOLESALE rather than facade by facade: a pure
     * function of (descriptor, anchor) needs no facade at all, so the blanket ban costs nothing and
     * closes the ones nobody thought to list. The individual `Foo::` entries stay because a
     * root-aliased call (`\DB::table(...)`) never names the facade namespace.
     *
     * NOT ON THIS LIST, deliberately: `Cache::` and the `config()` helper. The engine's own docblock
     * records a MEASURED decision to memoize compilation later if it ever pays, and reads
     * `config('app.timezone')` as the descriptor's tz default. Both are reads of ambient
     * CONFIGURATION, not executions, and banning them would put this guard in the way of a sanctioned
     * change — which is how guards get deleted.
     *
     * @var array<int, string>
     */
    private const EXECUTIVE_NEEDLES = [
        // ── persistence ──────────────────────────────────────────────────────────
        'Illuminate\\Database',   // Eloquent, the query builder, the connection manager
        'App\\Models',            // the central models (User, AbstractModel)
        'DB::',
        // ── queue / async ────────────────────────────────────────────────────────
        'Illuminate\\Queue',
        'Illuminate\\Bus',
        'Illuminate\\Contracts\\Queue',
        'ShouldQueue',
        'Queue::',
        'Bus::',
        'dispatch',
        'dispatchSync',
        'dispatchAfterResponse',
        // ── writes to the world ──────────────────────────────────────────────────
        'Storage::',
        'file_put_contents',
        'fwrite',
        'unlink',
        // ── network ──────────────────────────────────────────────────────────────
        'Http::',
        // ── firing listeners ─────────────────────────────────────────────────────
        'Event::',
        'event(',
        // ── ambient application state ────────────────────────────────────────────
        'App\\Tenancy',           // the engine takes its tz from the descriptor, never from a tenant
        'Auth::',
        'Illuminate\\Support\\Facades',
        'Illuminate\\Foundation',
        'Illuminate\\Console',
        'Illuminate\\Http',
        'Artisan::',
    ];

    /**
     * THE CEILING: every namespace the layer is allowed to name, with a reason.
     *
     * The needle list above is a DENYLIST, and a denylist can only forbid what somebody thought of. It
     * has a hole with a name: `App\Support\Pagination\StagedCursorPaginator` imports Eloquent and the
     * query builder, and it is a SIBLING of this layer — same `App\Support` tree, one directory over.
     * Importing it would give the recurrence engine a persistence surface without matching a single
     * entry in EXECUTIVE_NEEDLES, because the impurity would be one hop away instead of in the file.
     * "App\Support" is not a synonym for "pure"; it is a synonym for "not a module".
     *
     * So this list is a positive statement of what a cadence engine needs: date arithmetic, cron
     * parsing, and its own value types. Nothing else, and every widening argued for here in writing.
     * That is the same instrument {@see CalendarModuleBoundaryTest} uses on the Calendar, and for the
     * same reason — a boundary held by "nobody forbade it" is not held.
     *
     * @var array<string, string>
     */
    private const ALLOWED_NAMESPACES = [
        'App\\Support\\Recurrence\\' => 'the layer itself',
        'Carbon\\' => 'the date library the whole engine is arithmetic over',
        'Cron\\' => 'the cron expression parser a compiled cadence is evaluated with',
    ];

    /**
     * Unnamespaced classes the layer may import. Enumerated rather than blanket-allowed, because the
     * global namespace is not a safe category: `PDO`, `SplFileObject` and `mysqli` live there too, and
     * a rule of "anything without a backslash is fine" would wave all three through.
     *
     * @var array<int, string>
     */
    private const ALLOWED_GLOBAL_IMPORTS = [
        'DateTime', 'DateTimeImmutable', 'DateTimeInterface', 'DateTimeZone',
        'InvalidArgumentException', 'LogicException', 'RuntimeException', 'Throwable',
    ];

    /**
     * PHP types a pure cadence layer is allowed to speak in. `object` and `mixed` are absent on
     * purpose: both are type-erased, so a model passes through either one and the reflection check
     * below would wave it through. `array` IS allowed and is the acknowledged hole — see the
     * "knowingly not caught" note on {@see test_no_public_signature_in_the_recurrence_layer_touches_a_model}.
     *
     * @var array<int, string>
     */
    private const ALLOWED_BUILTIN_TYPES = [
        'array', 'bool', 'false', 'float', 'int', 'never', 'null', 'self', 'static', 'string', 'true', 'void',
    ];

    /**
     * The non-builtin types the layer's public API may name: its OWN value types, and the date
     * primitives it is arithmetic over. Nothing else — in particular nothing from a module, nothing
     * from Eloquent, nothing from the framework.
     *
     * MIXED BY DESIGN, and the shape of each entry says how it is matched. An entry ending in `\` is a
     * NAMESPACE and matches by prefix; an entry without one is a CLASS and matches EXACTLY. The three
     * date classes are classes, and prefix-matching them would have granted every type whose name
     * merely begins with theirs — `DateTime` alone would admit `DateTimeSomethingElse`, and it would
     * do so silently, since the entry a reader checks is exactly the one they expect to find. This is
     * the same rule ALLOWED_GLOBAL_IMPORTS is written under: "starts with a name we trust" is a
     * category, and a category is not a permission. Enforced by {@see judge} and probed by
     * {@see test_the_type_allowlist_does_not_prefix_match_a_class_entry}.
     *
     * @var array<int, string>
     */
    private const ALLOWED_TYPE_NAMESPACES = [
        'App\\Support\\Recurrence\\',
        'Carbon\\',
        'DateTimeInterface',
        'DateTimeImmutable',
        'DateTime',
    ];

    // ── 1. THE LAYER NAMES NO MODULE ─────────────────────────────────────────────

    /**
     * No file under `app/Support/Recurrence` may name ANY module.
     *
     * The needle is the namespace ROOT (`App\Modules`), not a list of module names, and that is a
     * deliberate difference from the module-to-module boundary tests. Those guard a specific
     * direction and can afford to enumerate; this one guards the property "below all modules", which a
     * list can only ever approximate — a module invented next year would be missing from it, and the
     * omission would read as permission. So the ban is total and needs no maintenance: `App\Modules`
     * in ANY spelling, on ANY line, in ANY file here is a violation. Comments included, and NO
     * exemption list exists to argue with — see the class docblock for why the four this guard was
     * born with were deleted rather than narrowed.
     *
     * WHY THIS SURVIVES ALONGSIDE THE CEILING BELOW, which would also catch every module name: the
     * ceiling is editable. Someone who needed a module badly enough could add `App\Modules\Foo\` to
     * ALLOWED_NAMESPACES and turn it green in one line, with a plausible reason. This test cannot be
     * satisfied that way — the module ban is the one edge that is not a list entry, so widening the
     * ceiling never widens it.
     *
     * IF YOU ARE HERE BECAUSE THIS TEST FAILED: you need something a module owns. Do not import it —
     * that inverts the layer. Pass the value IN as a parameter (the caller already has the model), or,
     * if it is genuinely behaviour and not data, define a CONTRACT here and let the module bind the
     * concrete, the way Variables does for the author-voice seam. And if all you wanted was to point a
     * reader at the module, write the sentence in PROSE: name the collaborator, do not spell its
     * namespace. That is exactly what the engine's own docblock does.
     */
    public function test_the_recurrence_layer_names_no_module(): void
    {
        $violations = [];
        $files = $this->layerFiles();

        foreach ($files as $relative => $source) {
            foreach ($this->moduleMentions($source) as [$lineNumber, $fqcn]) {
                $violations[] = $relative . ':' . $lineNumber . ' names ' . $fqcn;
            }
        }

        $this->assertSame(
            [],
            $violations,
            'The shared recurrence layer named a module. It exists BELOW every module precisely so the '
            . 'Calendar and Workflows can share cadence arithmetic without naming each other; one import '
            . 'back into a module collapses that. A docblock mention counts — that is how a real import '
            . "starts, and prose says the same thing without leaving a name to match. Offenders:\n  - "
            . implode("\n  - ", $violations)
        );

        $this->assertScanSawTheEngine($files);
    }

    // ── 2. THE LAYER HAS NO EXECUTIVE SURFACE (bytes) ────────────────────────────

    /**
     * No file under `app/Support/Recurrence` may name anything capable of EXECUTING.
     *
     * This is the fifth door of the calendar-event fence (see the class docblock). A calendar that may
     * not execute anything, calling a shared layer that may, has not been fenced — it has been fenced
     * everywhere except at the one seam that was built after the fence was written.
     *
     * IF YOU ARE HERE BECAUSE THIS TEST FAILED: the answer is almost never an allowlist entry. Work
     * out which side of the seam the impurity belongs on. Arming a model, claiming a due slot and
     * writing `next_due_at` are the IMPURE half and already have a home —
     * {@see \App\Modules\Workflows\Services\WorkflowScheduleService} keeps exactly three such methods
     * and delegates every computation down here. Anything the Calendar needs that executes belongs in
     * the Calendar's own module, where the fence can see it.
     */
    public function test_the_recurrence_layer_has_no_executive_surface(): void
    {
        $violations = [];
        $files = $this->layerFiles();

        foreach ($files as $relative => $source) {
            foreach (self::EXECUTIVE_NEEDLES as $needle) {
                if ($this->names($source, $needle)) {
                    $violations[] = $relative . ' names [' . $needle . ']';
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'The shared recurrence layer grew an executive surface. It must stay a PURE function of '
            . '(descriptor, anchor): no persistence, no queue, no writes, no ambient tenant state. This is '
            . 'the fifth door of the calendar-event fence — the Calendar may not execute anything, so a '
            . "shared layer it calls may not execute anything either (ADR-0051 D4). Offenders:\n  - "
            . implode("\n  - ", $violations)
        );

        $this->assertScanSawTheEngine($files);
    }

    /**
     * THE CEILING: the layer names its own namespace, `Carbon\`, `Cron\` and a short list of global
     * value types — and NOTHING else, imported or written out in full.
     *
     * This is the positive counterpart to the needle scan, and it exists because of one concrete
     * bypass: `App\Support\Pagination` sits one directory away, carries Eloquent, and matches no
     * needle. A denylist could only ever have caught that by somebody having already thought of it.
     *
     * Two extraction passes, because there are two ways to name a class:
     *   IMPORTS       `use Foo\Bar;` — the ordinary way, and the only one that can be aliased.
     *   INLINE `App\` a fully-qualified reference written out at the point of use, or inside a string.
     *                 Backslash runs are collapsed first, so the escaped-string form is not a way past.
     *
     * The inline pass is deliberately limited to `App\` names. Enumerating every vendor class written
     * inline would be a different (and much noisier) test, and the framework classes that matter are
     * already needles; what is NOT already covered — and what this closes — is a sibling of this layer
     * inside our own codebase.
     */
    public function test_the_recurrence_layer_names_only_allowlisted_namespaces(): void
    {
        $violations = [];
        $files = $this->layerFiles();
        $seen = 0;

        foreach ($files as $relative => $source) {
            // ── imports ──────────────────────────────────────────────────────────
            foreach (explode("\n", $source) as $index => $line) {
                if (preg_match('/^use\s+(?:function\s+|const\s+)?([^;]+);/', trim($line), $match) !== 1) {
                    continue;
                }

                $imported = trim($match[1]);

                // A grouped import (`use A\{B, C};`) is not parsed here. Refusing it outright beats
                // skipping it: an unparsed line is a silent hole, and this layer has never used one.
                if (str_contains($imported, '{')) {
                    $violations[] = $relative . ':' . ($index + 1) . ' uses a GROUPED import, which this '
                        . 'guard deliberately does not parse — write it out one class per line';

                    continue;
                }

                // `use X as Y` — judge X, the thing actually being imported.
                $imported = trim(preg_split('/\s+as\s+/i', $imported)[0]);
                $seen++;

                if (!str_contains($imported, '\\')) {
                    if (!in_array($imported, self::ALLOWED_GLOBAL_IMPORTS, true)) {
                        $violations[] = $relative . ':' . ($index + 1) . ' imports the global ' . $imported;
                    }

                    continue;
                }

                if (!$this->isAllowedNamespace($imported)) {
                    $violations[] = $relative . ':' . ($index + 1) . ' imports ' . $imported;
                }
            }

            // ── inline App\ references ───────────────────────────────────────────
            foreach ($this->appMentions($source) as [$lineNumber, $reference]) {
                $seen++;

                if ($this->isAllowedNamespace($reference)) {
                    continue;
                }

                $violations[] = $relative . ':' . $lineNumber . ' names ' . $reference;
            }
        }

        $this->assertSame(
            [],
            $violations,
            'The shared recurrence layer named something outside its ceiling. A cadence engine needs date '
            . 'arithmetic and a cron parser; anything else it reaches for is either a module (which '
            . 'inverts the layer) or another shared layer that may not be pure — App\\Support\\Pagination, '
            . 'one directory over, imports Eloquent. Add a prefix to ALLOWED_NAMESPACES with a reason, or '
            . "do not reach for it. Offenders:\n  - " . implode("\n  - ", $violations)
        );

        $this->assertGreaterThan(
            0,
            $seen,
            'the ceiling saw neither an import nor an App\\ reference — the extraction is broken and this '
            . 'test is passing on an empty set'
        );
        $this->assertScanSawTheEngine($files);
    }

    // ── 3. THE LAYER HAS NO EXECUTIVE SURFACE (signatures) ───────────────────────

    /**
     * NO PUBLIC METHOD IN THE LAYER ACCEPTS OR RETURNS A MODEL — checked by REFLECTION.
     *
     * Why reflection and not another byte scan: a byte scan answers "does this file contain the string
     * `Workflow`", which is a question about spelling. An import alias (`use ... as Subject`), a type
     * imported for one purpose and reused for another, or a class moved under a name the scan has
     * never heard of all defeat it. `ReflectionParameter::getType()` answers "what does this method
     * actually accept", which is the question. A signature is a fact.
     *
     * The rule is enforced twice, from opposite directions, because each catches what the other
     * misses:
     *
     *   NEGATIVE   no named type is, or extends, `Illuminate\Database\Eloquent\Model`. Crisp message,
     *              exactly the stated rule, works for a model this test has never heard of.
     *   POSITIVE   every named type is either an allowed builtin or lives in
     *              {@see ALLOWED_TYPE_NAMESPACES}. This is the stronger half: it also refuses a query
     *              builder, an Eloquent collection, a module DTO, a Request — none of which are
     *              Models, and every one of which would carry the layer somewhere it may not go.
     *
     * PROPERTIES are checked with the same rule, public or not. A promoted constructor property is
     * already a parameter, but a plain `private Workflow $workflow` assigned in a method body is not,
     * and it means the layer is holding a model even if no signature admits it.
     *
     * CONSTRUCTORS count as public methods when they are public. {@see CompiledSchedule}'s is private
     * — its named constructors are the API — so it is correctly out of scope here and still covered by
     * the property check.
     *
     * ── WHAT THIS KNOWINGLY DOES NOT CATCH ──────────────────────────────────────────────────────
     * A model smuggled inside an `array`. `nextDueAt(array $schedule, ...)` takes the descriptor as an
     * array, and PHP's type system has nothing to say about what is in it. No static check closes
     * that; what closes it is that the layer never calls a method on anything it finds in the array —
     * which is what the BYTE scan above is for. The two halves are not redundant.
     */
    public function test_no_public_signature_in_the_recurrence_layer_touches_a_model(): void
    {
        $violations = [];
        $checked = 0;

        foreach ($this->layerClasses() as $fqcn) {
            $reflection = new ReflectionClass($fqcn);

            // A layer class may not INHERIT a foreign surface either: `extends` a framework base would
            // hand it every public method that base has, none of which reflection below would attribute
            // to this class.
            $parent = $reflection->getParentClass();
            if ($parent !== false && !str_starts_with($parent->getName(), 'App\\Support\\Recurrence\\')) {
                $violations[] = $fqcn . ' extends ' . $parent->getName() . ' (outside the layer)';
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $fqcn) {
                    continue;
                }

                foreach ($method->getParameters() as $parameter) {
                    foreach ($this->typeNames($parameter->getType()) as $type) {
                        $checked++;
                        $violations = [
                            ...$violations,
                            ...$this->judge($type, $fqcn . '::' . $method->getName() . '($' . $parameter->getName() . ')'),
                        ];
                    }
                }

                foreach ($this->typeNames($method->getReturnType()) as $type) {
                    $checked++;
                    $violations = [
                        ...$violations,
                        ...$this->judge($type, $fqcn . '::' . $method->getName() . '(): ' . $type),
                    ];
                }
            }

            foreach ($reflection->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $fqcn) {
                    continue;
                }

                foreach ($this->typeNames($property->getType()) as $type) {
                    $checked++;
                    $violations = [
                        ...$violations,
                        ...$this->judge($type, $fqcn . '::$' . $property->getName()),
                    ];
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'A signature in the shared recurrence layer names something it may not. The layer speaks in '
            . 'descriptors (arrays), instants (Carbon) and its own value types — never in models, query '
            . 'builders, requests or module types. Taking a model here would make the layer an executor, '
            . "which is the fifth door of the calendar-event fence (ADR-0051 D4). Offenders:\n  - "
            . implode("\n  - ", $violations)
        );

        // ANTI-VACUITY. A reflection walk that resolved no classes, or classes with no typed
        // signatures, would pass in silence. The floor is far below the real count.
        $this->assertGreaterThan(
            20,
            $checked,
            'the signature walk inspected almost no types — it is not looking at the engine'
        );
    }

    /**
     * The layer's directory really holds the engine, and the engine really lives in the layer.
     *
     * Stated as its own test because it is the assumption EVERY other test in this file rests on: they
     * all scan a directory, and a directory that has moved makes them all pass while guarding nothing.
     * Resolving the classes through the autoloader (not through paths) means a rename fails here, once
     * and loudly, with the class name in the message.
     */
    public function test_the_layer_holds_the_engine(): void
    {
        $root = app_path(self::LAYER);
        $this->assertDirectoryExists($root, 'the shared recurrence layer must exist at ' . $root);

        foreach (self::LOAD_BEARING as $fqcn) {
            $this->assertTrue(class_exists($fqcn), $fqcn . ' must exist — the layer is what B1 extracted');

            $file = (new ReflectionClass($fqcn))->getFileName();
            $this->assertIsString($file, $fqcn . ' must be a real file-backed class');
            $this->assertStringStartsWith(
                $this->slashes($root) . '/',
                $this->slashes((string) $file),
                $fqcn . ' must live under app/' . self::LAYER . ' — if it moved, every scan in this file '
                . 'is now looking at the wrong directory'
            );
        }
    }

    /**
     * THE TYPE MATCHER ITSELF, PROBED.
     *
     * The signature guard above is only as strong as the question it asks, and "does this type start
     * with something we allow" is a weaker question than the list looks like it is asking. A class
     * entry that prefix-matched would wave through every type whose name merely begins with it, while
     * the allowlist a reader inspects would still read exactly as intended — the failure would be
     * invisible from both ends. So the distinction is asserted, not assumed.
     */
    public function test_the_type_allowlist_does_not_prefix_match_a_class_entry(): void
    {
        // The class entries themselves are allowed...
        $this->assertTrue($this->isAllowedType('DateTime'));
        $this->assertTrue($this->isAllowedType('DateTimeImmutable'));
        $this->assertTrue($this->isAllowedType('DateTimeInterface'));

        // ...and nothing that merely starts like them is.
        $this->assertFalse($this->isAllowedType('DateTimeSomethingElse'));
        $this->assertFalse($this->isAllowedType('DateTimeZone'));
        $this->assertFalse($this->isAllowedType('DateTimeInterfaceProxy'));

        // A NAMESPACE entry keeps matching by prefix.
        $this->assertTrue($this->isAllowedType('Carbon\\CarbonImmutable'));
        $this->assertTrue($this->isAllowedType('App\\Support\\Recurrence\\CompiledSchedule'));
        $this->assertFalse($this->isAllowedType('CarbonExtras\\Thing'));
        $this->assertFalse($this->isAllowedType('App\\Support\\Pagination\\StagedCursorPaginator'));
        $this->assertFalse($this->isAllowedType('Illuminate\\Http\\Request'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────────

    /**
     * Every PHP file in the layer, as relative-path => source.
     *
     * @return array<string, string>
     */
    private function layerFiles(): array
    {
        $root = app_path(self::LAYER);
        $this->assertDirectoryExists($root);

        $files = [];

        /** @var iterable<\SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $absolute = $this->slashes($file->getPathname());
            $relative = substr($absolute, strlen($this->slashes(base_path())) + 1);

            $files[$relative] = (string) file_get_contents($file->getPathname());
        }

        return $files;
    }

    /**
     * Every class/enum the layer declares, derived from its files and resolved through the autoloader.
     *
     * Resolution failure is a FAILURE, never a skip: a file the walk cannot turn into a class is a
     * file the signature check silently ignores, and "silently ignored" is the exact shape of the
     * vacuous guard this file is built to avoid.
     *
     * @return array<int, class-string>
     */
    private function layerClasses(): array
    {
        $classes = [];

        foreach (array_keys($this->layerFiles()) as $relative) {
            // app/Support/Recurrence/Enums/ScheduleDayMode.php -> App\Support\Recurrence\Enums\ScheduleDayMode
            $withoutRoot = substr($relative, strlen('app/' . self::LAYER . '/'));
            $fqcn = 'App\\' . str_replace('/', '\\', self::LAYER) . '\\'
                . str_replace('/', '\\', substr($withoutRoot, 0, -strlen('.php')));

            $this->assertTrue(
                class_exists($fqcn) || enum_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn),
                $relative . ' does not declare ' . $fqcn . ' — PSR-4 says it must, and a file the '
                . 'reflection walk cannot resolve is a file it silently stops guarding'
            );

            $classes[] = $fqcn;
        }

        $this->assertNotEmpty($classes, 'the layer declared no classes — this walk is guarding nothing');

        return $classes;
    }

    /**
     * ANTI-VACUITY for the byte scans: the files just read must include the engine itself.
     *
     * @param  array<string, string>  $files
     */
    private function assertScanSawTheEngine(array $files): void
    {
        $this->assertNotEmpty($files, 'the layer scan read no files at all — it is guarding nothing');

        foreach (self::LOAD_BEARING as $fqcn) {
            $file = (string) (new ReflectionClass($fqcn))->getFileName();
            $relative = substr($this->slashes($file), strlen($this->slashes(base_path())) + 1);

            $this->assertArrayHasKey(
                $relative,
                $files,
                'the scan did not read ' . $relative . ' (' . $fqcn . ') — it is not looking where the engine is'
            );
        }
    }

    /**
     * Whether a source NAMES a needle, as a whole token rather than as a run of characters, and with
     * backslash runs collapsed first so escaping cannot hide it. See the class docblock for the two
     * real bypasses this answers.
     *
     * The right-hand boundary is applied only when the needle ENDS in an identifier character. That is
     * what lets `DB::` and `event(` be written as they are: demanding a non-identifier character after
     * `DB::` would refuse to match `DB::table(...)`, which is the only thing anyone ever writes.
     */
    private function names(string $source, string $needle): bool
    {
        $collapsed = $this->collapse($source);

        $left = preg_match('/[A-Za-z0-9_]/', $needle[0]) === 1 ? '(?<![A-Za-z0-9_])' : '';
        $right = preg_match('/[A-Za-z0-9_]/', $needle[strlen($needle) - 1]) === 1 ? '(?![A-Za-z0-9_])' : '';

        return preg_match('/' . $left . preg_quote($this->collapse($needle), '/') . $right . '/', $collapsed) === 1;
    }

    /**
     * Every `App\…` name a source file mentions, as [line number, fully-qualified name].
     *
     * Backslash runs are collapsed FIRST, so a namespace written inside a double-quoted string —
     * `app("App\\Modules\\Workflows\\Models\\Workflow")`, the shortest route to a forbidden class —
     * reads exactly like an import. The match is GREEDY over namespace characters, so a name is judged
     * whole: a scan that stopped at `App\Support` could not tell this layer from the Eloquent-carrying
     * one next door.
     *
     * A leading `(?<![A-Za-z0-9_])` keeps `MyApp\Modules\…` from reading as `App\Modules\…`.
     *
     * WHY THIS STILL WALKS LINE BY LINE now that no test asks whether a mention sits in a comment: the
     * line NUMBER is the whole payload of a failure message. "ScheduleEngine.php names App\Modules\…"
     * sends the reader hunting; "ScheduleEngine.php:22" does not. The raw line text used to be carried
     * alongside it for the comment check, and was dropped with that check — a tuple slot nobody reads
     * is the same species of lie as a guard that scans nothing.
     *
     * @return array<int, array{0: int, 1: string}>
     */
    private function appMentions(string $source): array
    {
        $mentions = [];

        foreach (explode("\n", $this->collapse($source)) as $index => $line) {
            if (preg_match_all('/(?<![A-Za-z0-9_])App\\\\[A-Za-z0-9_\\\\]*/', $line, $matches) === 0) {
                continue;
            }

            foreach ($matches[0] as $fqcn) {
                $mentions[] = [$index + 1, rtrim($fqcn, '\\')];
            }
        }

        return $mentions;
    }

    /**
     * The subset of {@see appMentions} that names a module. `App\Modules` on its own counts — a bare
     * prefix in a string is one concatenation away from a class name.
     *
     * @return array<int, array{0: int, 1: string}>
     */
    private function moduleMentions(string $source): array
    {
        return array_values(array_filter(
            $this->appMentions($source),
            static fn (array $mention): bool => $mention[1] === 'App\\Modules'
                || str_starts_with($mention[1], 'App\\Modules\\'),
        ));
    }

    /**
     * Whether a fully-qualified name sits under one of the layer's allowed namespaces.
     *
     * The trailing backslash is trimmed for the equality case so a bare `namespace App\Support\Recurrence;`
     * line matches the `App\Support\Recurrence\` prefix. Prefixes are WRITTEN with the trailing
     * backslash on purpose — without it, `Carbon` would also allow a `CarbonExtras\` package. Every
     * entry in ALLOWED_NAMESPACES is a namespace, so prefix matching is right for all of them; the
     * TYPE list is the mixed one, and {@see isAllowedType} is where that distinction is made.
     */
    private function isAllowedNamespace(string $fqcn): bool
    {
        foreach (array_keys(self::ALLOWED_NAMESPACES) as $prefix) {
            if ($fqcn === rtrim($prefix, '\\') || str_starts_with($fqcn, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a type name is one the layer may speak in — namespace entries by prefix, class entries
     * EXACTLY. See ALLOWED_TYPE_NAMESPACES for why the two are matched differently.
     */
    private function isAllowedType(string $type): bool
    {
        foreach (self::ALLOWED_TYPE_NAMESPACES as $entry) {
            if (!str_ends_with($entry, '\\')) {
                if ($type === $entry) {
                    return true;
                }

                continue;
            }

            if ($type === rtrim($entry, '\\') || str_starts_with($type, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collapse every RUN of backslashes to one, so `App\\Modules` (a namespace inside a double-quoted
     * string) and `App\Modules` (an import) are the same thing to the scan.
     */
    private function collapse(string $value): string
    {
        return (string) preg_replace('/\\\\+/', '\\', $value);
    }

    /**
     * Flatten a reflection type into the names it mentions — union and intersection members included,
     * because `Workflow|null` and `Model&Arrayable` are types too and a walk that only understood
     * `ReflectionNamedType` would read straight past them.
     *
     * @return array<int, string>
     */
    private function typeNames(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $names = [];

            foreach ($type->getTypes() as $member) {
                $names = [...$names, ...$this->typeNames($member)];
            }

            return $names;
        }

        return [];
    }

    /**
     * The verdict on one type name, at one place. Returns the violations it found (none, or one from
     * each of the two directions described on the test).
     *
     * @return array<int, string>
     */
    private function judge(string $type, string $where): array
    {
        $violations = [];

        if (in_array(strtolower($type), self::ALLOWED_BUILTIN_TYPES, true)) {
            return [];
        }

        // NEGATIVE: is it a model?
        if (
            (class_exists($type) || interface_exists($type))
            && is_a($type, 'Illuminate\\Database\\Eloquent\\Model', true)
        ) {
            $violations[] = $where . ' accepts/returns the MODEL ' . $type
                . ' — the layer computes cadence, it never touches persistence';
        }

        // POSITIVE: is it something the layer is allowed to speak in at all?
        if (!$this->isAllowedType($type)) {
            $violations[] = $where . ' names ' . $type
                . ', which is neither an allowed builtin nor one of ['
                . implode(', ', self::ALLOWED_TYPE_NAMESPACES) . ']';
        }

        return $violations;
    }

    private function slashes(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
