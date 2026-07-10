<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\Enums\WorkflowScheduleFamily;
use InvalidArgumentException;

/**
 * The SINGLE source of truth that turns a validated `trigger_config.schedule` block into a
 * CompiledSchedule (a bespoke interval, a LIST of cron expressions, or a bespoke last-working-day
 * cadence). WorkflowScheduleService is the only consumer; it never contains a family->cron mapping
 * of its own, so the cadence grammar lives in exactly one place.
 *
 * SCHEDULE SHAPE (already validated by StoreWorkflowRequest::scheduleRules):
 *   { family: WorkflowScheduleFamily, params: {…per family}, tz?, times?[], exclusions? }
 *
 * MULTIPLE FIRE TIMES (optional `times[]`, replaces the single `params.time`): a wall-clock family
 * may carry a `times[]` list (1..6 HH:mm) instead of `params.time`, meaning "fire at EACH of these
 * times". The compiler expands it to ONE cron expression per time (same date fields, differing
 * minute/hour) — a CompiledSchedule::cronList — and the service takes the earliest strictly-after
 * candidate across them. A single `params.time` compiles to a one-element list (unchanged shape).
 * The interval families have no `time` param and forbid `times[]` (rejected by validation).
 * `exclusions` never reach the compiler's cron grammar — they are applied by the service as a
 * post-filter on the computed candidate, so no cron field encodes them.
 *
 * CRON GRAMMAR (fields: minute hour day-of-month month day-of-week):
 *   - every_n_minutes  -> INTERVAL (not cron): a pure from+N cadence phased on the arm time,
 *                         so a 15-minute schedule armed at 10:02 fires 10:17, 10:32, … This
 *                         deliberately does NOT use cron `*​/N` (which would snap to :00/:15/…),
 *                         preserving the historical "every N minutes from when armed" behaviour.
 *   - hourly           -> `0 * * * *`                (top of every hour)
 *   - hourly_at        -> `M * * * *`                (minute M of every hour)
 *   - every_n_hours    -> `M *​/N * * *`  N in 2..12  (hour-of-day MODULO N — see note below)
 *   - daily            -> `M H * * *`                (dailyAt HH:mm; one per time when times[])
 *   - twice_daily      -> `M h1,h2 * * *`            (two explicit hours; first<second enforced)
 *   - weekly           -> `M H * * d1,d2,…`          (weeklyOn a SET of weekdays; 0=Sunday, cron
 *                                                     dow 0=Sunday too; days sorted+deduped)
 *   - monthly          -> `M H D * *`                (monthlyOn; day D — see day-31 skip note)
 *   - twice_monthly    -> `M H d1,d2 * *`            (two explicit days; first<second enforced)
 *   - last_day_of_month-> `M H L * *`                (dragonmantank `L` = last calendar day)
 *   - quarterly         -> `M H D 1,4,7,10 *`         (quarterlyOn; day D of Jan/Apr/Jul/Oct)
 *   - yearly           -> `M H D Mon *`              (yearlyOn; day D of month Mon)
 *   - every_n_months   -> `M H D m1,m2,… *`          (day D of a JANUARY-ANCHORED month grid;
 *                                                     see modulo-year note)
 *   - nth_weekday_of_month  -> `M H * * W#O`         (dragonmantank `#`: the O-th W of the month;
 *                                                     ordinal=5 in a month without a 5th W is SKIPPED)
 *   - last_weekday_of_month -> `M H * * WL`          (dragonmantank `WL`: the last W of the month)
 *   - last_working_day_of_month -> BESPOKE           (last Mon-Fri of the month at HH:mm, computed in
 *                                                     the service; the `LW` cron token is BROKEN in
 *                                                     dragonmantank v3.6.0 — see CompiledSchedule note.
 *                                                     Does NOT account for public holidays.)
 *
 * SEMANTIC NOTES (matching Laravel's scheduler and documented in the tests):
 *   - every_n_hours `*​/N` is HOUR-OF-DAY modulo N, NOT a rolling N-hour interval. For N=5 it
 *     fires at 00,05,10,15,20 then RESETS at 00 next day, so the gap across midnight is 4h,
 *     not 5h. This is exactly how Laravel's everyFiveHours behaves (it also compiles to `*​/5`).
 *   - every_n_months is a JANUARY-ANCHORED month grid, MODULO the year (analogous to every_n_hours
 *     being hour-of-day modulo N). The month list is 1, 1+n, 1+2n, … ≤ 12 (n=2 -> 1,3,5,7,9,11;
 *     n=5 -> 1,6,11), so the cadence RESETS every January and the gap across the year boundary can
 *     be shorter than n months (n=5: …, Nov(11), then Jan(1) again — a 2-month gap). This mirrors
 *     the every_n_hours midnight-reset trade-off and is pinned in the tests.
 *   - monthly/quarterly/yearly/every_n_months day 31 (or 30/29) in a SHORTER month: cron simply does
 *     not match that month, so the fire is SKIPPED for months without that day (Feb never fires day
 *     31). No clamping — Laravel's monthlyOn(31) behaves the same. Use last_day_of_month for a
 *     guaranteed month-end fire.
 *   - yearly month=2 day=29 therefore fires ONLY IN LEAP YEARS (once per ~4 years) — pinned in
 *     the tests. The FE/AI surfaces should steer month-end intents to last_day_of_month.
 *   - nth_weekday_of_month with ordinal=5 SKIPS months that have only four occurrences of the
 *     weekday (a `W#5` cron does not match such a month) — same skip doctrine as day-31. Use
 *     last_weekday_of_month for a guaranteed final-occurrence fire.
 *   - last_working_day_of_month (`LW`) is the last Mon-Fri of the calendar month. It does NOT know
 *     about public holidays, so a month whose last weekday is a holiday still fires that day.
 */
class WorkflowScheduleCompiler
{
    /**
     * Compile a validated schedule block into its interval/cron/last-working-day form.
     *
     * @param  array<string, mixed>  $schedule  the validated trigger_config.schedule block
     */
    public function compile(array $schedule): CompiledSchedule
    {
        $family = WorkflowScheduleFamily::from((string) ($schedule['family'] ?? ''));
        $params = is_array($schedule['params'] ?? null) ? $schedule['params'] : [];

        return match ($family) {
            WorkflowScheduleFamily::EVERY_N_MINUTES => CompiledSchedule::interval($this->int($params, 'n', 1)),
            WorkflowScheduleFamily::HOURLY => CompiledSchedule::cron('0 * * * *'),
            WorkflowScheduleFamily::HOURLY_AT => CompiledSchedule::cron(
                sprintf('%d * * * *', $this->int($params, 'minute', 0)),
            ),
            WorkflowScheduleFamily::EVERY_N_HOURS => CompiledSchedule::cron(
                sprintf('%d */%d * * *', $this->int($params, 'minute', 0), $this->int($params, 'n', 2)),
            ),
            WorkflowScheduleFamily::DAILY => $this->cronFromTimes($schedule, $params, '%d %d * * *'),
            WorkflowScheduleFamily::TWICE_DAILY => $this->compileTwiceDaily($params),
            WorkflowScheduleFamily::WEEKLY => $this->compileWeekly($schedule, $params),
            WorkflowScheduleFamily::MONTHLY => $this->compileMonthly($schedule, $params),
            WorkflowScheduleFamily::TWICE_MONTHLY => $this->compileTwiceMonthly($schedule, $params),
            WorkflowScheduleFamily::LAST_DAY_OF_MONTH => $this->cronFromTimes($schedule, $params, '%d %d L * *'),
            WorkflowScheduleFamily::QUARTERLY => $this->compileQuarterly($schedule, $params),
            WorkflowScheduleFamily::YEARLY => $this->compileYearly($schedule, $params),
            WorkflowScheduleFamily::EVERY_N_MONTHS => $this->compileEveryNMonths($schedule, $params),
            WorkflowScheduleFamily::NTH_WEEKDAY_OF_MONTH => $this->compileNthWeekdayOfMonth($schedule, $params),
            WorkflowScheduleFamily::LAST_WEEKDAY_OF_MONTH => $this->compileLastWeekdayOfMonth($schedule, $params),
            WorkflowScheduleFamily::LAST_WORKING_DAY_OF_MONTH => $this->compileLastWorkingDayOfMonth($schedule, $params),
        };
    }

    /** twice_daily: `minute first_hour,second_hour * * *`. minute defaults to 0. No times[]. */
    private function compileTwiceDaily(array $params): CompiledSchedule
    {
        $minute = $this->int($params, 'minute', 0);
        $first = $this->int($params, 'first_hour', 0);
        $second = $this->int($params, 'second_hour', 0);

        return CompiledSchedule::cron(sprintf('%d %d,%d * * *', $minute, $first, $second));
    }

    /**
     * weekly: `minute hour * * d1,d2,…` — a SET of weekdays, sorted ascending and deduped (cron
     * dow 0=Sunday matches our weekday convention). One expression per fire time when times[] is set.
     *
     * READ TOLERANCE (Bot-module pattern): the write path now stores the multi-day `weekdays` list,
     * but a legacy record may still carry a scalar `weekday`. When `weekdays` is absent we read the
     * legacy `weekday` as a single-element list, so old rows keep compiling. Validation of NEW
     * writes accepts only the `weekdays` shape.
     */
    private function compileWeekly(array $schedule, array $params): CompiledSchedule
    {
        $weekdays = $this->weekdayList($params);

        return $this->expandTimes($schedule, $params, '%d %d * * ' . implode(',', $weekdays));
    }

    /**
     * every_n_months: `minute hour day m1,m2,… *` where the month list is a JANUARY-ANCHORED grid
     * (1, 1+n, 1+2n, … ≤ 12), so the cadence resets every January (modulo-year — see class note).
     */
    private function compileEveryNMonths(array $schedule, array $params): CompiledSchedule
    {
        $n = max(1, $this->int($params, 'n', 2));
        $day = $this->int($params, 'day', 1);

        $months = [];
        for ($month = 1; $month <= 12; $month += $n) {
            $months[] = $month;
        }

        return $this->expandTimes($schedule, $params, sprintf('%%d %%d %d %s *', $day, implode(',', $months)));
    }

    /**
     * nth_weekday_of_month: `minute hour * * weekday#ordinal` (dragonmantank `#` token). ordinal=5
     * in a month with only four occurrences of the weekday simply does not match — that month is
     * SKIPPED (see class note).
     */
    private function compileNthWeekdayOfMonth(array $schedule, array $params): CompiledSchedule
    {
        $weekday = $this->int($params, 'weekday', 0);
        $ordinal = $this->int($params, 'ordinal', 1);

        return $this->expandTimes($schedule, $params, sprintf('%%d %%d * * %d#%d', $weekday, $ordinal));
    }

    /** last_weekday_of_month: `minute hour * * weekdayL` (dragonmantank `L` suffix in the dow field). */
    private function compileLastWeekdayOfMonth(array $schedule, array $params): CompiledSchedule
    {
        $weekday = $this->int($params, 'weekday', 0);

        return $this->expandTimes($schedule, $params, sprintf('%%d %%d * * %dL', $weekday));
    }

    /**
     * last_working_day_of_month: a BESPOKE cadence (the last Mon-Fri of the month at HH:mm), NOT a
     * cron expression — dragonmantank's `LW` token does not express "last working day" and returns
     * garbage (see CompiledSchedule note). The service computes the concrete instant in the tz. With
     * times[] it carries every listed HH:mm; the service takes the earliest strictly-after.
     */
    private function compileLastWorkingDayOfMonth(array $schedule, array $params): CompiledSchedule
    {
        return CompiledSchedule::lastWorkingDayList($this->hourMinuteList($schedule, $params));
    }

    /** monthly: `minute hour day * *` (day-31 in short months is skipped — see class note). */
    private function compileMonthly(array $schedule, array $params): CompiledSchedule
    {
        $day = $this->int($params, 'day', 1);

        return $this->expandTimes($schedule, $params, sprintf('%%d %%d %d * *', $day));
    }

    /** twice_monthly: `minute hour first_day,second_day * *`. */
    private function compileTwiceMonthly(array $schedule, array $params): CompiledSchedule
    {
        $first = $this->int($params, 'first_day', 1);
        $second = $this->int($params, 'second_day', 1);

        return $this->expandTimes($schedule, $params, sprintf('%%d %%d %d,%d * *', $first, $second));
    }

    /** quarterly: `minute hour day 1,4,7,10 *` (day D of Jan/Apr/Jul/Oct). */
    private function compileQuarterly(array $schedule, array $params): CompiledSchedule
    {
        $day = $this->int($params, 'day', 1);

        return $this->expandTimes($schedule, $params, sprintf('%%d %%d %d 1,4,7,10 *', $day));
    }

    /** yearly: `minute hour day month *`. */
    private function compileYearly(array $schedule, array $params): CompiledSchedule
    {
        $day = $this->int($params, 'day', 1);
        $month = $this->int($params, 'month', 1);

        return $this->expandTimes($schedule, $params, sprintf('%%d %%d %d %d *', $day, $month));
    }

    /**
     * Build the cron cadence for a wall-clock family from a `%d %d …` template whose first two
     * placeholders are minute then hour, expanded over every fire time (the single `params.time`,
     * or each entry of `times[]`). One expression per time; the service takes the earliest
     * strictly-after. Shared by daily and last_day_of_month.
     */
    private function cronFromTimes(array $schedule, array $params, string $template): CompiledSchedule
    {
        return $this->expandTimes($schedule, $params, $template);
    }

    /**
     * Expand a `%d %d …` cron template (minute then hour placeholders) over every fire time into a
     * CompiledSchedule::cronList. A single time yields a one-element list (unchanged shape); the
     * service returns the earliest strictly-after candidate across the list.
     */
    private function expandTimes(array $schedule, array $params, string $template): CompiledSchedule
    {
        $expressions = array_map(
            fn (array $time) => sprintf($template, $time['minute'], $time['hour']),
            $this->hourMinuteList($schedule, $params),
        );

        return CompiledSchedule::cronList($expressions);
    }

    /**
     * The list of [hour, minute] fire times for a wall-clock family. When `schedule.times` is a
     * non-empty array it wins (each 'HH:mm' parsed to a pair); otherwise the single `params.time`
     * yields a one-element list. A missing/blank time defaults to midnight (00:00) — validation
     * requires a time (or times) for the wall-clock families, so the default is only defensive.
     *
     * @param  array<string, mixed>  $schedule
     * @param  array<string, mixed>  $params
     * @return array<int, array{hour: int, minute: int}>
     */
    private function hourMinuteList(array $schedule, array $params): array
    {
        $times = $schedule['times'] ?? null;

        if (is_array($times) && $times !== []) {
            return array_map(fn ($time) => $this->parseTime((string) $time), array_values($times));
        }

        return [$this->parseTime((string) ($params['time'] ?? '00:00'))];
    }

    /**
     * Parse an 'HH:mm' string into a {hour, minute} pair.
     *
     * @return array{hour: int, minute: int}
     */
    private function parseTime(string $time): array
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return ['hour' => (int) $hour, 'minute' => (int) $minute];
    }

    /**
     * The `weekdays` list ('weekday_list' type) as SORTED, DEDUPED ints. Read tolerance: when
     * `weekdays` is absent a legacy scalar `weekday` is read as a single-element list, so old rows
     * still compile. Falls back to [0] (Sunday) only if neither is present (a defensive default —
     * validation requires weekdays for new writes).
     *
     * @return array<int, int>
     */
    private function weekdayList(array $params): array
    {
        $raw = $params['weekdays'] ?? null;

        if (!is_array($raw)) {
            $raw = array_key_exists('weekday', $params) ? [$params['weekday']] : [0];
        }

        $days = array_values(array_unique(array_map('intval', $raw)));
        sort($days);

        return $days === [] ? [0] : $days;
    }

    /** An integer param with a default; guards against a malformed post-validation shape. */
    private function int(array $params, string $key, int $default): int
    {
        $value = $params[$key] ?? $default;

        if (!is_numeric($value)) {
            throw new InvalidArgumentException("schedule param [{$key}] must be numeric");
        }

        return (int) $value;
    }
}
