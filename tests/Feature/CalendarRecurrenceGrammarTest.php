<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Calendar\DTOs\CalendarRecurrence;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Services\CalendarRecurrenceService;
use App\Modules\Workflows\Services\WorkflowScheduleRulesValidator;
use App\Modules\Workspaces\Models\Workspace;
use App\Support\Recurrence\Enums\RecurrenceViolationCode;
use App\Support\Recurrence\Enums\ScheduleDayMode;
use App\Support\Recurrence\Enums\ScheduleDaySpecial;
use App\Support\Recurrence\Enums\ScheduleLimits;
use App\Support\Recurrence\Enums\ScheduleMonthMode;
use App\Support\Recurrence\Enums\ScheduleTimeMode;
use App\Support\Recurrence\RecurrenceViolation;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R3 B4 — ONE GRAMMAR, TWO PROFILES, and the mechanism that keeps it one.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE SET-CONTAINMENT TEST IS THE POINT OF THIS FILE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * ADR-0052 D2 splits recurrence validation in half: the shared layer owns the SHAPE GRAMMAR and answers
 * in codes, while each module owns its per-key rules and its prose. That split has one failure mode,
 * and it is silent — a module writes its own rules, they drift from the shared grammar one line at a
 * time, and what was meant to be one grammar with two profiles becomes two grammars that happen to
 * agree today.
 *
 * {@see test_everything_the_calendar_accepts_also_passes_the_workflows_validator} is the guard against
 * exactly that. Every descriptor the Calendar will STORE is fed to the Workflows module's own
 * validator — the other profile of the same grammar, written independently, with its own Laravel rules
 * and its own bounds — and must pass unchanged. If the Calendar ever accepts something Workflows would
 * refuse, the two profiles have stopped being profiles.
 *
 * THE CORPUS IS COLLECTED FROM THE REAL WRITE PATH, not hand-written. A hand-written list of
 * "descriptors the Calendar accepts" is a second opinion about what the Calendar accepts, and it would
 * drift from the endpoint the same way the two validators could drift from each other. So each case is
 * POSTed, and what is judged is the descriptor that actually landed in the column.
 *
 * The containment is deliberately ONE-WAY. Workflows accepts strictly more (minute cadences, modulo
 * steps, the last working day), and that is the design: the Calendar's subset is narrow on purpose, and
 * widening it later is additive while narrowing it after release is not.
 */
class CalendarRecurrenceGrammarTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        // NAMED, never inherited: the suite reads the developer's .env, and half this file's subject is
        // which clock a rule is stamped on. A test that let the app default drift could not tell a
        // correct stamp from a lucky one.
        config(['app.timezone' => 'UTC']);

        $this->member = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->member->id]);
        $this->workspace->users()->attach($this->member->id);

        app(TenantContext::class)->set($this->workspace);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Every cadence the Calendar accepts, as the payload a client sends. Each anchor is a real
     * occurrence of its own rule — which is not decoration, it is the write path's hard requirement,
     * and a case that got it wrong would fail with a 422 rather than silently proving nothing.
     *
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function acceptedCadences(): array
    {
        $timed = ['all_day' => false, 'starts_at' => '2026-09-07T09:00:00Z'];

        return [
            'every day' => [
                ['day' => ['mode' => ScheduleDayMode::EVERY_DAY->value]],
                $timed,
            ],
            'named weekdays' => [
                ['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1, 3, 5]]],
                $timed,
            ],
            'named days of the month' => [
                ['day' => ['mode' => ScheduleDayMode::MONTH_DAYS->value, 'days' => [1, 15]]],
                ['all_day' => false, 'starts_at' => '2026-09-15T09:00:00Z'],
            ],
            'the last day of the month' => [
                ['day' => ['mode' => ScheduleDayMode::SPECIAL->value, 'special' => ScheduleDaySpecial::LAST_DAY->value]],
                ['all_day' => false, 'starts_at' => '2026-09-30T09:00:00Z'],
            ],
            'the nth weekday of the month' => [
                ['day' => [
                    'mode' => ScheduleDayMode::SPECIAL->value,
                    'special' => ScheduleDaySpecial::NTH_WEEKDAY->value,
                    'ordinal' => 2,
                    'weekday' => 2,
                ]],
                ['all_day' => false, 'starts_at' => '2026-09-08T09:00:00Z'],
            ],
            'the last such weekday of the month' => [
                ['day' => [
                    'mode' => ScheduleDayMode::SPECIAL->value,
                    'special' => ScheduleDaySpecial::LAST_WEEKDAY->value,
                    'weekday' => 5,
                ]],
                ['all_day' => false, 'starts_at' => '2026-09-25T09:00:00Z'],
            ],
            'named months' => [
                [
                    'day' => ['mode' => ScheduleDayMode::MONTH_DAYS->value, 'days' => [1]],
                    'month' => ['mode' => ScheduleMonthMode::MONTHS->value, 'months' => [3, 9]],
                ],
                ['all_day' => false, 'starts_at' => '2026-09-01T09:00:00Z'],
            ],
            'every month, said out loud' => [
                [
                    'day' => ['mode' => ScheduleDayMode::MONTH_DAYS->value, 'days' => [1]],
                    'month' => ['mode' => ScheduleMonthMode::EVERY_MONTH->value],
                ],
                ['all_day' => false, 'starts_at' => '2026-09-01T09:00:00Z'],
            ],
            'with excluded dates' => [
                [
                    'day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]],
                    'exclusions' => ['dates' => ['2026-09-14', '2026-09-21']],
                ],
                $timed,
            ],
            'with a stated end' => [
                [
                    'day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]],
                    'until' => '2026-12-31',
                ],
                $timed,
            ],
            'with a number of repeats' => [
                [
                    'day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]],
                    'count' => 8,
                ],
                $timed,
            ],
            'an all-day series' => [
                ['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]]],
                ['all_day' => true, 'start_date' => '2026-09-07'],
            ],

            // THE BOUNDS, not only the modes. A mode-only corpus proves each mode survives the round
            // trip and says nothing about whether the two profiles still agree on where a value STOPS —
            // which is precisely the per-key drift ADR-0052 D2 names as this split's failure mode. Every
            // number below is an EDGE read from ScheduleLimits, so the corpus cannot fall behind the
            // constants it is meant to be guarding.
            'every weekday, both ends of the range' => [
                ['day' => [
                    'mode' => ScheduleDayMode::WEEKDAYS->value,
                    'weekdays' => range(ScheduleLimits::WEEKDAY_MIN, ScheduleLimits::WEEKDAY_MAX),
                ]],
                $timed,
            ],
            'the last possible day of the month' => [
                ['day' => ['mode' => ScheduleDayMode::MONTH_DAYS->value, 'days' => [ScheduleLimits::MONTH_DAY_MAX]]],
                ['all_day' => false, 'starts_at' => '2026-08-31T09:00:00Z'],
            ],
            'the first possible day of the month' => [
                ['day' => ['mode' => ScheduleDayMode::MONTH_DAYS->value, 'days' => [ScheduleLimits::MONTH_DAY_MIN]]],
                ['all_day' => false, 'starts_at' => '2026-09-01T09:00:00Z'],
            ],
            'the lowest ordinal' => [
                ['day' => [
                    'mode' => ScheduleDayMode::SPECIAL->value,
                    'special' => ScheduleDaySpecial::NTH_WEEKDAY->value,
                    'ordinal' => ScheduleLimits::ORDINAL_MIN,
                    'weekday' => ScheduleLimits::WEEKDAY_MIN,
                ]],
                // The first Sunday of September 2026.
                ['all_day' => false, 'starts_at' => '2026-09-06T09:00:00Z'],
            ],
            'the highest ordinal' => [
                ['day' => [
                    'mode' => ScheduleDayMode::SPECIAL->value,
                    'special' => ScheduleDaySpecial::NTH_WEEKDAY->value,
                    'ordinal' => ScheduleLimits::ORDINAL_MAX,
                    'weekday' => ScheduleLimits::WEEKDAY_MAX,
                ]],
                // The fifth Saturday of August 2026.
                ['all_day' => false, 'starts_at' => '2026-08-29T09:00:00Z'],
            ],
            'every month, both ends of the range' => [
                [
                    'day' => ['mode' => ScheduleDayMode::MONTH_DAYS->value, 'days' => [1]],
                    'month' => [
                        'mode' => ScheduleMonthMode::MONTHS->value,
                        'months' => range(ScheduleLimits::MONTH_MIN, ScheduleLimits::MONTH_MAX),
                    ],
                ],
                ['all_day' => false, 'starts_at' => '2026-09-01T09:00:00Z'],
            ],
            'a full exclusion list' => [
                [
                    'day' => ['mode' => ScheduleDayMode::EVERY_DAY->value],
                    'exclusions' => ['dates' => self::consecutiveDaysFrom('2026-10-01', ScheduleLimits::EXCLUSIONS_DATES_MAX)],
                ],
                $timed,
            ],
            'the largest number of repeats' => [
                ['day' => ['mode' => ScheduleDayMode::EVERY_DAY->value], 'count' => 366],
                $timed,
            ],
        ];
    }

    /**
     * $count consecutive `Y-m-d` days from $start — for the exclusion-list edge, built rather than
     * spelled so the list follows the shared cap instead of a literal somebody has to remember to bump.
     *
     * @return array<int, string>
     */
    private static function consecutiveDaysFrom(string $start, int $count): array
    {
        $day = new \DateTimeImmutable($start);

        return array_map(
            static fn (int $offset): string => $day->modify('+' . $offset . ' days')->format('Y-m-d'),
            range(0, $count - 1),
        );
    }

    /**
     * SET CONTAINMENT: everything the Calendar stores is something the Workflows profile of the same
     * grammar also accepts. See the class docblock for why this is the file's headline.
     */
    public function test_everything_the_calendar_accepts_also_passes_the_workflows_validator(): void
    {
        $workflows = app(WorkflowScheduleRulesValidator::class);
        $judged = 0;

        foreach (self::acceptedCadences() as $name => [$recurrence, $shape]) {
            $event = $this->create($recurrence, $shape, $name);

            $this->assertNotNull($event->recurrence, "[{$name}] stored no rule");

            $this->assertSame(
                [],
                $workflows->validate($event->recurrence),
                "[{$name}] the Calendar stored a descriptor the Workflows profile refuses. The two are "
                . 'meant to be profiles of ONE grammar; if they can disagree, they are two grammars.'
            );

            $judged++;
        }

        // ANTI-VACUITY, both halves. An empty corpus passes forever, and a validator that accepts
        // everything proves nothing about the corpus.
        $this->assertGreaterThanOrEqual(19, $judged, 'the containment corpus collapsed — it is proving nothing');
        $this->assertNotSame(
            [],
            $workflows->validate(['time' => ['mode' => ScheduleTimeMode::AT->value]]),
            'the Workflows validator accepted a descriptor with no fire times — it is not actually judging anything'
        );
    }

    /**
     * The corpus really does exercise every mode the Calendar claims to accept. Without this, dropping
     * a case from the array above would quietly shrink what containment means.
     */
    public function test_the_corpus_covers_every_mode_in_the_calendars_subset(): void
    {
        $modes = [];
        $specials = [];
        $monthModes = [];

        foreach (self::acceptedCadences() as [$recurrence]) {
            $modes[] = $recurrence['day']['mode'] ?? null;
            $specials[] = $recurrence['day']['special'] ?? null;
            $monthModes[] = $recurrence['month']['mode'] ?? null;
        }

        foreach (CalendarRecurrence::dayModes() as $mode) {
            $this->assertContains($mode, $modes, "no containment case exercises the [{$mode}] day mode");
        }

        foreach (CalendarRecurrence::daySpecials() as $special) {
            $this->assertContains($special, $specials, "no containment case exercises the [{$special}] special rule");
        }

        foreach (CalendarRecurrence::monthModes() as $mode) {
            $this->assertContains($mode, $monthModes, "no containment case exercises the [{$mode}] month mode");
        }
    }

    /**
     * The corpus reaches the EDGE of every per-key bound, not merely a comfortable value inside it.
     *
     * The bounds are the half of validation ADR-0052 D2 leaves to each module, stated once in
     * `ScheduleLimits` and spelled separately in each profile's own rule vocabulary. That is exactly
     * where two profiles drift, and a corpus of mid-range values would never notice. Every expectation
     * below is READ from the shared constants, so widening a limit without widening the corpus fails
     * here rather than silently narrowing what containment means.
     */
    public function test_the_corpus_reaches_the_edge_of_every_bound(): void
    {
        $weekdays = [];
        $monthDays = [];
        $months = [];
        $ordinals = [];
        $exclusionCounts = [];

        foreach (self::acceptedCadences() as [$recurrence]) {
            $weekdays = [...$weekdays, ...($recurrence['day']['weekdays'] ?? [])];
            $monthDays = [...$monthDays, ...($recurrence['day']['days'] ?? [])];
            $months = [...$months, ...($recurrence['month']['months'] ?? [])];
            $ordinals[] = $recurrence['day']['ordinal'] ?? null;
            $exclusionCounts[] = count($recurrence['exclusions']['dates'] ?? []);
        }

        foreach ([
            'weekday' => [$weekdays, ScheduleLimits::WEEKDAY_MIN, ScheduleLimits::WEEKDAY_MAX],
            'month day' => [$monthDays, ScheduleLimits::MONTH_DAY_MIN, ScheduleLimits::MONTH_DAY_MAX],
            'month' => [$months, ScheduleLimits::MONTH_MIN, ScheduleLimits::MONTH_MAX],
            'ordinal' => [$ordinals, ScheduleLimits::ORDINAL_MIN, ScheduleLimits::ORDINAL_MAX],
        ] as $name => [$seen, $min, $max]) {
            $this->assertContains($min, $seen, "no containment case reaches the lowest {$name}");
            $this->assertContains($max, $seen, "no containment case reaches the highest {$name}");
        }

        $this->assertContains(
            ScheduleLimits::EXCLUSIONS_DATES_MAX,
            $exclusionCounts,
            'no containment case fills the exclusion list to its cap'
        );
    }

    /**
     * THE RENDERER IS EXHAUSTIVE. The shared layer answers in codes and refuses to write sentences, so
     * a code this module never renders would reach a user as a blank message beside a control that
     * simply will not save. The `match` has no default arm; this is what turns that into a red suite
     * the moment a code is added rather than a silent hole later.
     */
    public function test_every_violation_code_renders_a_calendar_sentence(): void
    {
        $service = app(CalendarRecurrenceService::class);

        foreach (RecurrenceViolationCode::cases() as $code) {
            $message = $service->message(new RecurrenceViolation('day.mode', $code, ['field' => 'weekdays', 'special' => 'last_day']));

            $this->assertNotSame('', trim($message), "[{$code->value}] renders nothing");
            $this->assertStringNotContainsString(
                'calendar.validation',
                $message,
                "[{$code->value}] rendered a raw translation key — the lang entry is missing"
            );
        }
    }

    // ---- the narrow subset, refused rather than quietly widened ------------------

    /**
     * The three cadences the Calendar refuses, each with its own argument (see CalendarRecurrence):
     * a modulo step means something other than what it says, and the last working day is outside an
     * annotation's vocabulary.
     */
    public function test_it_refuses_the_cadences_outside_its_subset(): void
    {
        foreach ([
            'recurrence.day.mode' => ['day' => ['mode' => ScheduleDayMode::EVERY_N_DAYS->value, 'n' => 3]],
            'recurrence.month.mode' => ['month' => ['mode' => ScheduleMonthMode::EVERY_N_MONTHS->value, 'n' => 2]],
            'recurrence.day.special' => ['day' => [
                'mode' => ScheduleDayMode::SPECIAL->value,
                'special' => ScheduleDaySpecial::LAST_WORKING_DAY->value,
            ]],
        ] as $key => $recurrence) {
            $this->submit($recurrence)->assertStatus(422)->assertJsonValidationErrors($key);
        }
    }

    /**
     * FACT 3 of ADR-0052 D2, at its limit. The shared layer's caps (11 of 12 months, 6 of 7 weekdays)
     * stop an exclusion set ruling out a whole dimension; the Calendar does not accept either list at
     * all, which is the same guarantee with nothing left to cap. Both are expressible as the
     * complementary set on their own axis, so nothing is lost.
     */
    public function test_it_refuses_the_exclusion_lists_that_could_rule_out_a_dimension(): void
    {
        $this->submit(['exclusions' => ['months' => [1]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recurrence.exclusions.months');

        $this->submit(['exclusions' => ['weekdays' => [0]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recurrence.exclusions.weekdays');
    }

    /**
     * FACT 1 and FACT 2, from the wire side: the two keys a caller may not send. The hour is the
     * event's own and the zone is the workspace's, so a caller that could set either could set one that
     * disagrees with the row it is attached to.
     */
    public function test_it_refuses_a_caller_supplied_hour_or_timezone(): void
    {
        $this->submit(['time' => ['mode' => 'at', 'at' => ['07:00']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recurrence.time');

        $this->submit(['tz' => 'Europe/Warsaw'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recurrence.tz');
    }

    /**
     * FACT 2 from the other side: the STAMPED zone is always a real one, even when the workspace's own
     * column is not. The resolver already degrades to the app timezone with a warning; this pins that a
     * series can never carry the garbage through to the engine — where an unknown zone is not refused
     * but silently swapped, which is how a series comes to fire in the wrong one.
     */
    public function test_a_series_is_never_stamped_with_an_unusable_timezone(): void
    {
        $this->workspace->forceFill(['timezone' => 'Middle/Earth'])->save();
        app(TenantContext::class)->set($this->workspace->fresh());

        $event = $this->create(['day' => ['mode' => ScheduleDayMode::EVERY_DAY->value]]);

        $this->assertContains(
            $event->recurrence['tz'],
            timezone_identifiers_list(),
            'a series was stamped with a timezone the engine cannot resolve'
        );
        $this->assertSame('UTC', $event->recurrence['tz']);
    }

    /**
     * THE MISLEADING ERROR ADR-0052 §D2 WARNS ABOUT, pinned so it cannot come back.
     *
     * A consumer that leans on the shared layer alone stays fail-closed, but reports a missing or
     * unparseable START as "this rule has no occurrences", pointed at `exclusions`. Here the start is
     * garbage and the rule is fine: the error must be about the START, and the recurrence must not be
     * judged at all.
     */
    public function test_a_broken_start_is_reported_as_a_broken_start_not_as_an_empty_rule(): void
    {
        $response = $this->actingAsMember()
            ->postJson('/api/calendar/events', [
                'title' => 'Standup',
                'all_day' => false,
                'starts_at' => 'not-a-time',
                'recurrence' => ['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('starts_at');

        foreach (array_keys($response->json('errors')) as $key) {
            $this->assertStringStartsNotWith(
                'recurrence',
                $key,
                'the rule was judged against a start that never parsed — that is the misleading error ADR-0052 D2 names'
            );
        }
    }

    // ---- fixtures ---------------------------------------------------------------

    private function actingAsMember(): self
    {
        parent::actingAs($this->member)->withHeader('X-Workspace-Id', $this->workspace->id);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $recurrence
     * @param  array<string, mixed>  $shape
     */
    private function submit(array $recurrence, array $shape = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsMember()->postJson('/api/calendar/events', [
            'title' => 'Standup',
            ...($shape === [] ? ['all_day' => false, 'starts_at' => '2026-09-07T09:00:00Z'] : $shape),
            'recurrence' => $recurrence,
        ]);
    }

    /**
     * @param  array<string, mixed>  $recurrence
     * @param  array<string, mixed>  $shape
     */
    private function create(array $recurrence, array $shape = [], string $label = ''): CalendarEvent
    {
        $response = $this->submit($recurrence, $shape);

        $response->assertCreated();

        return CalendarEvent::query()->findOrFail($response->json('data.id'));
    }
}
