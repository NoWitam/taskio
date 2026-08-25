<?php

namespace App\Support\Recurrence;

/**
 * READ-SHIM: transparently upgrades a legacy `{ family, params }` schedule block to the v2
 * compositional `{ time, day, month }` descriptor, so the validator, the compiler and the service
 * only ever reason about ONE shape. It runs at every read boundary of a stored schedule (the
 * detail Resource that seeds the FE editor, the next_due/sweep computation) and at the v2 core's
 * own entry points (compile/validate), so a row written before the rebuild keeps firing and keeps
 * rendering without a data migration.
 *
 * FORMAT DETECTION is by the presence of the `family` key: legacy blocks always carry it, v2 blocks
 * never do. A v2 block (or anything without `family`) is returned VERBATIM — the shim is idempotent.
 * An unknown/garbage family is returned unchanged too (it then fails v2 validation on its missing
 * `time`, exactly like any malformed block).
 *
 * `exclusions` and `tz` are UNCHANGED between the two shapes and pass through verbatim.
 *
 * MAPPING (legacy family -> v2 axes; `times[]` on a wall-clock family becomes time.at wholesale):
 *   every_n_minutes{n}            -> time: every_minutes{minutes:n}
 *   hourly                        -> time: every_hours{hours:1, minute:0}
 *   hourly_at{minute}             -> time: every_hours{hours:1, minute}
 *   every_n_hours{n,minute}       -> time: every_hours{hours:n, minute}
 *   daily{time}                   -> time: at[time]
 *   twice_daily{h1,h2,minute}     -> time: at[h1:mm, h2:mm]
 *   weekly{weekdays|weekday,time} -> time: at[time], day: weekdays{weekdays}
 *   monthly{day,time}             -> time: at[time], day: month_days{days:[day]}
 *   twice_monthly{d1,d2,time}     -> time: at[time], day: month_days{days:[d1,d2]}
 *   last_day_of_month{time}       -> time: at[time], day: special{last_day}
 *   quarterly{day,time}           -> time: at[time], day: month_days{days:[day]}, month: months{1,4,7,10}
 *   yearly{month,day,time}        -> time: at[time], day: month_days{days:[day]}, month: months{month}
 *   every_n_months{n,day,time}    -> time: at[time], day: month_days{days:[day]},
 *                                    month: every_n_months{n, from:1, to:12}  (preserves the legacy
 *                                    January-anchored grid exactly via the explicit 1..12 window)
 *   nth_weekday_of_month{ordinal,weekday,time}  -> time: at[time], day: special{nth_weekday,ordinal,weekday}
 *   last_weekday_of_month{weekday,time}         -> time: at[time], day: special{last_weekday,weekday}
 *   last_working_day_of_month{time}             -> time: at[time], day: special{last_working_day}
 */
class LegacyScheduleUpgrader
{
    /**
     * Return the v2 form of $schedule. A block WITHOUT a `family` key is already v2 (or empty) and
     * is returned unchanged; a legacy block is mapped by family; an unknown family is returned as-is
     * so v2 validation reports it honestly.
     *
     * @param  array<string, mixed>  $schedule
     * @return array<string, mixed>
     */
    public function toV2(array $schedule): array
    {
        if (!array_key_exists('family', $schedule)) {
            return $schedule;
        }

        $family = (string) $schedule['family'];
        $params = is_array($schedule['params'] ?? null) ? $schedule['params'] : [];
        $times = $this->atTimes($schedule, $params);

        $v2 = $this->axesFor($family, $params, $times);

        if ($v2 === null) {
            return $schedule; // unmappable family — let v2 validation reject it
        }

        return $this->withCarriedKeys($v2, $schedule);
    }

    /**
     * The { time, day, month } axes for a legacy family, or null when the family is unknown.
     *
     * @param  array<string, mixed>  $params
     * @param  array<int, string>  $times  the wall-clock fire times for an `at` family (from times[] or params.time)
     * @return array<string, mixed>|null
     */
    private function axesFor(string $family, array $params, array $times): ?array
    {
        return match ($family) {
            'every_n_minutes' => ['time' => ['mode' => 'every_minutes', 'minutes' => $this->int($params, 'n', 1)]],
            'hourly' => ['time' => ['mode' => 'every_hours', 'hours' => 1, 'minute' => 0]],
            'hourly_at' => ['time' => ['mode' => 'every_hours', 'hours' => 1, 'minute' => $this->int($params, 'minute', 0)]],
            'every_n_hours' => ['time' => ['mode' => 'every_hours', 'hours' => $this->int($params, 'n', 2), 'minute' => $this->int($params, 'minute', 0)]],
            'daily' => ['time' => $this->atTime($times)],
            'twice_daily' => ['time' => ['mode' => 'at', 'at' => $this->twiceDailyTimes($params)]],
            'weekly' => ['time' => $this->atTime($times), 'day' => ['mode' => 'weekdays', 'weekdays' => $this->weekdays($params)]],
            'monthly' => ['time' => $this->atTime($times), 'day' => $this->monthDays([$this->int($params, 'day', 1)])],
            'twice_monthly' => ['time' => $this->atTime($times), 'day' => $this->monthDays([$this->int($params, 'first_day', 1), $this->int($params, 'second_day', 1)])],
            'last_day_of_month' => ['time' => $this->atTime($times), 'day' => ['mode' => 'special', 'special' => 'last_day']],
            'quarterly' => ['time' => $this->atTime($times), 'day' => $this->monthDays([$this->int($params, 'day', 1)]), 'month' => ['mode' => 'months', 'months' => [1, 4, 7, 10]]],
            'yearly' => ['time' => $this->atTime($times), 'day' => $this->monthDays([$this->int($params, 'day', 1)]), 'month' => ['mode' => 'months', 'months' => [$this->int($params, 'month', 1)]]],
            'every_n_months' => ['time' => $this->atTime($times), 'day' => $this->monthDays([$this->int($params, 'day', 1)]), 'month' => ['mode' => 'every_n_months', 'n' => $this->int($params, 'n', 2), 'from' => 1, 'to' => 12]],
            'nth_weekday_of_month' => ['time' => $this->atTime($times), 'day' => ['mode' => 'special', 'special' => 'nth_weekday', 'ordinal' => $this->int($params, 'ordinal', 1), 'weekday' => $this->int($params, 'weekday', 0)]],
            'last_weekday_of_month' => ['time' => $this->atTime($times), 'day' => ['mode' => 'special', 'special' => 'last_weekday', 'weekday' => $this->int($params, 'weekday', 0)]],
            'last_working_day_of_month' => ['time' => $this->atTime($times), 'day' => ['mode' => 'special', 'special' => 'last_working_day']],
            default => null,
        };
    }

    /**
     * Carry the shape-identical `tz` and `exclusions` through to the v2 block verbatim.
     *
     * @param  array<string, mixed>  $v2
     * @param  array<string, mixed>  $legacy
     * @return array<string, mixed>
     */
    private function withCarriedKeys(array $v2, array $legacy): array
    {
        foreach (['tz', 'exclusions'] as $key) {
            if (array_key_exists($key, $legacy)) {
                $v2[$key] = $legacy[$key];
            }
        }

        return $v2;
    }

    /**
     * The wall-clock fire times for an `at` family: the legacy `times[]` list wins, else the single
     * `params.time` as a one-element list. Empty when neither is present (an unmappable time; v2
     * validation then reports the missing at-list).
     *
     * @param  array<string, mixed>  $schedule
     * @param  array<string, mixed>  $params
     * @return array<int, string>
     */
    private function atTimes(array $schedule, array $params): array
    {
        $times = $schedule['times'] ?? null;

        if (is_array($times) && $times !== []) {
            return array_values(array_map('strval', $times));
        }

        $time = $params['time'] ?? null;

        return is_string($time) && $time !== '' ? [$time] : [];
    }

    /**
     * A time.mode=at axis from a list of HH:mm strings.
     *
     * @param  array<int, string>  $times
     * @return array{mode: string, at: array<int, string>}
     */
    private function atTime(array $times): array
    {
        return ['mode' => 'at', 'at' => $times];
    }

    /**
     * twice_daily's two hours as HH:mm at the shared minute (default :00).
     *
     * @param  array<string, mixed>  $params
     * @return array<int, string>
     */
    private function twiceDailyTimes(array $params): array
    {
        $minute = $this->int($params, 'minute', 0);

        return [
            sprintf('%02d:%02d', $this->int($params, 'first_hour', 0), $minute),
            sprintf('%02d:%02d', $this->int($params, 'second_hour', 0), $minute),
        ];
    }

    /**
     * A day.mode=weekdays axis. Reads the multi-day `weekdays` list, tolerating a legacy scalar
     * `weekday` as a one-element list (Bot-module read tolerance).
     *
     * @param  array<string, mixed>  $params
     * @return array<int, int>
     */
    private function weekdays(array $params): array
    {
        $raw = $params['weekdays'] ?? null;

        if (!is_array($raw)) {
            $raw = array_key_exists('weekday', $params) ? [$params['weekday']] : [];
        }

        return array_values(array_map('intval', $raw));
    }

    /**
     * A day.mode=month_days axis from a list of calendar days.
     *
     * @param  array<int, int>  $days
     * @return array{mode: string, days: array<int, int>}
     */
    private function monthDays(array $days): array
    {
        return ['mode' => 'month_days', 'days' => array_values($days)];
    }

    /**
     * An integer param with a default (legacy params were already validated numeric before storage).
     *
     * @param  array<string, mixed>  $params
     */
    private function int(array $params, string $key, int $default): int
    {
        $value = $params[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }
}
