<?php

namespace App\Support\Recurrence;

use App\Support\Recurrence\Enums\ScheduleDayMode;
use App\Support\Recurrence\Enums\ScheduleDaySpecial;
use App\Support\Recurrence\Enums\ScheduleMonthMode;
use App\Support\Recurrence\Enums\ScheduleTimeMode;
use InvalidArgumentException;

/**
 * The SINGLE source of truth that turns a validated v2 `trigger_config.schedule` descriptor into a
 * CompiledSchedule (a LIST of cron expressions, or a bespoke last-working-day cadence).
 * ScheduleEngine is the only consumer; it never contains a descriptor->cron mapping of its
 * own, so the cadence grammar lives in exactly one place.
 *
 * COMPOSITIONAL DESCRIPTOR (already validated by RecurrenceDescriptorValidator + the consuming
 * module's own per-key rules):
 *   { time: {mode,…}, day?: {mode,…}, month?: {mode,…}, tz?, exclusions? }
 * The three axes are INDEPENDENT: the day axis fills the cron day-of-month OR day-of-week field
 * (never both), the month axis fills the month field, and EVERY time expression carries the SAME
 * day/month fields. `exclusions`/`tz` never reach the cron grammar — the service applies exclusions
 * as a post-filter and resolves wall-clock fields in the tz.
 *
 * CRON GRAMMAR (fields: minute hour day-of-month month day-of-week):
 *   time.at            -> one `M H …` per fire time (union across time.at).
 *   time.every_minutes -> `*​/n * …` (whole-hour grid); with an HH:mm window it is the ≤3-expression
 *                         UNION `M1-59/n H1`, `*​/n (H1+1)-(H2-1)` (only when H1+1 ≤ H2-1),
 *                         `0-M2/n H2`, or the single `M1-M2/n H1` when the window stays in one hour.
 *   time.every_hours   -> `m` on an every-n-hours grid; with a window `m from-to/n …`.
 *   day.every_n_days   -> dom `*​/n` (or `from-to/n` with a window); dow `*`.
 *   day.weekdays       -> dow `d1,d2,…` (sorted, deduped; 0=Sunday); dom `*`.
 *   day.month_days     -> dom `d1,d2,…`; dow `*`.
 *   day.special last_day        -> dom `L`.
 *   day.special nth_weekday     -> dow `{weekday}#{ordinal}` (ordinal=5 skips a month lacking a 5th).
 *   day.special last_weekday    -> dow `{weekday}L`.
 *   day.special last_working_day-> BESPOKE (see below).
 *   month.every_n_months -> `*​/n` (or `from-to/n`); month.months -> `m1,m2,…`.
 *
 * LAST WORKING DAY is BESPOKE (the last Mon-Fri of the month at time.at HH:mm) — the dragonmantank
 * `LW` token is broken (see CompiledSchedule). It carries its allowed months (from the month axis)
 * so it can combine with a month restriction; the service iterates months and skips disallowed ones.
 *
 * SEMANTIC NOTES (matching cron's day-of-month semantics, pinned in the tests):
 *   - a day-of-month that a month lacks (31 in February) simply does not match — the fire is SKIPPED
 *     for that month, never clamped. Use day.special last_day for a guaranteed month-end fire.
 *   - month `*​/n` and every_n_hours `*​/n` are MODULO grids (they reset at the year/day boundary),
 *     so the gap across the boundary can be shorter than n.
 */
class ScheduleCompiler
{
    public function __construct(
        private LegacyScheduleUpgrader $upgrader = new LegacyScheduleUpgrader,
    ) {}

    /**
     * Compile a schedule block into its cron/last-working-day form. A legacy block is upgraded to v2
     * first (the read-shim), so the grammar below only ever sees the compositional descriptor.
     *
     * @param  array<string, mixed>  $schedule  the validated trigger_config.schedule block
     */
    public function compile(array $schedule): CompiledSchedule
    {
        $schedule = $this->upgrader->toV2($schedule);

        $time = $this->block($schedule, 'time');
        $day = $this->block($schedule, 'day');
        $month = $this->block($schedule, 'month');

        // day.special = last_working_day is the one bespoke, non-cron cadence — it fires at explicit
        // HH:mm times (time.mode=at, enforced by validation) in the months the month axis allows.
        if ($this->isLastWorkingDay($day)) {
            return CompiledSchedule::lastWorkingDay($this->hourMinuteList($time), $this->monthSet($month));
        }

        [$dom, $dow] = $this->dayFields($day);
        $monthToken = $this->monthToken($month);

        return CompiledSchedule::cronList($this->timeExpressions($time, $dom, $monthToken, $dow));
    }

    /** Whether the day axis selects the bespoke last-working-day rule. */
    private function isLastWorkingDay(array $day): bool
    {
        return ($day['mode'] ?? null) === ScheduleDayMode::SPECIAL->value
            && ($day['special'] ?? null) === ScheduleDaySpecial::LAST_WORKING_DAY->value;
    }

    /**
     * The cron expressions for the TIME axis, each carrying the shared $dom/$monthToken/$dow fields.
     * A single expression for most cases; the ≤3-expression union for a minute window.
     *
     * @return array<int, string>
     */
    private function timeExpressions(array $time, string $dom, string $monthToken, string $dow): array
    {
        $mode = ScheduleTimeMode::from((string) ($time['mode'] ?? ''));
        $tail = sprintf('%s %s %s', $dom, $monthToken, $dow);

        return match ($mode) {
            ScheduleTimeMode::AT => $this->atExpressions($time, $tail),
            ScheduleTimeMode::EVERY_MINUTES => $this->everyMinutesExpressions($time, $tail),
            ScheduleTimeMode::EVERY_HOURS => $this->everyHoursExpressions($time, $tail),
        };
    }

    /** time.at: one `minute hour <tail>` per fire time (the union across time.at). */
    private function atExpressions(array $time, string $tail): array
    {
        return array_map(
            fn (array $hm) => sprintf('%d %d %s', $hm['minute'], $hm['hour'], $tail),
            $this->hourMinuteList($time),
        );
    }

    /**
     * time.every_minutes: `*​/n * <tail>` on the whole-hour grid, OR the ≤3-expression UNION when an
     * HH:mm window is set. Window through a single hour collapses to `M1-M2/n H1`; across hours it is
     * the head `M1-59/n H1`, the optional full middle `*​/n (H1+1)-(H2-1)`, and the tail `0-M2/n H2`.
     * The window never wraps midnight (from<to is enforced by validation).
     */
    private function everyMinutesExpressions(array $time, string $tail): array
    {
        $n = $this->int($time, 'minutes', 1);

        if (!$this->hasWindow($time)) {
            return [sprintf('*/%d * %s', $n, $tail)];
        }

        [$h1, $m1] = $this->parseTime((string) $time['from']);
        [$h2, $m2] = $this->parseTime((string) $time['to']);

        if ($h1 === $h2) {
            return [sprintf('%d-%d/%d %d %s', $m1, $m2, $n, $h1, $tail)];
        }

        $expressions = [sprintf('%d-59/%d %d %s', $m1, $n, $h1, $tail)];

        if ($h1 + 1 <= $h2 - 1) {
            $expressions[] = sprintf('*/%d %d-%d %s', $n, $h1 + 1, $h2 - 1, $tail);
        }

        $expressions[] = sprintf('0-%d/%d %d %s', $m2, $n, $h2, $tail);

        return $expressions;
    }

    /**
     * time.every_hours: `minute` on the whole-day every-n-hours grid, OR `minute from-to/n <tail>`
     * when a 0..23 hour window is set. `minute` defaults to 0.
     */
    private function everyHoursExpressions(array $time, string $tail): array
    {
        $n = $this->int($time, 'hours', 1);
        $minute = $this->int($time, 'minute', 0);

        if (!$this->hasWindow($time)) {
            return [sprintf('%d */%d %s', $minute, $n, $tail)];
        }

        return [sprintf('%d %d-%d/%d %s', $minute, $this->int($time, 'from', 0), $this->int($time, 'to', 0), $n, $tail)];
    }

    /**
     * The day axis as [day-of-month, day-of-week] cron fields — exactly one is constrained, the other
     * is `*`, so the cron dom/dow OR-trap never arises.
     *
     * @return array{0: string, 1: string}
     */
    private function dayFields(array $day): array
    {
        $mode = ScheduleDayMode::from((string) ($day['mode'] ?? ScheduleDayMode::EVERY_DAY->value));

        return match ($mode) {
            ScheduleDayMode::EVERY_DAY => ['*', '*'],
            ScheduleDayMode::EVERY_N_DAYS => [$this->stepField($day, 'n', $this->int($day, 'n', 1)), '*'],
            ScheduleDayMode::WEEKDAYS => ['*', implode(',', $this->sortedUnique($day['weekdays'] ?? []))],
            ScheduleDayMode::MONTH_DAYS => [implode(',', $this->sortedUnique($day['days'] ?? [])), '*'],
            ScheduleDayMode::SPECIAL => $this->specialDayFields($day),
        };
    }

    /**
     * The dom/dow fields for a `special` day rule (last_working_day is handled before this — bespoke).
     *
     * @return array{0: string, 1: string}
     */
    private function specialDayFields(array $day): array
    {
        $special = ScheduleDaySpecial::from((string) ($day['special'] ?? ''));

        return match ($special) {
            ScheduleDaySpecial::LAST_DAY => ['L', '*'],
            ScheduleDaySpecial::NTH_WEEKDAY => ['*', sprintf('%d#%d', $this->int($day, 'weekday', 0), $this->int($day, 'ordinal', 1))],
            ScheduleDaySpecial::LAST_WEEKDAY => ['*', sprintf('%dL', $this->int($day, 'weekday', 0))],
            ScheduleDaySpecial::LAST_WORKING_DAY => throw new InvalidArgumentException('last_working_day is a bespoke cadence, not a cron field'),
        };
    }

    /** The month axis as a single cron month token. */
    private function monthToken(array $month): string
    {
        $mode = ScheduleMonthMode::from((string) ($month['mode'] ?? ScheduleMonthMode::EVERY_MONTH->value));

        return match ($mode) {
            ScheduleMonthMode::EVERY_MONTH => '*',
            ScheduleMonthMode::EVERY_N_MONTHS => $this->stepField($month, 'n', $this->int($month, 'n', 1)),
            ScheduleMonthMode::MONTHS => implode(',', $this->sortedUnique($month['months'] ?? [])),
        };
    }

    /**
     * The concrete months (1..12) a bespoke last-working-day cadence may fire in, expanding the month
     * axis exactly as its cron token would match: every_month -> all 12; months -> the set;
     * every_n_months -> the from..to/n grid (from defaults to 1, to to 12), matching cron `*​/n`.
     *
     * @return array<int, int>
     */
    private function monthSet(array $month): array
    {
        $mode = ScheduleMonthMode::from((string) ($month['mode'] ?? ScheduleMonthMode::EVERY_MONTH->value));

        if ($mode === ScheduleMonthMode::MONTHS) {
            return $this->sortedUnique($month['months'] ?? []);
        }

        if ($mode === ScheduleMonthMode::EVERY_MONTH) {
            return range(1, 12);
        }

        $n = max(1, $this->int($month, 'n', 1));
        $from = $this->hasWindow($month) ? $this->int($month, 'from', 1) : 1;
        $to = $this->hasWindow($month) ? $this->int($month, 'to', 12) : 12;

        $months = [];
        for ($m = $from; $m <= $to; $m += $n) {
            $months[] = $m;
        }

        return $months;
    }

    /**
     * A cron STEP field: `from-to/n` when the axis carries a window, else `*​/n`. Shared by
     * every_n_days (dom) and every_n_months (month).
     */
    private function stepField(array $block, string $stepKey, int $step): string
    {
        if ($this->hasWindow($block)) {
            return sprintf('%d-%d/%d', $this->int($block, 'from', 0), $this->int($block, 'to', 0), $step);
        }

        return sprintf('*/%d', $step);
    }

    /** Whether an axis block carries BOTH window bounds (both-or-none is enforced by validation). */
    private function hasWindow(array $block): bool
    {
        return array_key_exists('from', $block) && array_key_exists('to', $block)
            && $block['from'] !== null && $block['to'] !== null;
    }

    /**
     * The [hour, minute] fire times of a time.at axis (each 'HH:mm' parsed to a pair). Empty/absent
     * yields a single defensive midnight — validation requires a non-empty at-list.
     *
     * @return array<int, array{hour: int, minute: int}>
     */
    private function hourMinuteList(array $time): array
    {
        $at = $time['at'] ?? null;

        if (!is_array($at) || $at === []) {
            return [['hour' => 0, 'minute' => 0]];
        }

        return array_map(function ($value) {
            [$hour, $minute] = $this->parseTime((string) $value);

            return ['hour' => $hour, 'minute' => $minute];
        }, array_values($at));
    }

    /**
     * Parse an 'HH:mm' string into a [hour, minute] pair.
     *
     * @return array{0: int, 1: int}
     */
    private function parseTime(string $time): array
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return [(int) $hour, (int) $minute];
    }

    /**
     * A list of ints, SORTED ascending and DEDUPED — the shape cron wants for a value set.
     *
     * @return array<int, int>
     */
    private function sortedUnique(mixed $values): array
    {
        $values = is_array($values) ? $values : [];
        $ints = array_values(array_unique(array_map('intval', $values)));
        sort($ints);

        return $ints;
    }

    /**
     * A named sub-block ({ time, day, month }) as an array, defaulting to an empty array when absent
     * (day/month default to every_day/every_month, handled by the field builders).
     *
     * @return array<string, mixed>
     */
    private function block(array $schedule, string $key): array
    {
        return is_array($schedule[$key] ?? null) ? $schedule[$key] : [];
    }

    /** An integer axis field with a default; guards against a malformed post-validation shape. */
    private function int(array $block, string $key, int $default): int
    {
        $value = $block[$key] ?? $default;

        if (!is_numeric($value)) {
            throw new InvalidArgumentException("schedule field [{$key}] must be numeric");
        }

        return (int) $value;
    }
}
