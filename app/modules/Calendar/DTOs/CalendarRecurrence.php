<?php

namespace App\Modules\Calendar\DTOs;

use App\Support\Recurrence\Enums\ScheduleDayMode;
use App\Support\Recurrence\Enums\ScheduleDaySpecial;
use App\Support\Recurrence\Enums\ScheduleLimits;
use App\Support\Recurrence\Enums\ScheduleMonthMode;
use App\Support\Recurrence\Enums\ScheduleTimeMode;
use InvalidArgumentException;

/**
 * A VALIDATED, STAMPED RECURRENCE — the rule a calendar event repeats by, in the shape the row stores.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * ONE GRAMMAR, TWO PROFILES — and this class is the Calendar's profile
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The cadence grammar belongs to the shared recurrence layer and is stated exactly once there. The
 * Calendar accepts a deliberately NARROWER subset of it, and the subset is stated HERE — as three
 * lists of enum cases, never as string literals, so a mode the shared layer renames cannot go on
 * quietly meaning something else in this module.
 *
 * WHAT IS IN:
 *   TIME   exactly one wall-clock hour, and it is not the caller's to choose — see below.
 *   DAY    every day; named weekdays; named days of the month; the month-anchored specials
 *          (last day, the Nth weekday, the last such weekday).
 *   MONTH  every month, or named months.
 *   EXCLUSIONS  dates only.
 *
 * WHAT IS OUT, and none of it is an economy:
 *   SUB-DAILY CADENCES (every N minutes / every N hours) — a five-minute annotation is a load
 *      generator, not a calendar entry: a six-week grid would hold over sixty thousand occurrences of
 *      one event. The Calendar's time axis is a single hour by construction, so the two sub-daily
 *      modes are unreachable from here at all.
 *   MODULO CADENCES (every_n_days / every_n_months) — they compile to a grid that RESETS every month
 *      (and every year), while a person reads "every 3 days" as "three days after the last one". A
 *      mode that means something other than what it says is worse than a mode that is missing.
 *   LAST WORKING DAY — outside an annotation's vocabulary, and trivially addable later.
 *   MONTH / WEEKDAY EXCLUSIONS — every one of them is already expressible as the complementary set on
 *      the day or month axis, and refusing the keys is the strongest possible form of the shared
 *      layer's "exclusions must not be able to rule out a whole dimension" (see FACT 3 below).
 *
 * Widening this subset later is ADDITIVE and safe. Narrowing it after release is not. Hence narrow.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE THREE SHAPE FACTS THE SHARED LAYER DOES NOT CHECK, REPRODUCED STRUCTURALLY
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * ADR-0052 D2 names three facts that `RecurrenceDescriptorValidator` deliberately leaves to every
 * consumer, and warns what happens to a consumer that skips them: it stays fail-closed, but every one
 * of the three surfaces as the SAME message — "this schedule has no occurrences", pointed at
 * `exclusions` — whichever of the three actually went wrong. This class refuses to be that consumer.
 * Each fact is asserted in {@see stamped()}, which is the ONLY way to build one of these:
 *
 *   FACT 1 — THE TIME AXIS IS REQUIRED. Here it is stronger than required: it is SERVER-AUTHORED. A
 *            recurring event's hour is its OWN start's hour (or the shared layer's day anchor, for an
 *            all-day series), so the caller never sends one and cannot send one that disagrees with
 *            the anchor. Asserted anyway — the assertion is what makes "cannot" a fact instead of a
 *            habit of the one call site that exists today.
 *   FACT 2 — THE TIMEZONE MUST BE A REAL ZONE. Stamped from the workspace resolver (which already
 *            falls back to the app timezone for an unusable value) and asserted against the live
 *            tzdata here, because an unknown identifier is not rejected by the engine — it is
 *            silently swapped for the app timezone, and a series that fires in the wrong zone looks
 *            exactly like a series that fires.
 *   FACT 3 — EXCLUSIONS MAY NOT RULE OUT A WHOLE DIMENSION. The shared layer's structural caps
 *            (11 of 12 months, 6 of 7 weekdays) are what stop that; the Calendar does not accept
 *            either list at all, which is the same guarantee at its limit. The `dates` list keeps the
 *            shared cap, read from the shared constant.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE SAME TECHNIQUE {@see CalendarEventDTO} USES, FOR THE SAME REASON
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Private constructor, one named constructor, assertions rather than trust. The FormRequest guards ONE
 * caller and answers it with a 422; this guards the TYPE, so a second writer — a step, a console fix,
 * a future importer — cannot reach the row with a rule the module does not mean. An invalid recurrence
 * is not merely rejected, it is unrepresentable.
 */
final readonly class CalendarRecurrence
{
    /**
     * The DAY-axis modes the Calendar accepts. Enum cases, not strings: a rename in the shared layer
     * has to come through here.
     *
     * @return array<int, string>
     */
    public static function dayModes(): array
    {
        return array_map(fn (ScheduleDayMode $mode): string => $mode->value, [
            ScheduleDayMode::EVERY_DAY,
            ScheduleDayMode::WEEKDAYS,
            ScheduleDayMode::MONTH_DAYS,
            ScheduleDayMode::SPECIAL,
        ]);
    }

    /**
     * The `special` day rules the Calendar accepts — every month-anchored one EXCEPT the bespoke
     * last-working-day cadence.
     *
     * @return array<int, string>
     */
    public static function daySpecials(): array
    {
        return array_map(fn (ScheduleDaySpecial $special): string => $special->value, [
            ScheduleDaySpecial::LAST_DAY,
            ScheduleDaySpecial::NTH_WEEKDAY,
            ScheduleDaySpecial::LAST_WEEKDAY,
        ]);
    }

    /**
     * The MONTH-axis modes the Calendar accepts.
     *
     * @return array<int, string>
     */
    public static function monthModes(): array
    {
        return array_map(fn (ScheduleMonthMode $mode): string => $mode->value, [
            ScheduleMonthMode::EVERY_MONTH,
            ScheduleMonthMode::MONTHS,
        ]);
    }

    /** The one exclusion list the Calendar accepts. See FACT 3 in the class docblock. */
    public const EXCLUSION_KEY = 'dates';

    private function __construct(
        /**
         * The full v2 descriptor as stored and as handed to the shared engine: a server-authored
         * `time` and `tz`, plus whichever of `day` / `month` / `exclusions` the caller asked for.
         *
         * @var array<string, mixed>
         */
        public array $descriptor,
        /** The last calendar DAY the series may place an occurrence on ('Y-m-d'), or null for no end. */
        public ?string $until,
    ) {}

    /**
     * The one way to build a recurrence: a descriptor that has already been STAMPED (time + zone) by
     * {@see \App\Modules\Calendar\Services\CalendarRecurrenceService}, checked here against everything
     * that must be true of it regardless of which door it came through.
     *
     * @param  array<string, mixed>  $descriptor
     *
     * @throws InvalidArgumentException when the descriptor is not one the Calendar can mean
     */
    public static function stamped(array $descriptor, ?string $until = null): self
    {
        self::assertTimeAxis($descriptor);
        self::assertTimezone($descriptor);
        self::assertDayAxis($descriptor);
        self::assertMonthAxis($descriptor);
        self::assertExclusions($descriptor);

        if ($until !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) !== 1) {
            throw new InvalidArgumentException("A recurrence end needs a plain Y-m-d day, got [{$until}].");
        }

        return new self($descriptor, $until);
    }

    /** The zone the series was stamped with — the clock its rule is read on, for good. */
    public function timezone(): string
    {
        return (string) $this->descriptor['tz'];
    }

    /** The series' single wall-clock hour, 'HH:mm', in {@see timezone()}. */
    public function hour(): string
    {
        return (string) $this->descriptor['time']['at'][0];
    }

    /**
     * The days this series does NOT fall on, ascending and distinct.
     *
     * @return array<int, string>
     */
    public function exclusionDates(): array
    {
        /** @var array<int, string> $dates */
        $dates = $this->descriptor['exclusions'][self::EXCLUSION_KEY] ?? [];

        return $dates;
    }

    /**
     * The same series with one more day excluded — how a single occurrence is REMOVED.
     *
     * Deleting one occurrence of a series is an exclusion, and an exclusion removes a whole DAY rather
     * than a moment. The two are the same thing here only because a Calendar series has exactly one
     * hour, which is an independent argument for keeping the subset narrow: the day the grammar grows a
     * second fire time per day, this method stops being able to mean what it says.
     *
     * Idempotent — excluding an already-excluded day is a no-op rather than a duplicate entry, so the
     * shared cap counts DAYS and not clicks.
     */
    public function excluding(string $date): self
    {
        $dates = $this->exclusionDates();

        if (in_array($date, $dates, true)) {
            return $this;
        }

        $dates[] = $date;
        sort($dates);

        $descriptor = $this->descriptor;
        $descriptor['exclusions'] = [self::EXCLUSION_KEY => $dates];

        return new self($descriptor, $this->until);
    }

    /** The same series, ending on $until (or never, for null). How a series is CLOSED by a split. */
    public function endingOn(?string $until): self
    {
        return new self($this->descriptor, $until);
    }

    // ---- the assertions ------------------------------------------------------

    /**
     * FACT 1. Exactly one wall-clock hour, expressed through the shared layer's `at` mode. Not
     * "present" — exactly one, because everything downstream (the fixed duration, the day-identifies-
     * an-occurrence rule, the exclusion that removes a day) rests on there being a single one.
     *
     * @param  array<string, mixed>  $descriptor
     */
    private static function assertTimeAxis(array $descriptor): void
    {
        $time = $descriptor['time'] ?? null;

        if (!is_array($time) || ($time['mode'] ?? null) !== ScheduleTimeMode::AT->value) {
            throw new InvalidArgumentException(
                'A calendar recurrence must carry the time axis the Calendar stamps on it '
                . '(mode ' . ScheduleTimeMode::AT->value . '). The hour is the EVENT\'s own hour and is '
                . 'never taken from a caller.'
            );
        }

        $at = $time['at'] ?? null;

        if (!is_array($at) || count($at) !== 1 || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($at[0] ?? '')) !== 1) {
            throw new InvalidArgumentException(
                'A calendar recurrence has exactly ONE wall-clock hour, as HH:mm. A series with two '
                . 'fire times a day would break the rule that a DAY identifies an occurrence.'
            );
        }
    }

    /**
     * FACT 2. A real IANA zone, checked against the live tzdata rather than assumed — the engine does
     * not reject an unknown identifier, it silently substitutes the application timezone.
     *
     * @param  array<string, mixed>  $descriptor
     */
    private static function assertTimezone(array $descriptor): void
    {
        $tz = $descriptor['tz'] ?? null;

        if (!is_string($tz) || !in_array($tz, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException(
                'A calendar recurrence must be STAMPED with a real timezone. An unknown one is not '
                . 'refused downstream — it is quietly replaced by the application timezone, and a series '
                . 'firing in the wrong zone looks exactly like a series firing.'
            );
        }
    }

    /**
     * The day axis, if present, in the Calendar's own subset. Optional: an absent axis means every day,
     * which is the shared layer's own default and needs no key.
     *
     * @param  array<string, mixed>  $descriptor
     */
    private static function assertDayAxis(array $descriptor): void
    {
        $day = $descriptor['day'] ?? null;

        if ($day === null) {
            return;
        }

        if (!is_array($day) || !in_array((string) ($day['mode'] ?? ''), self::dayModes(), true)) {
            throw new InvalidArgumentException('The Calendar does not accept this day cadence — see CalendarRecurrence for the subset and why it is narrow.');
        }

        if (($day['mode'] ?? null) === ScheduleDayMode::SPECIAL->value
            && !in_array((string) ($day['special'] ?? ''), self::daySpecials(), true)) {
            throw new InvalidArgumentException('The Calendar does not accept this special day rule.');
        }
    }

    /**
     * The month axis, if present, in the Calendar's own subset.
     *
     * @param  array<string, mixed>  $descriptor
     */
    private static function assertMonthAxis(array $descriptor): void
    {
        $month = $descriptor['month'] ?? null;

        if ($month === null) {
            return;
        }

        if (!is_array($month) || !in_array((string) ($month['mode'] ?? ''), self::monthModes(), true)) {
            throw new InvalidArgumentException('The Calendar does not accept this month cadence.');
        }
    }

    /**
     * FACT 3. Dates, and only dates, within the shared cap. The month and weekday lists are not capped
     * here because they are not ACCEPTED here — a dimension that cannot be named cannot be excluded.
     *
     * @param  array<string, mixed>  $descriptor
     */
    private static function assertExclusions(array $descriptor): void
    {
        $exclusions = $descriptor['exclusions'] ?? null;

        if ($exclusions === null) {
            return;
        }

        if (!is_array($exclusions) || array_keys($exclusions) !== [self::EXCLUSION_KEY]) {
            throw new InvalidArgumentException(
                'A calendar recurrence excludes DATES and nothing else. A month or weekday exclusion is '
                . 'the complementary set on the month or day axis, and saying it the other way round is '
                . 'how a rule comes to exclude every value it has.'
            );
        }

        $dates = $exclusions[self::EXCLUSION_KEY];

        if (!is_array($dates) || count($dates) > ScheduleLimits::EXCLUSIONS_DATES_MAX) {
            throw new InvalidArgumentException(
                'A calendar recurrence may exclude at most ' . ScheduleLimits::EXCLUSIONS_DATES_MAX . ' dates.'
            );
        }

        foreach ($dates as $date) {
            if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new InvalidArgumentException('A recurrence exclusion is a plain Y-m-d day.');
            }
        }
    }
}
