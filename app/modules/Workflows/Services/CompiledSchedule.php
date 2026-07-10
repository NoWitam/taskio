<?php

namespace App\Modules\Workflows\Services;

/**
 * The compiled form of a validated `trigger_config.schedule` block. Three kinds:
 *   - a BESPOKE interval (every_n_minutes — a pure "from + N minutes" cadence, no wall-clock grid),
 *   - a CRON cadence: a NON-EMPTY LIST of cron expressions (one per fire time). A single-time
 *     schedule is a one-element list; a `times[]` schedule is one expression per time (all sharing
 *     the same date fields, differing only in minute/hour). WorkflowScheduleService takes the
 *     earliest strictly-after candidate across the list.
 *   - a BESPOKE last-working-day cadence (last_working_day_of_month — the last Mon-Fri of the
 *     month at one or MORE HH:mm times; see note below on why this is NOT a cron expression).
 * WorkflowScheduleService branches on `kind` to pick the right nextDueAt path.
 * WorkflowScheduleCompiler is the only producer.
 *
 *   interval:         { kind: 'interval',          minutes: int }        — next = from + minutes.
 *   cron:             { kind: 'cron',              expressions: str[],   — next = earliest strictly-
 *                                                  expression: str }       after run date across the
 *                                                  expressions. `expression` is the FIRST (back-compat).
 *   last_working_day: { kind: 'last_working_day',  times: [{hour,minute}], — next = earliest last
 *                                                  hour, minute: int }      Mon-Fri of the month at any
 *                                                  listed HH:mm, resolved in the schedule tz. `hour`/
 *                                                  `minute` are the FIRST time (back-compat).
 *
 * BACK-COMPAT: `expression` (first cron string) and `hour`/`minute` (first LWD time) keep the
 * pre-times single-value shape used by existing callers and pins; the list fields carry the full
 * `times[]` set. For a single-time schedule the list is one element and the scalars equal it.
 *
 * WHY last_working_day IS NOT A CRON EXPRESSION: the dragonmantank/cron-expression `LW` token does
 * NOT mean "last working day of the month" — it is parsed as "nearest weekday to day 0" (the `L` in
 * `LW` casts to int 0), which normalises to the previous month and returns wrong/garbage dates
 * (verified against v3.6.0). "Last working day" also cannot be expressed as any single standard cron
 * expression (it is the latest of {last Mon, …, last Fri}). So it is computed deterministically in
 * the service instead of delegated to the cron lib — no hack around a broken token, no new package.
 */
readonly class CompiledSchedule
{
    /**
     * @param  array<int, string>|null  $expressions  cron expressions (kind=cron), earliest wins
     * @param  array<int, array{hour: int, minute: int}>|null  $times  HH:mm fire times (kind=last_working_day)
     */
    private function __construct(
        public string $kind,
        public ?int $minutes = null,
        public ?string $expression = null,
        public ?int $hour = null,
        public ?int $minute = null,
        public ?array $expressions = null,
        public ?array $times = null,
    ) {}

    /** A bespoke interval cadence: next fire is $from + $minutes (phase from arm time). */
    public static function interval(int $minutes): self
    {
        return new self('interval', minutes: $minutes);
    }

    /**
     * A cron cadence from ONE expression (the single-time case). Kept for callers/tests that build
     * a scalar-time schedule; internally stored as a one-element expression list too.
     */
    public static function cron(string $expression): self
    {
        return new self('cron', expression: $expression, expressions: [$expression]);
    }

    /**
     * A cron cadence from a LIST of expressions (the `times[]` case) — one expression per fire time,
     * all sharing the same date fields. The service returns the earliest strictly-after candidate;
     * `expression` mirrors the first for back-compat.
     *
     * @param  array<int, string>  $expressions
     */
    public static function cronList(array $expressions): self
    {
        $expressions = array_values($expressions);

        return new self('cron', expression: $expressions[0] ?? null, expressions: $expressions);
    }

    /**
     * A bespoke last-working-day cadence at ONE HH:mm (the single-time case): next fire is the last
     * Mon-Fri of the month at $hour:$minute, resolved in the schedule tz (see class note on why this
     * cannot be a cron expression).
     */
    public static function lastWorkingDay(int $hour, int $minute): self
    {
        return new self('last_working_day', hour: $hour, minute: $minute, times: [['hour' => $hour, 'minute' => $minute]]);
    }

    /**
     * A bespoke last-working-day cadence at a LIST of HH:mm times (the `times[]` case): the earliest
     * strictly-after "last Mon-Fri of the month at HH:mm" across the times wins. `hour`/`minute`
     * mirror the first time for back-compat.
     *
     * @param  array<int, array{hour: int, minute: int}>  $times
     */
    public static function lastWorkingDayList(array $times): self
    {
        $times = array_values($times);

        return new self(
            'last_working_day',
            hour: $times[0]['hour'] ?? null,
            minute: $times[0]['minute'] ?? null,
            times: $times,
        );
    }

    public function isInterval(): bool
    {
        return $this->kind === 'interval';
    }

    public function isCron(): bool
    {
        return $this->kind === 'cron';
    }

    public function isLastWorkingDay(): bool
    {
        return $this->kind === 'last_working_day';
    }
}
