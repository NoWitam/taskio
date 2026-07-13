<?php

namespace App\Modules\Workflows\Services;

/**
 * The compiled form of a validated v2 `trigger_config.schedule` block. Two kinds:
 *   - a CRON cadence: a NON-EMPTY LIST of cron expressions (the union of every fire moment the
 *     { time, day, month } descriptor implies — one expression per fire time, or the ≤3-expression
 *     union of a minute window). WorkflowScheduleService takes the earliest strictly-after candidate
 *     across the list.
 *   - a BESPOKE last-working-day cadence (day.special = last_working_day): the last Mon-Fri of the
 *     month at one or MORE HH:mm times (from time.at), RESTRICTED to the months the month axis
 *     allows. It is NOT cron — see note below — so the service resolves it directly.
 * WorkflowScheduleService branches on `kind` to pick the right nextDueAt path.
 * WorkflowScheduleCompiler is the only producer.
 *
 *   cron:             { kind: 'cron', expressions: str[] }              — earliest strictly-after
 *                                                                         across the expressions.
 *   last_working_day: { kind: 'last_working_day',                       — earliest last Mon-Fri of an
 *                       times: [{hour,minute}], months: int[] }           ALLOWED month at any listed
 *                                                                         HH:mm, resolved in the tz.
 *
 * WHY last_working_day IS NOT A CRON EXPRESSION: the dragonmantank/cron-expression `LW` token does
 * NOT mean "last working day of the month" — it is parsed as "nearest weekday to day 0" (the `L` in
 * `LW` casts to int 0), which normalises to the previous month and returns wrong/garbage dates
 * (verified against v3.6.0). "Last working day" also cannot be expressed as any single standard cron
 * expression (it is the latest of {last Mon, …, last Fri}). So it is computed deterministically in
 * the service instead of delegated to the cron lib — no hack around a broken token, no new package.
 *
 * NO INTERVAL KIND: every minute/hour cadence is now a WALL-CLOCK cron grid (a stepped minute grid,
 * or `m` on an every-n-hours grid), so there is no phase-from-activation interval to model. A
 * schedule preview is therefore always exact (WorkflowScheduleService::isApproximate is always false).
 */
readonly class CompiledSchedule
{
    /**
     * @param  array<int, string>|null  $expressions  cron expressions (kind=cron), earliest wins
     * @param  array<int, array{hour: int, minute: int}>|null  $times  HH:mm fire times (kind=last_working_day)
     * @param  array<int, int>|null  $months  the months (1..12) the last-working-day cadence may fire in
     */
    private function __construct(
        public string $kind,
        public ?array $expressions = null,
        public ?array $times = null,
        public ?array $months = null,
    ) {}

    /**
     * A cron cadence from a LIST of expressions — the union of every fire moment the descriptor
     * implies (one per time.at entry, or the ≤3-expression union of a minute window). The service
     * returns the earliest strictly-after candidate across the list.
     *
     * @param  array<int, string>  $expressions
     */
    public static function cronList(array $expressions): self
    {
        return new self('cron', expressions: array_values($expressions));
    }

    /**
     * A bespoke last-working-day cadence at a LIST of HH:mm times (from time.at), firing only in the
     * ALLOWED months (from the month axis; every month when unrestricted). The earliest strictly-after
     * "last Mon-Fri of an allowed month at HH:mm" wins.
     *
     * @param  array<int, array{hour: int, minute: int}>  $times
     * @param  array<int, int>  $months  the months (1..12) it may fire in
     */
    public static function lastWorkingDay(array $times, array $months): self
    {
        return new self('last_working_day', times: array_values($times), months: array_values($months));
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
