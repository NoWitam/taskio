<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\Enums\WorkflowStatus;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cron\CronExpression;

/**
 * The SINGLE source of truth for a schedule workflow's next fire time. Given the validated
 * `trigger_config.schedule` cadence, it computes the next `next_due_at` and arms the model.
 * Both the write path (create/update/status via WorkflowService) and the due-sweep command
 * go through here, so the cadence is interpreted identically everywhere.
 *
 * CADENCE SHAPE (already validated by StoreWorkflowRequest::scheduleRules):
 *   { family: WorkflowScheduleFamily, params: {…per family}, tz?, times?[], exclusions? }
 *
 * The family->interval/cron/last-working-day translation lives ENTIRELY in WorkflowScheduleCompiler
 * (the one cadence grammar); this service only decides how to advance a clock from a CompiledSchedule
 * and applies the optional EXCLUSIONS post-filter.
 *
 * MULTIPLE FIRE TIMES: the compiler expands `times[]` into a LIST (of cron expressions, or of
 * last-working-day HH:mm pairs). The next fire is the EARLIEST strictly-after candidate across the
 * whole list — the union of the per-time occurrences.
 *
 * EXCLUSIONS (optional): a `{ months?, weekdays?, dates? }` filter evaluated in the SCHEDULE tz. A
 * candidate is DROPPED when its month ∈ months OR its weekday ∈ weekdays OR its date (Y-m-d) ∈ dates.
 * The filter loops — compute the union candidate, skip past it if excluded, recompute — for EVERY
 * kind (interval too: "every 15 min except weekends" skips the whole weekend). HARD LIMITS bound the
 * loop: at most MAX_ITERATIONS steps AND a HORIZON_YEARS window from the start; exceeding either
 * returns null (a schedule with no reachable occurrence — e.g. weekly-Monday excluding Mondays).
 *
 * CONVENTIONS (settled here, mirrored in the store request + the compiler):
 *   - weekday: 0=Sunday .. 6=Saturday — matches Carbon::dayOfWeek AND cron's day-of-week 0=Sun.
 *   - tz: the schedule's own timezone; defaults to config('app.timezone') (UTC). Wall-clock
 *     cron families (daily/weekly/monthly… at HH:mm) are resolved IN this tz by the cron lib,
 *     then converted to UTC for storage, so a "09:00 Europe/Warsaw" daily schedule fires at the
 *     right UTC instant year round (07:00 UTC in summer/CEST, 08:00 UTC in winter/CET). Exclusions
 *     are evaluated against the candidate re-expressed in this same tz.
 *   - Every returned time is STRICTLY AFTER $from (never equal) and stored in UTC.
 *
 * INTERVAL vs CRON vs LAST-WORKING-DAY:
 *   - every_n_minutes is a BESPOKE interval (from + N minutes), phased on the arm instant with
 *     NO wall-clock alignment — a 15-minute schedule armed at 10:02 fires 10:17, 10:32, …
 *     (kept identical to the historical behaviour; it does NOT snap to :00/:15/:30/:45).
 *   - last_working_day_of_month is a BESPOKE cadence computed here (last Mon-Fri of the month at
 *     HH:mm, resolved in the schedule tz). It is NOT cron: dragonmantank's `LW` token does not mean
 *     "last working day" and returns garbage (see CompiledSchedule note), and the intent cannot be
 *     expressed as a single standard cron expression.
 *   - every other family compiles to one or more cron expressions whose next run date the cron lib
 *     resolves in the schedule tz with allowCurrentDate=FALSE (hence strictly-after).
 *
 * DAY-31 SKIP (monthly/quarterly/yearly): a day that does not exist in a given month (e.g. 31
 * in February) simply does not match — cron SKIPS that month rather than clamping to month-end.
 * This mirrors Laravel's monthlyOn(31). Use last_day_of_month for a guaranteed end-of-month fire.
 *
 * DST behaviour (documented, covered by unit tests around Europe/Warsaw's spring-forward AND
 * fall-back):
 *   For a wall-clock cron family the cron lib resolves the local time in the tz.
 *   SPRING-FORWARD gap (2026-03-29, Warsaw 02:00->03:00): a daily 02:30 does not exist; the lib
 *     resolves it to 03:30 local (01:30 UTC) that day rather than crashing or looping. That single
 *     skewed fire is the accepted trade-off for a rare boundary; the following day returns to 02:30.
 *   FALL-BACK overlap (2026-10-25, Warsaw 03:00->02:00, so 02:00-03:00 happens twice): a daily
 *     02:30 fires TWICE that night at two DISTINCT UTC instants — 00:30 UTC (02:30 CEST, +02:00, the
 *     first pass) then 01:30 UTC (02:30 CET, +01:00, the second pass after the clock rolls back).
 *     The strictly-after invariant advances from the first to the second (they are different UTC
 *     instants), so the same UTC moment is never fired twice; the next day returns to a single 02:30.
 *     This is the cron lib's observed resolution and is PINNED, not changed.
 */
class WorkflowScheduleService
{
    /**
     * The exclusion loop's hard stops (defence against an unreachable schedule). MAX_ITERATIONS
     * bounds the step count; HORIZON_YEARS bounds the search window from the start instant. Hitting
     * either returns null — no occurrence within a decade / a thousand candidates is treated as none.
     */
    private const MAX_ITERATIONS = 1000;

    private const HORIZON_YEARS = 10;

    public function __construct(
        private WorkflowScheduleCompiler $compiler = new WorkflowScheduleCompiler,
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
     * The next $count fire instants strictly after $from (default now()), in ASCENDING order and
     * UTC — the same cadence the write/sweep paths use, walked forward. Each occurrence anchors the
     * search for the next (cursor = occurrence), so the strictly-after invariant guarantees progress
     * and the interval families phase off the previous fire exactly as `arm` would. The list is SHORT
     * (≤ $count) and may be EMPTY when the cadence has no reachable occurrence (an over-constrained
     * exclusion set) — the caller treats [] as "no occurrences".
     *
     * A PURE function: it never touches tenancy, models or the database — it only interprets the
     * given schedule array. This is the seam the schedule-preview endpoint renders and the emptiness
     * guard reuses ($count = 1), so "the first occurrence" is computed in exactly one place.
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
     * Whether a preview of this schedule is only APPROXIMATE. True EXCLUSIVELY for the interval
     * cadence (every_n_minutes): its phase is set by the workflow's ARM instant (activation), so a
     * preview anchored in "now" is indicative, not the grid the live workflow will fire on. Every
     * wall-clock cron / last-working-day family is anchored to the calendar, so its preview is exact.
     * Decided on the COMPILED kind (the compiler is the authority on interval-vs-cron), never on the
     * family string.
     *
     * @param  array<string, mixed>  $schedule  the validated trigger_config.schedule block
     */
    public function isApproximate(array $schedule): bool
    {
        return $this->compiler->compile($schedule)->isInterval();
    }

    /**
     * The earliest strictly-after candidate across the compiled cadence (the UNION of all fire
     * times / expressions), BEFORE the exclusion filter. Null only when a cron list is empty
     * (defensive — the compiler always yields at least one).
     */
    private function nextCandidate(CompiledSchedule $compiled, Carbon $cursor, string $tz): ?Carbon
    {
        if ($compiled->isInterval()) {
            return $this->nextInterval($cursor, (int) $compiled->minutes);
        }

        if ($compiled->isLastWorkingDay()) {
            return $this->earliest(array_map(
                fn (array $time) => $this->nextLastWorkingDay($cursor, $time['hour'], $time['minute'], $tz),
                $compiled->times ?? [],
            ));
        }

        return $this->earliest(array_map(
            fn (string $expression) => $this->nextCron($expression, $cursor, $tz),
            $compiled->expressions ?? [],
        ));
    }

    /**
     * The earliest Carbon in a list (the union across fire times), or null for an empty list.
     *
     * @param  array<int, Carbon>  $candidates
     */
    private function earliest(array $candidates): ?Carbon
    {
        $earliest = null;

        foreach ($candidates as $candidate) {
            if ($earliest === null || $candidate->lessThan($earliest)) {
                $earliest = $candidate;
            }
        }

        return $earliest;
    }

    /**
     * Arm (or clear) a workflow's next_due_at from its cadence. A schedule workflow that is
     * ACTIVE gets its concrete next fire time; any other workflow (non-schedule, or an
     * inactive schedule) is left with a NULL due time so the sweep's WHERE never sees it. A
     * cadence with NO reachable occurrence (over-constrained exclusions) also leaves next_due_at
     * NULL — the workflow simply never fires, without a crash or a busy-loop.
     */
    public function arm(Workflow $workflow, ?CarbonInterface $from = null): void
    {
        if (!$this->isArmable($workflow)) {
            $workflow->next_due_at = null;

            return;
        }

        $schedule = $workflow->trigger_config['schedule'] ?? [];

        $workflow->next_due_at = $this->nextDueAt($schedule, $from ?? Carbon::now());
    }

    /** A workflow is armable iff it is an ACTIVE schedule-triggered workflow. */
    public function isArmable(Workflow $workflow): bool
    {
        return $workflow->trigger_type === WorkflowTriggerType::SCHEDULE
            && $workflow->status === WorkflowStatus::ACTIVE;
    }

    /**
     * Race-safe compare-and-swap claim of a DUE workflow's slot. Advances next_due_at to the
     * next fire time and stamps last_scheduled_run_at in ONE conditional UPDATE that matches
     * ONLY if next_due_at is STILL the value the sweep read ($expectedDueAt) and the workflow
     * is STILL active:
     *
     *   UPDATE workflows
     *   SET next_due_at = <recomputed>, last_scheduled_run_at = now()
     *   WHERE id = ? AND next_due_at = <expected> AND status = 'active'
     *
     * Postgres locks the row for the UPDATE, so exactly one concurrent sweep flips it and sees
     * affected=1 (we WON the slot); a rival that already advanced it sees 0 (LOST — skip). This
     * is the second guard beyond `withoutOverlapping`: even two overlapping sweeps fire a due
     * workflow at most once. The advanced next_due_at is computed from $expectedDueAt (the slot
     * that was due), so cadence stays anchored to the intended grid, not to now().
     *
     * Returns the newly-armed next fire time iff THIS call won the slot, else null. When the
     * recomputed next time is null (an exclusion set that has no further occurrence), the slot is
     * CLEARED (next_due_at = null) on a winning CAS so the workflow stops firing without looping.
     *
     * The WHERE matches on the RAW stored next_due_at string (getRawOriginal), so the CAS
     * compares byte-for-byte against what the DB holds — no format/precision mismatch with the
     * datetime cast. The next fire time is recomputed from the model's (cast) due Carbon so it
     * stays anchored to the intended grid, not to now().
     */
    public function claimDue(Workflow $workflow): ?Carbon
    {
        $expectedRaw = $workflow->getRawOriginal('next_due_at');

        if ($expectedRaw === null) {
            return null;
        }

        $next = $this->nextDueAt($workflow->trigger_config['schedule'] ?? [], $workflow->next_due_at);

        $affected = Workflow::withoutGlobalScopes()
            ->whereKey($workflow->id)
            ->where('next_due_at', $expectedRaw)
            ->where('status', WorkflowStatus::ACTIVE->value)
            ->update([
                'next_due_at' => $next,
                'last_scheduled_run_at' => now(),
            ]);

        return $affected === 1 ? $next : null;
    }

    /**
     * every_n_minutes: a SIMPLE interval — $from + n minutes. No wall-clock alignment (a
     * schedule armed at 10:02 with n=15 fires at 10:17, not 10:15), which keeps the cadence
     * a pure "every N minutes from when it was armed" and avoids drift bookkeeping. Returned
     * in UTC for storage (the interval is timezone-agnostic — N minutes is N minutes).
     */
    private function nextInterval(CarbonInterface $from, int $minutes): Carbon
    {
        $minutes = max(1, $minutes);

        return Carbon::instance($from->toImmutable())->setTimezone('UTC')->addMinutes($minutes);
    }

    /**
     * A cron family: let the cron lib compute the next run date IN the schedule tz with
     * allowCurrentDate=false (strictly after $from), then convert to UTC for storage. Passing
     * the tz string makes the lib resolve wall-clock fields (HH:mm, weekday, day-of-month) as
     * LOCAL times in that zone, which is what a "09:00 Europe/Warsaw daily" schedule means.
     */
    private function nextCron(string $expression, CarbonInterface $from, string $tz): Carbon
    {
        $utcFrom = Carbon::instance($from->toImmutable())->setTimezone('UTC');

        $next = (new CronExpression($expression))->getNextRunDate($utcFrom, 0, false, $tz);

        return Carbon::instance($next)->setTimezone('UTC');
    }

    /**
     * last_working_day_of_month: the last Mon-Fri of the month at $hour:$minute, resolved in the
     * schedule tz and returned in UTC. Computed here (not via cron) because dragonmantank's `LW`
     * token is broken (see CompiledSchedule note). We walk this month's last working day; if that
     * instant is not STRICTLY AFTER $from we advance to next month's, so the result is always later.
     * The candidate is built as a LOCAL wall-clock time in the tz, then converted to UTC — matching
     * every other wall-clock family. Public holidays are ignored (last calendar weekday only).
     */
    private function nextLastWorkingDay(CarbonInterface $from, int $hour, int $minute, string $tz): Carbon
    {
        $localFrom = Carbon::instance($from->toImmutable())->setTimezone($tz);

        $candidate = $this->lastWorkingDayOfMonth($localFrom, $hour, $minute);

        if ($candidate->lessThanOrEqualTo($localFrom)) {
            $candidate = $this->lastWorkingDayOfMonth($localFrom->copy()->addMonthNoOverflow()->startOfMonth(), $hour, $minute);
        }

        return $candidate->setTimezone('UTC');
    }

    /**
     * The last working day (Mon-Fri) of $reference's month at $hour:$minute, as a Carbon in
     * $reference's timezone. Starts at the last calendar day and steps backwards over Sat/Sun.
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
     * each a list; missing/blank -> empty). An empty block (or an all-empty one) means "no
     * exclusions" and the filter is a no-op — the write validator is what rejects an all-empty block
     * as a mistake; here we treat it leniently so a stale/partial record never crashes the sweep.
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
     * (Y-m-d) ∈ dates — all evaluated against the candidate RE-EXPRESSED in the schedule tz (an
     * exclusion is a wall-clock-day concept, like the rest of the cadence). A no-op when every
     * exclusion list is empty.
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

    /** The schedule's timezone, defaulting to the app timezone (UTC). */
    private function timezone(array $schedule): string
    {
        $tz = $schedule['tz'] ?? null;

        return is_string($tz) && $tz !== '' ? $tz : (string) config('app.timezone', 'UTC');
    }
}
