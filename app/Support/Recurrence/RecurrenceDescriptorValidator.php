<?php

namespace App\Support\Recurrence;

use App\Support\Recurrence\Enums\RecurrenceViolationCode;
use App\Support\Recurrence\Enums\ScheduleDayMode;
use App\Support\Recurrence\Enums\ScheduleDaySpecial;
use App\Support\Recurrence\Enums\ScheduleLimits;
use App\Support\Recurrence\Enums\ScheduleMonthMode;
use App\Support\Recurrence\Enums\ScheduleTimeMode;
use Throwable;

/**
 * WHETHER A DESCRIPTOR IS WELL-FORMED — the one answer, for every module that accepts one.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE DECISION THIS CLASS RECORDS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * B1 moved the cadence GRAMMAR down into this layer (the compiler, the engine, the enums, the limits)
 * and left the VALIDATOR up in the automations module. That split could not survive contact with a
 * second consumer: a module that may not name the first one could compile a descriptor but not judge
 * one, so it would have had to write a second set of rules — two definitions of "valid", drifting
 * from the day the second one was written, which is the exact failure the extraction was performed to
 * prevent.
 *
 * The split that DOES survive is not "who owns validation" but WHAT VALIDATION IS MADE OF:
 *
 *   THE RULES ARE SHARED — which mode owns which keys, what each mode requires, how a window pairs
 *   and orders, which combinations are contradictory, and whether the cadence can ever fire. That is
 *   grammar. It lives here, and it answers in CODES.
 *
 *   THE PROSE IS NOT — the message a user reads is written per screen, in that user's language, in
 *   that module's vocabulary. Sharing it would have forced this layer to pick a language (the app is
 *   PL+EN switchable) and forced every consumer to inherit a message written about a different
 *   subject. A module accepting a NARROW SUBSET of the grammar also needs its errors to talk about
 *   that subset, not about axes its screen has never heard of.
 *
 *   THE PER-KEY TYPES AND RANGES ARE NOT EITHER, and this is the deliberate limit of the decision.
 *   "weekday is an integer 0..6", "at is HH:mm", "the list has no duplicates" are expressed by each
 *   consumer in its framework's own validation vocabulary, because that is what produces per-key
 *   error paths a form can attach to a field. What stops THAT drifting is that every bound is read
 *   from the shared ScheduleLimits, so the FACTS are still stated once even though the spelling is
 *   per module. Re-implementing those checks here as well would create a second live implementation
 *   of the same rule — the very thing this class exists to avoid — with only one of the two rendered
 *   on any given path.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT THIS CLASS DOES NOT CHECK — the list, not the category
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * "Types and ranges stay with the consumer" is the principle; it is not a specification, and a
 * consumer that reads only the principle will miss three checks that are neither a type nor a range.
 * A descriptor that passes everything here can still be unusable, so EVERY consumer must also assert,
 * in its own rules:
 *
 *   1. THE TIME AXIS IS REQUIRED. The day and month axes are optional and default to
 *      every_day/every_month; `time` does not default to anything. Nothing below reports its
 *      absence — an absent axis has no mode, and an axis with no mode is skipped by design.
 *   2. `tz` MUST BE A REAL ZONE. An unknown identifier is not rejected here; the engine falls back to
 *      the app timezone, so a typo becomes a schedule that fires in the wrong zone in silence.
 *   3. THE EXCLUSION LISTS MUST NOT BE ABLE TO EXCLUDE EVERYTHING. The structural caps
 *      (ScheduleLimits::EXCLUSIONS_MONTHS_MAX = 11, EXCLUSIONS_WEEKDAYS_MAX = 6) are what stop a
 *      descriptor ruling out every month or every weekday by construction.
 *
 * A consumer that skips all three is FAIL-CLOSED, not unsafe: {@see unreachable()} still refuses a
 * descriptor that can never fire. But it refuses it with ONE sentence, pointed at `exclusions`, when
 * the real defect was a missing time axis or a garbage zone — which is a bad error, not a bad
 * outcome. The three are listed here rather than left to be rediscovered by trial.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT A CONSUMER MUST DO
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *   1. hand a RESOLVED v2 descriptor to {@see violations()} — this class does NOT upgrade a legacy
 *      `{ family, params }` block, because upgrading changes which keys exist and a consumer that
 *      validates raw input must decide for itself whether it accepts the old vocabulary at all
 *      (LegacyScheduleUpgrader is right there when it does);
 *   2. render every returned code into its own translated message, at its own prefix + the
 *      violation's relative path;
 *   3. call {@see unreachable()} LAST and only when nothing else failed — it costs a real projection,
 *      and stacking "this never fires" on top of a structural error describes a descriptor nobody
 *      wrote.
 *
 * PURE, like everything else in this layer: no framework, no models, no clock beyond the projection
 * {@see unreachable()} asks the engine for.
 */
class RecurrenceDescriptorValidator
{
    /** The exclusion block's entire vocabulary. Anything else is a typo, never a silent no-op. */
    private const EXCLUSION_KEYS = ['months', 'weekdays', 'dates'];

    public function __construct(private ScheduleEngine $engine = new ScheduleEngine) {}

    /**
     * Every SHAPE violation in a resolved v2 descriptor, in a stable order (time axis, day axis,
     * month axis, exclusions). An empty list means the descriptor's shape is sound — it says nothing
     * about the per-key types and ranges the consumer checks, and nothing about reachability.
     *
     * An axis whose `mode` is missing or unknown is SKIPPED rather than reported field by field: with
     * no mode there is no shape to check against, and a consumer's own enum rule has already said the
     * mode is the problem. Reporting the mode's absence twice — once as an unknown enum, once as a
     * pile of "required for this mode" — describes a descriptor the user did not write.
     *
     * @param  array<string, mixed>  $descriptor  the resolved v2 `{ time, day?, month?, tz?, exclusions? }` block
     * @return array<int, RecurrenceViolation>
     */
    public function violations(array $descriptor): array
    {
        return [
            ...$this->timeViolations($descriptor),
            ...$this->dayViolations($descriptor),
            ...$this->monthViolations($descriptor),
            ...$this->exclusionViolations($descriptor),
        ];
    }

    /**
     * Whether the cadence can EVER fire, as a violation list ([] when it can).
     *
     * Separate from {@see violations()} because it is the one check that costs a projection rather
     * than a look at the array, and because it is only meaningful once the shape is sound: an
     * over-constrained descriptor (weekly-on-Monday that also excludes Mondays) is structurally
     * perfect and still unfireable, while a structurally broken one has nothing to project.
     *
     * Reported on `exclusions` — the key that is almost always the cause, and the only one a user can
     * relax without abandoning the cadence they asked for.
     *
     * @param  array<string, mixed>  $descriptor
     * @return array<int, RecurrenceViolation>
     */
    public function unreachable(array $descriptor): array
    {
        try {
            $occurrences = $this->engine->nextOccurrences($descriptor, 1);
        } catch (Throwable) {
            // A descriptor the engine cannot even project is unusable for the same practical reason,
            // and the consumer's own structural rules are what name the actual defect.
            $occurrences = [];
        }

        return $occurrences === []
            ? [new RecurrenceViolation('exclusions', RecurrenceViolationCode::NO_OCCURRENCE)]
            : [];
    }

    // ---- TIME axis ------------------------------------------------------------

    /**
     * The time axis: the field its mode requires, the window it may carry, and the rejection of any
     * key foreign to that mode.
     *
     * @param  array<string, mixed>  $descriptor
     * @return array<int, RecurrenceViolation>
     */
    private function timeViolations(array $descriptor): array
    {
        $time = $descriptor['time'] ?? null;

        if (!is_array($time)) {
            return []; // absence/shape of the axis itself is the consumer's required/array rule
        }

        $mode = ScheduleTimeMode::tryFrom((string) ($time['mode'] ?? ''));

        if ($mode === null) {
            return [];
        }

        $violations = match ($mode) {
            ScheduleTimeMode::AT => $this->requireList($time, 'at', 'time'),
            ScheduleTimeMode::EVERY_MINUTES => [
                ...$this->requireNumeric($time, 'minutes', 'time'),
                ...$this->hhmmWindowViolations($time),
            ],
            ScheduleTimeMode::EVERY_HOURS => [
                ...$this->requireNumeric($time, 'hours', 'time'),
                ...$this->hourWindowViolations($time),
            ],
        };

        return [...$violations, ...$this->foreignKeys($time, $mode->allowedKeys(), 'time')];
    }

    /**
     * The optional HH:mm window of an every_minutes time: both-or-neither, each a valid wall-clock
     * time, and ascending (a window never wraps midnight).
     *
     * @param  array<string, mixed>  $time
     * @return array<int, RecurrenceViolation>
     */
    private function hhmmWindowViolations(array $time): array
    {
        $pairing = $this->windowPairing($time, 'time');

        if ($pairing !== null) {
            return $pairing;
        }

        if (!$this->hasBothBounds($time)) {
            return [];
        }

        $violations = [];
        $fromOk = $this->isHhmm($time['from'] ?? null);
        $toOk = $this->isHhmm($time['to'] ?? null);

        if (!$fromOk) {
            $violations[] = new RecurrenceViolation('time.from', RecurrenceViolationCode::WINDOW_START_NOT_TIME);
        }

        if (!$toOk) {
            $violations[] = new RecurrenceViolation('time.to', RecurrenceViolationCode::WINDOW_END_NOT_TIME);
        }

        if ($fromOk && $toOk && $this->minutesOfDay((string) $time['from']) >= $this->minutesOfDay((string) $time['to'])) {
            $violations[] = new RecurrenceViolation('time.to', RecurrenceViolationCode::WINDOW_TIMES_NOT_ASCENDING);
        }

        return $violations;
    }

    /**
     * The optional 0..23 hour window of an every_hours time: both-or-neither, integers in range,
     * ascending.
     *
     * @param  array<string, mixed>  $time
     * @return array<int, RecurrenceViolation>
     */
    private function hourWindowViolations(array $time): array
    {
        $pairing = $this->windowPairing($time, 'time');

        if ($pairing !== null) {
            return $pairing;
        }

        if (!$this->hasBothBounds($time)) {
            return [];
        }

        $violations = [];
        $fromOk = $this->isHour($time['from'] ?? null);
        $toOk = $this->isHour($time['to'] ?? null);

        if (!$fromOk) {
            $violations[] = new RecurrenceViolation('time.from', RecurrenceViolationCode::WINDOW_START_NOT_HOUR);
        }

        if (!$toOk) {
            $violations[] = new RecurrenceViolation('time.to', RecurrenceViolationCode::WINDOW_END_NOT_HOUR);
        }

        if ($fromOk && $toOk && (int) $time['from'] >= (int) $time['to']) {
            $violations[] = new RecurrenceViolation('time.to', RecurrenceViolationCode::WINDOW_HOURS_NOT_ASCENDING);
        }

        return $violations;
    }

    // ---- DAY axis -------------------------------------------------------------

    /**
     * The day axis. Optional: absent or empty means every_day, which has nothing to check.
     *
     * @param  array<string, mixed>  $descriptor
     * @return array<int, RecurrenceViolation>
     */
    private function dayViolations(array $descriptor): array
    {
        $day = $descriptor['day'] ?? null;

        if (!is_array($day) || $day === []) {
            return [];
        }

        $mode = ScheduleDayMode::tryFrom((string) ($day['mode'] ?? ''));

        if ($mode === null) {
            return $this->requireMode($day, 'day');
        }

        if ($mode === ScheduleDayMode::SPECIAL) {
            // The special rule owns its own foreign-key check: which params are legal depends on the
            // RULE, not on the mode, so the outer check would reject them all.
            return $this->specialDayViolations($day, $descriptor);
        }

        $violations = match ($mode) {
            ScheduleDayMode::EVERY_DAY => [],
            ScheduleDayMode::EVERY_N_DAYS => $this->everyNViolations($day, 'day'),
            ScheduleDayMode::WEEKDAYS => $this->requireList($day, 'weekdays', 'day'),
            ScheduleDayMode::MONTH_DAYS => $this->requireList($day, 'days', 'day'),
            ScheduleDayMode::SPECIAL => [],
        };

        return [...$violations, ...$this->foreignKeys($day, $mode->allowedKeys(), 'day')];
    }

    /**
     * The `special` day rule: the rule itself, the params that rule needs, the time-axis restriction
     * it imposes, and the keys it allows.
     *
     * @param  array<string, mixed>  $day
     * @param  array<string, mixed>  $descriptor
     * @return array<int, RecurrenceViolation>
     */
    private function specialDayViolations(array $day, array $descriptor): array
    {
        $special = ScheduleDaySpecial::tryFrom((string) ($day['special'] ?? ''));

        if ($special === null) {
            // A PRESENT but unknown rule was already reported by the consumer's enum rule; an ABSENT
            // one has nothing else to report it.
            return array_key_exists('special', $day)
                ? []
                : [new RecurrenceViolation('day.special', RecurrenceViolationCode::SPECIAL_REQUIRED)];
        }

        $violations = [];
        $context = ['special' => $special->value];

        if ($special->needsOrdinal() && !is_numeric($day['ordinal'] ?? null)) {
            $violations[] = new RecurrenceViolation('day.ordinal', RecurrenceViolationCode::SPECIAL_NEEDS_ORDINAL, $context);
        }

        if ($special->needsWeekday() && !is_numeric($day['weekday'] ?? null)) {
            $violations[] = new RecurrenceViolation('day.weekday', RecurrenceViolationCode::SPECIAL_NEEDS_WEEKDAY, $context);
        }

        // The bespoke last-working-day cadence exists only at explicit fire times — reported on the
        // TIME axis, where the contradiction has to be resolved.
        if ($special->requiresAtTime() && ($descriptor['time']['mode'] ?? null) !== ScheduleTimeMode::AT->value) {
            $violations[] = new RecurrenceViolation('time.mode', RecurrenceViolationCode::SPECIAL_REQUIRES_AT_TIME, $context);
        }

        return [
            ...$violations,
            ...$this->foreignKeys($day, ['mode', 'special', ...$special->allowedParams()], 'day'),
        ];
    }

    // ---- MONTH axis -----------------------------------------------------------

    /**
     * The month axis. Optional: absent or empty means every_month.
     *
     * @param  array<string, mixed>  $descriptor
     * @return array<int, RecurrenceViolation>
     */
    private function monthViolations(array $descriptor): array
    {
        $month = $descriptor['month'] ?? null;

        if (!is_array($month) || $month === []) {
            return [];
        }

        $mode = ScheduleMonthMode::tryFrom((string) ($month['mode'] ?? ''));

        if ($mode === null) {
            return $this->requireMode($month, 'month');
        }

        $violations = match ($mode) {
            ScheduleMonthMode::EVERY_MONTH => [],
            ScheduleMonthMode::EVERY_N_MONTHS => $this->everyNViolations($month, 'month'),
            ScheduleMonthMode::MONTHS => $this->requireList($month, 'months', 'month'),
        };

        return [...$violations, ...$this->foreignKeys($month, $mode->allowedKeys(), 'month')];
    }

    // ---- EXCLUSIONS -----------------------------------------------------------

    /**
     * An `exclusions` key outside the block's vocabulary. A descriptor-driven consumer gets told,
     * rather than having its filter silently ignored.
     *
     * @param  array<string, mixed>  $descriptor
     * @return array<int, RecurrenceViolation>
     */
    private function exclusionViolations(array $descriptor): array
    {
        $exclusions = $descriptor['exclusions'] ?? null;

        if (!is_array($exclusions)) {
            return [];
        }

        $violations = [];

        foreach (array_keys($exclusions) as $name) {
            if (!in_array((string) $name, self::EXCLUSION_KEYS, true)) {
                $violations[] = new RecurrenceViolation(
                    'exclusions.' . $name,
                    RecurrenceViolationCode::EXCLUSION_KEY_NOT_ALLOWED,
                    ['field' => (string) $name],
                );
            }
        }

        return $violations;
    }

    // ---- shared field checks --------------------------------------------------

    /**
     * An every_n_* axis: the required step and the optional ascending window. The bounds themselves
     * are the consumer's per-key rules; what lives here is the pairing and the ordering.
     *
     * @param  array<string, mixed>  $block
     * @return array<int, RecurrenceViolation>
     */
    private function everyNViolations(array $block, string $axis): array
    {
        $violations = $this->requireNumeric($block, 'n', $axis);

        $pairing = $this->windowPairing($block, $axis);

        if ($pairing !== null) {
            return [...$violations, ...$pairing];
        }

        if (!$this->hasBothBounds($block)) {
            return $violations;
        }

        $from = $block['from'] ?? null;
        $to = $block['to'] ?? null;

        if (is_numeric($from) && is_numeric($to) && (int) $from >= (int) $to) {
            $violations[] = new RecurrenceViolation($axis . '.to', RecurrenceViolationCode::WINDOW_BOUNDS_NOT_ASCENDING);
        }

        return $violations;
    }

    /**
     * The both-or-neither rule for a window, as a violation list — or NULL when the pairing is fine
     * and the caller should go on to check the bounds themselves. (Null rather than an empty list
     * because "nothing wrong with the pairing" and "no window at all" lead to different next steps.)
     *
     * @param  array<string, mixed>  $block
     * @return array<int, RecurrenceViolation>|null
     */
    private function windowPairing(array $block, string $axis): ?array
    {
        if ($this->present($block, 'from') === $this->present($block, 'to')) {
            return null;
        }

        return [new RecurrenceViolation($axis . '.to', RecurrenceViolationCode::WINDOW_INCOMPLETE)];
    }

    /**
     * Whether the axis carries a window at all (both bounds present).
     *
     * @param  array<string, mixed>  $block
     */
    private function hasBothBounds(array $block): bool
    {
        return $this->present($block, 'from') && $this->present($block, 'to');
    }

    /**
     * A numeric scalar the chosen mode requires (minutes/hours/n).
     *
     * @param  array<string, mixed>  $block
     * @return array<int, RecurrenceViolation>
     */
    private function requireNumeric(array $block, string $key, string $axis): array
    {
        if (is_numeric($block[$key] ?? null)) {
            return [];
        }

        return [new RecurrenceViolation(
            $axis . '.' . $key,
            RecurrenceViolationCode::MODE_FIELD_REQUIRED,
            ['field' => $key],
        )];
    }

    /**
     * A non-empty list the chosen mode requires (time.at, day.weekdays, day.days, month.months). The
     * element rules and bounds are the consumer's.
     *
     * @param  array<string, mixed>  $block
     * @return array<int, RecurrenceViolation>
     */
    private function requireList(array $block, string $key, string $axis): array
    {
        $value = $block[$key] ?? null;

        if (is_array($value) && $value !== []) {
            return [];
        }

        return [new RecurrenceViolation(
            $axis . '.' . $key,
            RecurrenceViolationCode::MODE_LIST_REQUIRED,
            ['field' => $key],
        )];
    }

    /**
     * A present day/month axis with no `mode` key at all. A present-but-unknown mode is the
     * consumer's enum rule to report.
     *
     * @param  array<string, mixed>  $block
     * @return array<int, RecurrenceViolation>
     */
    private function requireMode(array $block, string $axis): array
    {
        return array_key_exists('mode', $block)
            ? []
            : [new RecurrenceViolation($axis . '.mode', RecurrenceViolationCode::MODE_REQUIRED)];
    }

    /**
     * Keys the chosen mode does not own. Rejected rather than ignored: a caller that sent `minutes`
     * on an `at` time asked for something it is not going to get, and a silent drop is how a
     * descriptor comes to mean something nobody wrote.
     *
     * @param  array<string, mixed>  $block
     * @param  array<int, string>  $allowed
     * @return array<int, RecurrenceViolation>
     */
    private function foreignKeys(array $block, array $allowed, string $axis): array
    {
        $violations = [];

        foreach (array_keys($block) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                $violations[] = new RecurrenceViolation(
                    $axis . '.' . $key,
                    RecurrenceViolationCode::FIELD_NOT_ALLOWED_FOR_MODE,
                    ['field' => (string) $key],
                );
            }
        }

        return $violations;
    }

    // ---- primitive predicates -------------------------------------------------

    /**
     * Whether a block carries a usable value for $key (present, not null, not blank).
     *
     * @param  array<string, mixed>  $block
     */
    private function present(array $block, string $key): bool
    {
        return array_key_exists($key, $block) && $block[$key] !== null && $block[$key] !== '';
    }

    /** Whether a value is a well-formed 'HH:mm' wall-clock time. */
    private function isHhmm(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    /** Whether a value is an integer hour 0..23. */
    private function isHour(mixed $value): bool
    {
        return is_numeric($value)
            && (int) $value >= ScheduleLimits::HOUR_MIN
            && (int) $value <= ScheduleLimits::HOUR_MAX;
    }

    /** Minutes-of-day for an 'HH:mm' string, for window ordering. */
    private function minutesOfDay(string $time): int
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return (int) $hour * 60 + (int) $minute;
    }
}
