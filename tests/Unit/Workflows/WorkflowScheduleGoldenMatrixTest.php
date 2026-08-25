<?php

namespace Tests\Unit\Workflows;

use App\Modules\Workflows\Services\WorkflowScheduleService;
use App\Support\Recurrence\LegacyScheduleUpgrader;
use App\Support\Recurrence\ScheduleCompiler;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tests\Support\Schedule\ScheduleGoldenMatrix;
use Tests\TestCase;
use ValueError;

/**
 * ABSOLUTE-VALUE CHARACTERIZATION of the workflow schedule engine.
 *
 * =====================================================================================
 * THIS TEST HAS AN EXPIRY DATE. It is scaffolding for ONE refactor, not a specification
 * of the product. Written 2026-08-25 against commit e501cf2, to gate the extraction of
 * WorkflowScheduleService / WorkflowScheduleCompiler / CompiledSchedule /
 * LegacyScheduleUpgrader / the Schedule* enums out of the Workflows module and into a
 * shared layer, so the Calendar can drive recurring events from the same engine.
 *
 * DELETE IT — together with tests/Support/Schedule/ — once that extraction has shipped
 * and been reviewed. Do not grow it, do not treat a value in the fixture as a promise to
 * a customer, and do not "fix" a failing row by regenerating the fixture. The behaviours
 * that are genuinely intended are specified by name in WorkflowScheduleServiceTest,
 * WorkflowScheduleCompilerTest and WorkflowScheduleLegacyUpgraderTest; those are the
 * files that outlive this one.
 * =====================================================================================
 *
 * WHY IT EXISTS. WorkflowScheduleEngineParityTest looks like the safety net for this move and is
 * not: it compares a COLD service instance against a WARM one, so both sides are the post-refactor
 * code and both move together. It pins statelessness, not correctness. A refactor that shifted every
 * schedule by an hour would pass all of it. The engine decides whether customers' automations fire,
 * and it runs every minute; "the tests were green" is not worth much if the tests only ever asked
 * the new code to agree with itself.
 *
 * So this file names CONCRETE INSTANTS. Every expectation is a literal in
 * tests/Support/Schedule/schedule-golden-matrix.json, generated once from the engine as it stood
 * BEFORE the extraction and committed alongside it. The test computes actuals and compares them to
 * that file. It never recomputes an expectation, and it never asks the engine to be its own oracle.
 *
 * WHAT IS PINNED. Every pure public method of the two seams being moved, over the whole descriptor
 * x anchor matrix:
 *   WorkflowScheduleService::nextDueAt / previousOrAtOccurrence / occurrencesFrom /
 *                            nextOccurrences (anchored AND from the frozen clock) /
 *                            isApproximate / timezone
 *   WorkflowScheduleCompiler::compile   (the cron grammar itself — the precise failure locator)
 *   LegacyScheduleUpgrader::toV2        (the read-shim old rows still depend on)
 * The impure members (arm, claimDue, isArmable) touch models and are covered by the sweep tests.
 *
 * ENVIRONMENT. Both the app timezone and "now" are set explicitly here, never inherited: this repo
 * has no `.env.testing`, and a descriptor with no `tz` key resolves through `config('app.timezone')`.
 * Leaving either to the machine is how a green suite goes red for a reason unrelated to the code.
 */
class WorkflowScheduleGoldenMatrixTest extends TestCase
{
    /**
     * Floors, not equalities: the matrix may GROW (and then the fixture must be regenerated, which
     * the key-set assertions force), but it must never quietly shrink. A characterization test that
     * has lost its cases still passes, which is the one failure mode that would make this whole file
     * a decoration.
     */
    private const MINIMUM_DESCRIPTORS = 70;

    private const MINIMUM_ANCHORS = 16;

    private const MINIMUM_CASES = 1120;

    /**
     * Emptiness guard. Roughly 96% of the matrix resolves to a real instant (only the deliberately
     * unreachable cadence is null everywhere), so an engine that started answering null — or a
     * harness that stopped calling it — cannot hide behind "all the nulls matched".
     */
    private const MINIMUM_RESOLVED_INSTANTS = 1000;

    private const MINIMUM_DISTINCT_INSTANTS = 400;

    private WorkflowScheduleService $service;

    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => ScheduleGoldenMatrix::APP_TIMEZONE]);
        Carbon::setTestNow(Carbon::parse(ScheduleGoldenMatrix::FROZEN_NOW));

        $this->service = new WorkflowScheduleService;
        $this->fixture = $this->fixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * The fixture must exist, parse, and have been generated under the SAME environment and call
     * shape the test uses. A matrix generated under a different timezone or a different occurrence
     * count would compare apples to pears and fail in a way that reads like an engine regression.
     */
    private function fixture(): array
    {
        $this->assertFileExists(
            ScheduleGoldenMatrix::FIXTURE,
            'the golden matrix fixture is missing — it is committed, not generated by the suite',
        );

        $fixture = json_decode((string) file_get_contents(ScheduleGoldenMatrix::FIXTURE), true);

        $this->assertIsArray($fixture, 'the golden matrix fixture is not valid JSON');
        $this->assertSame(ScheduleGoldenMatrix::APP_TIMEZONE, $fixture['meta']['app_timezone']);
        $this->assertSame(ScheduleGoldenMatrix::FROZEN_NOW, $fixture['meta']['frozen_now']);
        $this->assertSame(ScheduleGoldenMatrix::OCCURRENCES_FROM_COUNT, $fixture['meta']['occurrences_from_count']);
        $this->assertSame(ScheduleGoldenMatrix::NEXT_OCCURRENCES_COUNT, $fixture['meta']['next_occurrences_count']);

        return $fixture;
    }

    /**
     * The matrix must cover what it claims to cover. Floors on the three dimensions, and an EXACT
     * key-set match against the fixture in BOTH directions — a descriptor deleted from the input
     * leaves an orphan key in the fixture, and a descriptor added without regenerating leaves a case
     * with no expectation. Either way this goes red instead of silently testing less.
     */
    public function test_the_matrix_covers_what_it_claims_to(): void
    {
        $descriptors = ScheduleGoldenMatrix::descriptors();
        $anchors = ScheduleGoldenMatrix::anchors();

        $this->assertGreaterThanOrEqual(self::MINIMUM_DESCRIPTORS, count($descriptors), 'descriptor coverage shrank');
        $this->assertGreaterThanOrEqual(self::MINIMUM_ANCHORS, count($anchors), 'anchor coverage shrank');

        $expectedKeys = [];

        foreach (array_keys($descriptors) as $name) {
            foreach (array_keys($anchors) as $anchorName) {
                $expectedKeys[] = ScheduleGoldenMatrix::caseKey($name, $anchorName);
            }
        }

        $this->assertGreaterThanOrEqual(self::MINIMUM_CASES, count($expectedKeys), 'the descriptor x anchor matrix shrank');

        $fixtureKeys = array_keys($this->fixture['cases']);

        sort($expectedKeys);
        sort($fixtureKeys);

        $this->assertSame(
            $expectedKeys,
            $fixtureKeys,
            'the matrix and the committed fixture disagree about which cases exist — regenerate the '
            . 'fixture ONLY if the inputs were meant to change (php tests/Support/Schedule/regenerate-golden-matrix.php)',
        );

        $this->assertSame(
            array_keys($descriptors),
            array_keys($this->fixture['per_descriptor']),
            'the per-descriptor section and the descriptor list disagree',
        );

        foreach ($this->fixture['cases'] as $key => $expected) {
            $this->assertSame(
                ['next_due_at', 'previous_or_at', 'occurrences_from', 'next_occurrences'],
                array_keys($expected),
                'case [' . $key . '] does not pin all four anchored methods',
            );
        }
    }

    /**
     * The fixture must contain real, varied instants — not a field of nulls that any broken engine
     * would satisfy. Asserted on the COMMITTED file, so it is a statement about the expectations
     * themselves rather than about whatever the engine did today.
     */
    public function test_the_committed_expectations_are_not_empty(): void
    {
        $instants = [];
        $nulls = 0;

        foreach ($this->fixture['cases'] as $expected) {
            foreach ([$expected['next_due_at'], $expected['previous_or_at']] as $value) {
                if ($value === null) {
                    $nulls++;
                } else {
                    $instants[] = $value;
                }
            }

            foreach ([...$expected['occurrences_from'], ...$expected['next_occurrences']] as $value) {
                $instants[] = $value;
            }
        }

        $this->assertGreaterThanOrEqual(self::MINIMUM_RESOLVED_INSTANTS, count($instants), 'the matrix pins too few real instants to be a gate');
        $this->assertGreaterThanOrEqual(self::MINIMUM_DISTINCT_INSTANTS, count(array_unique($instants)), 'the pinned instants are not varied enough to catch a shift');
        $this->assertGreaterThan(0, $nulls, 'the unreachable cadence must still be pinned as null');

        foreach ($instants as $instant) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $instant);
        }
    }

    /**
     * THE GATE. Every anchored method, every descriptor, every anchor, against the committed
     * instants. One assertion per method per case so the failure message names the row that moved.
     */
    public function test_every_anchored_answer_matches_the_committed_instant(): void
    {
        $compared = 0;

        foreach (ScheduleGoldenMatrix::cases($this->service) as $key => $actual) {
            $expected = $this->fixture['cases'][$key];

            $this->assertSame($expected['next_due_at'], $actual['next_due_at'], 'nextDueAt moved: ' . $key);
            $this->assertSame($expected['previous_or_at'], $actual['previous_or_at'], 'previousOrAtOccurrence moved: ' . $key);
            $this->assertSame($expected['occurrences_from'], $actual['occurrences_from'], 'occurrencesFrom moved: ' . $key);
            $this->assertSame($expected['next_occurrences'], $actual['next_occurrences'], 'nextOccurrences moved: ' . $key);

            $compared++;
        }

        $this->assertGreaterThanOrEqual(self::MINIMUM_CASES, $compared, 'fewer cases were compared than the matrix defines');
    }

    /**
     * The anchor-independent half: the read-shim's output, the compiled cadence, the resolved
     * timezone, the approximation flag, and the one projection that reads the clock.
     *
     * The compiled cron expressions are the most precise failure locator in the file — when a fire
     * instant moves, this is what says whether the GRAMMAR changed or the arithmetic around it did.
     */
    public function test_every_descriptor_compiles_and_resolves_as_committed(): void
    {
        $compared = 0;

        foreach (ScheduleGoldenMatrix::perDescriptor($this->service) as $name => $actual) {
            $expected = $this->fixture['per_descriptor'][$name];

            $this->assertSame($expected['upgraded'], $actual['upgraded'], 'the read-shim output changed: ' . $name);
            $this->assertSame($expected['compiled'], $actual['compiled'], 'the compiled cadence changed: ' . $name);
            $this->assertSame($expected['timezone'], $actual['timezone'], 'the resolved timezone changed: ' . $name);
            $this->assertSame($expected['is_approximate'], $actual['is_approximate'], 'isApproximate changed: ' . $name);
            $this->assertSame(
                $expected['next_occurrences_from_frozen_now'],
                $actual['next_occurrences_from_frozen_now'],
                'the projection from the frozen clock moved: ' . $name,
            );

            $compared++;
        }

        $this->assertGreaterThanOrEqual(self::MINIMUM_DESCRIPTORS, $compared, 'fewer descriptors were compared than the matrix defines');
    }

    /**
     * The stamps in the fixture are formatted in UTC, so a service that returned the right INSTANT in
     * the wrong zone would slip through them. Every returned object is checked directly instead —
     * storage-facing code (next_due_at) depends on this, not just the preview.
     */
    public function test_every_returned_instant_carries_the_utc_zone(): void
    {
        $anchors = array_slice(ScheduleGoldenMatrix::anchors(), 0, 3, preserve_keys: true);
        $checked = 0;

        foreach (ScheduleGoldenMatrix::descriptors() as $name => $descriptor) {
            foreach ($anchors as $anchorName => $anchor) {
                $from = CarbonImmutable::parse($anchor)->utc();
                $where = ScheduleGoldenMatrix::caseKey($name, $anchorName);

                $moments = [
                    $this->service->nextDueAt($descriptor, $from),
                    $this->service->previousOrAtOccurrence($descriptor, $from),
                    ...$this->service->occurrencesFrom($descriptor, $from, 2),
                    ...$this->service->nextOccurrences($descriptor, 2, $from),
                ];

                foreach (array_filter($moments) as $moment) {
                    $this->assertSame('UTC', $moment->timezone->getName(), 'a non-UTC instant escaped: ' . $where);
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(500, $checked, 'the timezone sweep stopped covering the matrix');
    }

    /**
     * Two edges too small to earn a matrix row, pinned here so the extraction cannot drop them:
     * a non-positive count short-circuits to an empty list without touching the cadence, and an
     * UNKNOWN legacy family is passed through the read-shim verbatim and then blows up in the
     * compiler's enum cast. That throw is the current contract at this seam — the shim deliberately
     * defers to v2 validation — and a "tidier" upgrader that silently defaulted the time axis
     * instead would turn a loud misconfiguration into a schedule firing at midnight.
     */
    public function test_the_small_edges_of_the_seam_are_pinned(): void
    {
        $daily = ['time' => ['mode' => 'at', 'at' => ['09:00']], 'tz' => 'UTC'];
        $anchor = CarbonImmutable::parse('2026-08-15T12:00:00Z');

        $this->assertSame([], $this->service->nextOccurrences($daily, 0, $anchor));
        $this->assertSame([], $this->service->nextOccurrences($daily, -3, $anchor));
        $this->assertSame([], $this->service->occurrencesFrom($daily, $anchor, 0));
        $this->assertSame([], $this->service->occurrencesFrom($daily, $anchor, -3));

        $unknown = ['family' => 'every_full_moon', 'params' => ['time' => '09:00']];

        $this->assertSame($unknown, (new LegacyScheduleUpgrader)->toV2($unknown), 'an unmappable family must pass through untouched');

        $this->expectException(ValueError::class);
        (new ScheduleCompiler)->compile($unknown);
    }
}
