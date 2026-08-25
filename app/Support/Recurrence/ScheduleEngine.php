<?php

namespace App\Support\Recurrence;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Throwable;

/**
 * The SINGLE source of truth for when a recurrence descriptor fires. Given the validated v2
 * `{ time, day?, month?, tz?, exclusions? }` block, it answers the next fire instant, the previous
 * one, and the projections built from them — and NOTHING else. It is a PURE function of
 * (descriptor, anchor): no models, no tenancy, no database, no clock except the anchor it is handed.
 *
 * Placement mirrors the metering actor resolver and the AI fenced-block helper: a shared support layer,
 * not a module. Workflows drives it for a schedule trigger's `next_due_at`; the Calendar needs the same
 * cadence arithmetic for recurring events, and the two modules may not name each other (pinned by
 * CalendarModuleBoundaryTest), so the engine they share can only live below both. The Workflows-facing
 * half — arming a model, the compare-and-swap slot claim — stays in the module, on its schedule-service
 * facade, which delegates every computation down here.
 *
 * NOT ONE FULLY-QUALIFIED NAME FROM OUTSIDE THIS LAYER APPEARS ANYWHERE IN THIS DIRECTORY, and that is
 * deliberate rather than sloppy. RecurrenceLayerBoundaryTest forbids a foreign namespaced name here even
 * inside a comment, and it carries NO exemption list — a list of length zero cannot grow, whereas a list
 * of length four teaches that adding a fifth entry is a normal move. A docblock reference is how a real
 * import starts; prose tells a reader exactly as much and gives the guard nothing to forgive. So: name
 * the collaborators, do not spell their namespaces.
 *
 * The { time, day, month } -> cron/last-working-day translation lives ENTIRELY in
 * ScheduleCompiler (the one cadence grammar); this engine only advances a clock from a
 * CompiledSchedule and applies the optional EXCLUSIONS post-filter. A legacy `{ family, params }`
 * block read from storage is upgraded to v2 (LegacyScheduleUpgrader) at every entry, so old rows keep
 * firing with no data migration.
 *
 * TWO COMPILED KINDS (no interval kind — every minute/hour cadence is a WALL-CLOCK cron grid now):
 *   - CRON: one or more cron expressions; the next fire is the EARLIEST strictly-after candidate
 *     across the whole list (the union of all fire moments).
 *   - LAST_WORKING_DAY: the last Mon-Fri of an ALLOWED month (the month axis) at one of the time.at
 *     HH:mm times — a bespoke cadence (the `LW` cron token is broken; see CompiledSchedule).
 *
 * EXCLUSIONS (optional): a `{ months?, weekdays?, dates? }` filter evaluated in the SCHEDULE tz. A
 * candidate is DROPPED when its month ∈ months OR its weekday ∈ weekdays OR its date (Y-m-d) ∈ dates.
 * The filter loops — compute the union candidate, skip past it if excluded, recompute. HARD LIMITS
 * bound the loop: at most MAX_ITERATIONS steps AND a HORIZON_YEARS window; exceeding either returns
 * null (a schedule with no reachable occurrence — e.g. weekly-Monday excluding Mondays).
 *
 * CONVENTIONS:
 *   - weekday: 0=Sunday .. 6=Saturday — matches Carbon::dayOfWeek AND cron's day-of-week 0=Sun.
 *   - tz: the schedule's own timezone; defaults to config('app.timezone') (UTC). Wall-clock fields
 *     are resolved IN this tz by the cron lib, then converted to UTC for storage.
 *   - Every returned time is STRICTLY AFTER $from (never equal) and stored in UTC — EXCEPT the
 *     prev-or-at anchor helpers below, which deliberately return an occurrence ≤ the anchor.
 *
 * DST behaviour (documented, covered by unit tests around Europe/Warsaw's spring-forward AND
 * fall-back):
 *   SPRING-FORWARD gap (2026-03-29, 02:00->03:00): a daily 02:30 does not exist; the cron lib
 *     resolves it forward past the gap (03:30 local = 01:30 UTC) that day, then returns to 02:30.
 *   FALL-BACK overlap (2026-10-25, 03:00->02:00): a daily 02:30 fires TWICE at two DISTINCT UTC
 *     instants (00:30 UTC then 01:30 UTC); the strictly-after invariant advances from the first to the
 *     second, so the same UTC moment is never fired twice. Pinned, not changed.
 */
class ScheduleEngine
{
    /**
     * The exclusion loop's hard stops (defence against an unreachable schedule). MAX_ITERATIONS
     * bounds the step count; HORIZON_YEARS bounds the search window from the anchor. Hitting either
     * returns null — no occurrence within a decade / a thousand candidates is treated as none.
     */
    private const MAX_ITERATIONS = 1000;

    private const HORIZON_YEARS = 10;

    /**
     * The month-walk bound for the bespoke last-working-day cadence: the next (or previous) fire is
     * at most a handful of months away even when the month axis allows a single month a year, so two
     * years of steps is a safe, cheap ceiling before giving up on a single candidate resolution.
     */
    private const LAST_WORKING_DAY_MONTH_STEPS = 24;

    /**
     * MEASURED, NOT ASSUMED: descriptor compilation is NOT this engine's cost, so it is deliberately
     * NOT cached.
     *
     * A memoization of `toV2()` + `compile()` keyed on the descriptor was built, tested for parity and
     * then REMOVED, because the profile said it could not pay. Per projection on the dev machine:
     * `ScheduleCompiler::compile()` 0.0053 ms and `LegacyScheduleUpgrader::toV2()` 0.0001 ms,
     * against ~0.29 ms for the whole projection — under 2%, and the cache's own `serialize()` key ate
     * part of even that. End to end it measured SLOWER, not faster.
     *
     * The cost is the date arithmetic underneath (the cron library's run-date search and the Carbon
     * timezone conversions around it), which happens per projection whatever is cached above it. Any
     * future attempt belongs there — and this file is the sweep command's hot path, so it needs a
     * measurement first and WorkflowScheduleSweepDeterminismTest green after.
     *
     * The way the calendar's cost was actually brought down was by projecting FEWER schedules
     * (WorkflowScheduleCalendarSource), not by making each projection cheaper.
     */
    public function __construct(
        private ScheduleCompiler $compiler = new ScheduleCompiler,
        private LegacyScheduleUpgrader $upgrader = new LegacyScheduleUpgrader,
    ) {}

    /**
     * The next fire instant STRICTLY AFTER $from, computed in the schedule's tz and returned in UTC
     * for storage — or null when the cadence has NO reachable occurrence within the hard limits (an
     * over-constrained exclusion set). Callers must treat null as "leave next_due_at NULL, do not arm".
     *
     * @param  array<string, mixed>  $schedule  the validated trigger_config.schedule block
     */
    public function nextDueAt(array $schedule, CarbonInterface $from): ?Carbon
    {
        $schedule = $this->upgrader->toV2($schedule);
        $compiled = $this->compiler->compile($schedule);
        $tz = $this->timezone($schedule);
        $exclusions = $this->normalizeExclusions($schedule['exclusions'] ?? null);

        $start = Carbon::instance($from->toImmutable())->setTimezone('UTC');
        $cursor = $start;
        // Boundary instant, NOT a diff: Carbon 3's diffInYears($start) is SIGNED (negative for a
        // later candidate), which silently killed this guard when written as a >= comparison.
        $horizon = $start->copy()->addYears(self::HORIZON_YEARS);

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $candidate = $this->nextCandidate($compiled, $cursor, $tz);

            if ($candidate === null || $candidate->greaterThan($horizon)) {
                return null;
            }

            if (!$this->isExcluded($candidate, $exclusions, $tz)) {
                return $candidate;
            }

            // Excluded: advance the cursor PAST this candidate and keep searching (strictly-after
            // guarantees the next candidate is a later instant, so the loop makes progress).
            $cursor = $candidate;
        }

        return null;
    }

    /**
     * The next $count fire instants strictly after $from (default now()), ASCENDING and UTC — the same
     * cadence the write/sweep paths use, walked forward. Each occurrence anchors the search for the
     * next. The list is SHORT (≤ $count) and may be EMPTY when the cadence has no reachable occurrence.
     *
     * A PURE function: it never touches tenancy, models or the database. This is the seam the
     * schedule-preview endpoint renders and the emptiness guard reuses ($count = 1).
     *
     * @param  array<string, mixed>  $schedule  the validated trigger_config.schedule block
     * @return array<int, Carbon> ascending UTC instants, at most $count
     */
    public function nextOccurrences(array $schedule, int $count, ?CarbonImmutable $from = null): array
    {
        $count = max(0, $count);
        $cursor = $from ?? CarbonImmutable::now('UTC');

        $occurrences = [];

        for ($i = 0; $i < $count; $i++) {
            $next = $this->nextDueAt($schedule, $cursor);

            if ($next === null) {
                break;
            }

            $occurrences[] = $next;
            $cursor = $next;
        }

        return $occurrences;
    }

    /**
     * ANCHORED preview seam: the $count fire instants surrounding an $anchor — the occurrence AT or
     * BEFORE the anchor first (when one exists), then the strictly-later ones. Lets the FE render a
     * preview centred on a chosen instant (e.g. "what fires around next Monday") rather than only the
     * future from now. When no prior occurrence exists (the anchor precedes the first fire), the list
     * is simply the occurrences strictly after the anchor. Ascending, UTC, exclusions respected.
     *
     * @param  array<string, mixed>  $schedule
     * @return array<int, Carbon>
     */
    public function occurrencesFrom(array $schedule, CarbonInterface $anchor, int $count): array
    {
        $count = max(0, $count);

        if ($count === 0) {
            return [];
        }

        $previous = $this->previousOrAtOccurrence($schedule, $anchor);

        $occurrences = [];
        $cursor = $anchor->toImmutable();

        if ($previous !== null) {
            $occurrences[] = Carbon::instance($previous)->setTimezone('UTC');
            $cursor = $previous;
        }

        // Each next is strictly after $cursor; when $cursor is the prev-or-at occurrence the first
        // "next" is the earliest occurrence strictly after the anchor, so the union stays ascending.
        while (count($occurrences) < $count) {
            $next = $this->nextDueAt($schedule, $cursor);

            if ($next === null) {
                break;
            }

            $occurrences[] = $next;
            $cursor = $next;
        }

        return $occurrences;
    }

    /**
     * The greatest occurrence AT OR BEFORE $anchor (respecting exclusions), or null when none exists
     * within the horizon (the anchor precedes the first fire, or the cadence is over-constrained).
     * The mirror of nextDueAt: for a cron cadence it is the LATEST getPreviousRunDate across the
     * expressions (allowCurrentDate, so an occurrence exactly on the anchor counts); an excluded
     * candidate is stepped strictly backwards until a non-excluded one is found or the backward
     * horizon is crossed. Returned in UTC.
     *
     * @param  array<string, mixed>  $schedule
     */
    public function previousOrAtOccurrence(array $schedule, CarbonInterface $anchor): ?CarbonImmutable
    {
        $schedule = $this->upgrader->toV2($schedule);
        $compiled = $this->compiler->compile($schedule);
        $tz = $this->timezone($schedule);
        $exclusions = $this->normalizeExclusions($schedule['exclusions'] ?? null);

        $anchorUtc = Carbon::instance($anchor->toImmutable())->setTimezone('UTC');
        $floor = $anchorUtc->copy()->subYears(self::HORIZON_YEARS);

        // First candidate: the greatest occurrence <= anchor (allowCurrentDate = true).
        $candidate = $this->previousCandidate($compiled, $anchorUtc, $tz, allowCurrent: true);

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            if ($candidate === null || $candidate->lessThan($floor)) {
                return null;
            }

            if (!$this->isExcluded($candidate, $exclusions, $tz)) {
                return $candidate->toImmutable();
            }

            // Excluded: step STRICTLY BEFORE this candidate and keep searching backwards.
            $candidate = $this->previousCandidate($compiled, $candidate, $tz, allowCurrent: false);
        }

        return null;
    }

    /**
     * Whether a preview of this schedule is only APPROXIMATE. Always FALSE now: every cadence is a
     * calendar-anchored wall-clock grid (there is no phase-from-activation interval), so a preview is
     * always exact. Kept (and always false) so the preview response shape is stable for the FE.
     *
     * @param  array<string, mixed>  $schedule  the validated trigger_config.schedule block
     */
    public function isApproximate(array $schedule): bool
    {
        return false;
    }

    /**
     * The earliest strictly-after candidate across the compiled cadence (the UNION of all fire
     * times / expressions), BEFORE the exclusion filter. Null only when a cron list is empty
     * (defensive — the compiler always yields at least one).
     */
    private function nextCandidate(CompiledSchedule $compiled, Carbon $cursor, string $tz): ?Carbon
    {
        if ($compiled->isLastWorkingDay()) {
            return $this->nextLastWorkingDay($cursor, $compiled->times ?? [], $compiled->months ?? [], $tz);
        }

        return $this->earliest(array_map(
            fn (string $expression) => $this->nextCron($expression, $cursor, $tz),
            $compiled->expressions ?? [],
        ));
    }

    /**
     * The greatest candidate at-or-before $cursor across the compiled cadence. For cron it is the
     * LATEST getPreviousRunDate across the expressions ($allowCurrent includes an exact-match instant);
     * for last-working-day it walks back over months.
     */
    private function previousCandidate(CompiledSchedule $compiled, Carbon $cursor, string $tz, bool $allowCurrent): ?Carbon
    {
        if ($compiled->isLastWorkingDay()) {
            return $this->previousLastWorkingDay($cursor, $compiled->times ?? [], $compiled->months ?? [], $tz, $allowCurrent);
        }

        return $this->latest(array_map(
            fn (string $expression) => $this->previousCron($expression, $cursor, $tz, $allowCurrent),
            $compiled->expressions ?? [],
        ));
    }

    /**
     * The earliest non-null Carbon in a list (the forward union across fire times), or null when the
     * list is empty / all null.
     *
     * @param  array<int, Carbon|null>  $candidates
     */
    private function earliest(array $candidates): ?Carbon
    {
        $earliest = null;

        foreach ($candidates as $candidate) {
            if ($candidate !== null && ($earliest === null || $candidate->lessThan($earliest))) {
                $earliest = $candidate;
            }
        }

        return $earliest;
    }

    /**
     * The latest non-null Carbon in a list (the backward union across fire times), or null.
     *
     * @param  array<int, Carbon|null>  $candidates
     */
    private function latest(array $candidates): ?Carbon
    {
        $latest = null;

        foreach ($candidates as $candidate) {
            if ($candidate !== null && ($latest === null || $candidate->greaterThan($latest))) {
                $latest = $candidate;
            }
        }

        return $latest;
    }

    /**
     * A cron cadence: let the cron lib compute the next run date IN the schedule tz with
     * allowCurrentDate=false (strictly after $from), then convert to UTC for storage. Passing the tz
     * string makes the lib resolve wall-clock fields (HH:mm, weekday, day-of-month) as LOCAL times.
     */
    private function nextCron(string $expression, CarbonInterface $from, string $tz): Carbon
    {
        $utcFrom = Carbon::instance($from->toImmutable())->setTimezone('UTC');

        $next = (new CronExpression($expression))->getNextRunDate($utcFrom, 0, false, $tz);

        return Carbon::instance($next)->setTimezone('UTC');
    }

    /**
     * A cron cadence, walked BACKWARDS: the previous run date at-or-before (allowCurrent) or strictly
     * before (!allowCurrent) $from, resolved in the tz and returned in UTC. Null when the lib finds no
     * prior run (defensive — treated as "no earlier occurrence").
     */
    private function previousCron(string $expression, CarbonInterface $from, string $tz, bool $allowCurrent): ?Carbon
    {
        $utcFrom = Carbon::instance($from->toImmutable())->setTimezone('UTC');

        try {
            $previous = (new CronExpression($expression))->getPreviousRunDate($utcFrom, 0, $allowCurrent, $tz);
        } catch (Throwable) {
            return null;
        }

        return Carbon::instance($previous)->setTimezone('UTC');
    }

    /**
     * last_working_day: the earliest "last Mon-Fri of an ALLOWED month at HH:mm" STRICTLY AFTER $from,
     * resolved in the tz and returned in UTC. Walks forward month by month; a month the month axis
     * disallows is skipped, and a month whose last-working-day fire times have all passed relative to
     * $from is skipped too. Null when no occurrence is found within the month-walk bound.
     *
     * @param  array<int, array{hour: int, minute: int}>  $times
     * @param  array<int, int>  $months
     */
    private function nextLastWorkingDay(CarbonInterface $from, array $times, array $months, string $tz): ?Carbon
    {
        if ($times === [] || $months === []) {
            return null;
        }

        $localFrom = Carbon::instance($from->toImmutable())->setTimezone($tz);
        $cursorMonth = $localFrom->copy()->startOfMonth();

        for ($i = 0; $i < self::LAST_WORKING_DAY_MONTH_STEPS; $i++) {
            if (in_array((int) $cursorMonth->month, $months, true)) {
                $candidate = $this->lastWorkingDayInMonth($cursorMonth, $times, $localFrom, forward: true);

                if ($candidate !== null) {
                    return $candidate->setTimezone('UTC');
                }
            }

            $cursorMonth = $cursorMonth->addMonthNoOverflow()->startOfMonth();
        }

        return null;
    }

    /**
     * last_working_day, walked BACKWARDS: the latest "last Mon-Fri of an allowed month at HH:mm" that
     * is at-or-before (allowCurrent) or strictly before (!allowCurrent) $from. Steps back month by
     * month over disallowed / already-passed months.
     *
     * @param  array<int, array{hour: int, minute: int}>  $times
     * @param  array<int, int>  $months
     */
    private function previousLastWorkingDay(CarbonInterface $from, array $times, array $months, string $tz, bool $allowCurrent): ?Carbon
    {
        if ($times === [] || $months === []) {
            return null;
        }

        $localFrom = Carbon::instance($from->toImmutable())->setTimezone($tz);
        $cursorMonth = $localFrom->copy()->startOfMonth();

        for ($i = 0; $i < self::LAST_WORKING_DAY_MONTH_STEPS; $i++) {
            if (in_array((int) $cursorMonth->month, $months, true)) {
                $candidate = $this->lastWorkingDayInMonth($cursorMonth, $times, $localFrom, forward: false, allowCurrent: $allowCurrent);

                if ($candidate !== null) {
                    return $candidate->setTimezone('UTC');
                }
            }

            $cursorMonth = $cursorMonth->subMonthNoOverflow()->startOfMonth();
        }

        return null;
    }

    /**
     * The best last-working-day fire instant in $cursorMonth relative to $localFrom: FORWARD picks the
     * EARLIEST time strictly after $localFrom; backward picks the LATEST time before (or at, when
     * $allowCurrent) $localFrom. Null when no time in this month satisfies the bound.
     *
     * @param  array<int, array{hour: int, minute: int}>  $times
     */
    private function lastWorkingDayInMonth(CarbonInterface $cursorMonth, array $times, CarbonInterface $localFrom, bool $forward, bool $allowCurrent = false): ?Carbon
    {
        $best = null;

        foreach ($times as $time) {
            $candidate = $this->lastWorkingDayOfMonth($cursorMonth, $time['hour'], $time['minute']);

            if ($forward) {
                $satisfies = $candidate->greaterThan($localFrom);
                $better = $best === null || $candidate->lessThan($best);
            } else {
                $satisfies = $allowCurrent ? $candidate->lessThanOrEqualTo($localFrom) : $candidate->lessThan($localFrom);
                $better = $best === null || $candidate->greaterThan($best);
            }

            if ($satisfies && $better) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * The last working day (Mon-Fri) of $reference's month at $hour:$minute, as a Carbon in
     * $reference's timezone. Starts at the last calendar day and steps backwards over Sat/Sun.
     * Public holidays are ignored (last calendar weekday only).
     */
    private function lastWorkingDayOfMonth(CarbonInterface $reference, int $hour, int $minute): Carbon
    {
        $day = Carbon::instance($reference->toImmutable())->endOfMonth()->setTime($hour, $minute, 0);

        while ($day->isWeekend()) {
            $day = $day->subDay();
        }

        return $day;
    }

    /**
     * Normalize the optional exclusions block into a predictable shape ({ months, weekdays, dates },
     * each a list; missing/blank -> empty). Treated leniently here so a stale/partial record never
     * crashes the sweep — the write validator is what rejects an all-empty block as a mistake.
     *
     * @return array{months: array<int, int>, weekdays: array<int, int>, dates: array<int, string>}
     */
    private function normalizeExclusions(mixed $exclusions): array
    {
        $exclusions = is_array($exclusions) ? $exclusions : [];

        return [
            'months' => $this->intList($exclusions['months'] ?? null),
            'weekdays' => $this->intList($exclusions['weekdays'] ?? null),
            'dates' => $this->stringList($exclusions['dates'] ?? null),
        ];
    }

    /**
     * Whether a candidate is excluded: its month ∈ months, OR its weekday ∈ weekdays, OR its date
     * (Y-m-d) ∈ dates — all evaluated against the candidate RE-EXPRESSED in the schedule tz. A no-op
     * when every exclusion list is empty.
     *
     * @param  array{months: array<int, int>, weekdays: array<int, int>, dates: array<int, string>}  $exclusions
     */
    private function isExcluded(Carbon $candidate, array $exclusions, string $tz): bool
    {
        if ($exclusions['months'] === [] && $exclusions['weekdays'] === [] && $exclusions['dates'] === []) {
            return false;
        }

        $local = $candidate->copy()->setTimezone($tz);

        return in_array($local->month, $exclusions['months'], true)
            || in_array($local->dayOfWeek, $exclusions['weekdays'], true)
            || in_array($local->format('Y-m-d'), $exclusions['dates'], true);
    }

    /**
     * Coerce a value to a flat list of ints (dropping non-numerics).
     *
     * @return array<int, int>
     */
    private function intList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map('intval', array_filter($value, 'is_numeric')));
    }

    /**
     * Coerce a value to a flat list of strings (dropping non-strings).
     *
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * The schedule's timezone, defaulting to the app timezone (UTC). Public so the schedule-preview
     * request can fold a wall-clock `anchor` (an ISO-8601 string without an offset) into the same tz.
     */
    public function timezone(array $schedule): string
    {
        $tz = $schedule['tz'] ?? null;

        return is_string($tz) && $tz !== '' ? $tz : (string) config('app.timezone', 'UTC');
    }
}
