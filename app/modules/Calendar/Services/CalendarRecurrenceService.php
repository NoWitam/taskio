<?php

namespace App\Modules\Calendar\Services;

use App\Modules\Calendar\DTOs\CalendarRecurrence;
use App\Modules\Calendar\Enums\CalendarEventScope;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Support\Recurrence\Enums\RecurrenceViolationCode;
use App\Support\Recurrence\Enums\ScheduleLimits;
use App\Support\Recurrence\Enums\ScheduleTimeMode;
use App\Support\Recurrence\RecurrenceDescriptorValidator;
use App\Support\Recurrence\RecurrenceViolation;
use App\Support\Recurrence\ScheduleEngine;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * EVERYTHING THE CALENDAR KNOWS ABOUT REPEATING — the stamping, the judging, and the arithmetic.
 * No writes: {@see CalendarEventService} is still the only writer of the table.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT "STAMPING" MEANS AND WHY IT IS NOT A DETAIL
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A stored recurrence is a FULL v2 descriptor, but only part of it came from the caller. Two of its
 * keys are written here, at save time, and are never taken from the wire:
 *
 *   `time`  the series' single hour, read off the event's OWN anchor. A caller cannot send one, so a
 *           rule whose hour disagrees with the event's start is unrepresentable rather than merely
 *           refused. For an all-day series there is no hour to read, so the shared layer's day anchor
 *           ({@see ScheduleEngine::DAY_ANCHOR}) is used — the same hour its own day projection uses,
 *           chosen because it is the one wall-clock hour that exists exactly once in every timezone.
 *   `tz`    the workspace's timezone, as it stands AT THE MOMENT OF THE WRITE.
 *
 * The second is the one worth arguing. Deriving the zone on every read would be less code and would be
 * wrong: a single timed event stores an ABSOLUTE INSTANT, so changing the workspace timezone already
 * moves that event's wall-clock hour on the grid. Stamping gives a series identical behaviour — its
 * occurrences move with the zone the same way its anchor does. A re-derived zone would instead pin the
 * wall-clock hour and move the instants, so a series and the single events it stands for would drift
 * apart on a change nobody connected to either.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE ANCHOR MUST SATISFY THE RULE — one engine call, and it replaces the emptiness check
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * {@see anchorIsFirstOccurrence()} asks the engine for the occurrence AT-OR-BEFORE the anchor and
 * demands it be the anchor itself. That single call is what makes "start" mean FIRST OCCURRENCE rather
 * than "the day we start counting from", and it is what makes splitting a series at an occurrence
 * correct by construction.
 *
 * THE SHARED LAYER'S `RecurrenceDescriptorValidator::unreachable()` IS DELIBERATELY NOT CALLED, which
 * is a departure from its stated recipe and is therefore written down rather than left to be
 * discovered. `unreachable()` projects forward from NOW: it answers "does this cadence fire again",
 * which is the right question for an automation and the wrong one for a calendar, where a series that
 * ran through last spring is an ordinary thing to record and to edit.
 *
 * WHAT REPLACES IT IS TWO CHECKS, NOT ONE, and the first was briefly mistaken for both. The anchor
 * check answers a question about the CADENCE — it strips exclusions, so it proves the rule fires at the
 * anchor and nothing more. It does NOT prove the SERIES has an occurrence, because the series is the
 * cadence minus its exclusions, bounded by its own end date: weekly-on-Mondays anchored on the 7th,
 * excluding the 7th, ending on the 10th passes the anchor check and falls on no day at all.
 * {@see hasOccurrenceBetween()} closes that, against the FULL descriptor and inside the series' own
 * bounds, and it is what the write path calls last.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * CODES IN, TRANSLATED CALENDAR PROSE OUT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The shared layer answers in {@see RecurrenceViolationCode} and refuses to write sentences, because
 * the app is PL+EN switchable and because the next consumer's screen is about a different subject.
 * {@see message()} is this module's renderer: a `match` with NO default arm, so a code added to the
 * grammar makes this expression THROW at the point of use instead of reaching a user as a blank error
 * next to a control that simply refuses to save. Asserted exhaustive by test.
 *
 * Several codes cannot arise here at all — the window codes describe modes the Calendar does not
 * accept, `special_requires_at_time` describes a restriction this module satisfies by construction,
 * and `no_occurrence` is never asked for. They are rendered anyway, into one honest sentence, because
 * the alternative is a default arm and a default arm is how an unrendered code becomes invisible.
 */
class CalendarRecurrenceService
{
    public function __construct(
        private CalendarTimezoneResolver $timezone,
        private ScheduleEngine $engine = new ScheduleEngine,
        private RecurrenceDescriptorValidator $validator = new RecurrenceDescriptorValidator,
    ) {}

    /**
     * The instant a series is anchored at: the event's own start, expressed as an absolute moment.
     *
     * An all-day event has no moment, so it is given the shared layer's day anchor ON THE CLOCK THE
     * WRITE IS STAMPING — the same hour {@see ScheduleEngine::occurrenceDaysBetween()} projects a
     * day-shaped cadence at, so the days that come back are the days the anchor names. That is normally
     * the workspace's current zone; an edit that KEEPS a series' existing stamp passes it in, and must,
     * or the noon it builds would be somebody else's noon and would not print back as `12:00`.
     *
     * @param  string|null  $timezone  the clock to build the day anchor on; null means the workspace's
     */
    public function anchorInstant(bool $allDay, ?string $startDate, ?CarbonImmutable $startsAt, ?string $timezone = null): ?CarbonImmutable
    {
        if (!$allDay) {
            return $startsAt;
        }

        if ($startDate === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) !== 1) {
            return null;
        }

        return CarbonImmutable::parse($startDate . ' ' . ScheduleEngine::DAY_ANCHOR, $timezone ?? $this->timezone->resolve());
    }

    /**
     * A caller's cadence, STAMPED with the event's own hour and the workspace's zone — as a plain
     * descriptor array, deliberately not yet as a {@see CalendarRecurrence}.
     *
     * The order matters and is the reason these are two methods. The value object refuses anything
     * outside the Calendar's subset by THROWING, which is the right behaviour for a type and the wrong
     * one for a validation pass: a `day` block with no `mode` has to come back as a per-field 422
     * ({@see grammarViolations()} reports it as `mode_required` on `day.mode`), not as an exception the
     * request would have to translate into a message about a field it can no longer name. So a caller
     * builds the descriptor, judges it, and only then asks for the type.
     *
     * The `day` / `month` blocks keep their SHAPE verbatim — this method does not repair a cadence, add
     * a missing key or drop a foreign one, because their grammar is the shared layer's and their
     * per-key types are the FormRequest's, and silently repairing either would mean the thing that was
     * validated is not the thing that was stored.
     *
     * WHAT IT DOES CANONICALISE IS SCALAR SPELLING: `weekdays`, `days`, `months`, `ordinal` and
     * `weekday` are written as INTEGERS. Laravel's `integer` rule accepts `"1"` as readily as `1`, so
     * without this a rule sent from a form landed in the column as `["1","3"]`, came back out of the
     * resource as strings, and a client comparing with `===` rendered the wrong days — while the engine,
     * which coerces, went on firing correctly. A column that holds two spellings of one fact has two
     * readings, and this module has exactly one other rule of that kind (the all-day discriminator) and
     * spends a great deal of effort making it impossible.
     *
     * @param  array<string, mixed>|null  $day
     * @param  array<string, mixed>|null  $month
     * @param  array<int, string>  $exclusionDates
     * @param  string|null  $timezone  the clock to stamp; null means the workspace's CURRENT one. An
     *                                 edit that does not move the anchor passes the series' EXISTING
     *                                 stamp — see {@see timezoneCarriedBy()}.
     * @return array<string, mixed>
     */
    public function descriptor(?array $day, ?array $month, array $exclusionDates, CarbonImmutable $anchor, ?string $timezone = null): array
    {
        $tz = $timezone ?? $this->timezone->resolve();

        $descriptor = [
            'time' => [
                'mode' => ScheduleTimeMode::AT->value,
                'at' => [$anchor->setTimezone($tz)->format('H:i')],
            ],
        ];

        if ($day !== null && $day !== []) {
            $descriptor['day'] = $this->canonicalise($day, ['weekdays', 'days'], ['ordinal', 'weekday']);
        }

        if ($month !== null && $month !== []) {
            $descriptor['month'] = $this->canonicalise($month, ['months'], []);
        }

        $descriptor['tz'] = $tz;

        $dates = array_values(array_unique($exclusionDates));
        sort($dates);

        if ($dates !== []) {
            $descriptor['exclusions'] = [CalendarRecurrence::EXCLUSION_KEY => $dates];
        }

        return $descriptor;
    }

    /**
     * One axis with its numeric fields written as integers, and everything else untouched.
     *
     * A NON-NUMERIC VALUE IS LEFT EXACTLY AS IT ARRIVED, deliberately: casting it would turn whatever
     * the caller sent into `0` and hand the grammar a value nobody wrote, hiding the error behind a
     * plausible-looking day. The per-key rules have already refused it; this only settles the spelling
     * of values that were going to be accepted anyway.
     *
     * @param  array<string, mixed>  $axis
     * @param  array<int, string>  $lists  keys holding a list of integers
     * @param  array<int, string>  $scalars  keys holding one integer
     * @return array<string, mixed>
     */
    private function canonicalise(array $axis, array $lists, array $scalars): array
    {
        foreach ($lists as $key) {
            if (is_array($axis[$key] ?? null)) {
                $axis[$key] = array_map(
                    static fn (mixed $value): mixed => is_numeric($value) ? (int) $value : $value,
                    $axis[$key],
                );
            }
        }

        foreach ($scalars as $key) {
            if (is_numeric($axis[$key] ?? null)) {
                $axis[$key] = (int) $axis[$key];
            }
        }

        return $axis;
    }

    /**
     * A judged descriptor as the value object, or NULL when it is not one this module can mean.
     *
     * Null rather than an exception because the only caller is a validation pass that has already
     * reported everything it could name; what is left is a shape nobody has a field for, and a 500 on
     * a validation path is never the right answer. {@see fromStored()} degrades the same way for the
     * same reason.
     *
     * `Throwable`, not `InvalidArgumentException`, and the two catches are deliberately the same width:
     * they wrap the identical constructor, so a narrower one here would mean the READ path degraded
     * quietly while the VALIDATION path returned a 500 — the worse of the two, on the more exposed
     * surface, for the same input.
     *
     * @param  array<string, mixed>  $descriptor
     */
    public function stamp(array $descriptor): ?CalendarRecurrence
    {
        try {
            return CalendarRecurrence::stamped($descriptor);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A recurrence read back off a row, or NULL when the row does not repeat — or carries a descriptor
     * this module can no longer mean.
     *
     * FAIL-SOFT on a malformed stored value, for the same reason {@see CalendarTimezoneResolver} is:
     * refusing would take the whole event down for one bad column written by a console fix or a
     * restored dump. Null means "not a series", which is the fail-CLOSED answer for every scoped
     * operation — they all require one.
     *
     * @param  array<string, mixed>|null  $descriptor
     */
    public function fromStored(?array $descriptor, ?string $until): ?CalendarRecurrence
    {
        if ($descriptor === null) {
            return null;
        }

        try {
            return CalendarRecurrence::stamped($descriptor, $until);
        } catch (Throwable $exception) {
            Log::warning('A calendar event carries a recurrence this module cannot read; treating it as a single event.', [
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Every SHAPE violation the shared grammar finds. The per-key types and ranges are the
     * FormRequest's; this is the half no consumer may re-derive.
     *
     * @param  array<string, mixed>  $descriptor  a STAMPED descriptor, not yet a value object
     * @return array<int, RecurrenceViolation>
     */
    public function grammarViolations(array $descriptor): array
    {
        return $this->validator->violations($descriptor);
    }

    /**
     * Whether the event's own start is an occurrence of its own CADENCE — the check that makes "start"
     * mean "first occurrence". See the class docblock for why this replaces the emptiness check rather
     * than accompanying it.
     *
     * THE EXCLUSIONS ARE STRIPPED BEFORE THE ENGINE IS ASKED, and that is a correction to the obvious
     * reading of this rule rather than a loophole in it. Removing a single occurrence is an exclusion,
     * so a user who deletes the FIRST occurrence of a weekly series leaves a row whose anchor day is
     * excluded by its own descriptor. If this check honoured exclusions, that row would be
     * unsaveable — editing the title of a series whose first occurrence you had removed would fail with
     * "the start is not an occurrence", about a start the user never touched.
     *
     * So the layers are kept apart, which is what they already are everywhere else: the CADENCE says
     * which days a series falls on, and the EXCLUSIONS subtract days from that afterwards. The anchor
     * has to satisfy the cadence. It does not have to survive the subtraction.
     */
    public function anchorIsFirstOccurrence(CalendarRecurrence $recurrence, CarbonImmutable $anchor): bool
    {
        $descriptor = $recurrence->descriptor;
        unset($descriptor['exclusions']);

        try {
            $previous = $this->engine->previousOrAtOccurrence($descriptor, $anchor);
        } catch (Throwable) {
            // A descriptor the engine cannot project at all cannot have the anchor among its
            // occurrences, so FALSE is both the fail-closed answer and the true one. Caught rather than
            // allowed to escape because this runs inside a validation pass, where a 500 is never a
            // better answer than a 422 — the same reason the shared layer's own reachability check
            // swallows a throw.
            return false;
        }

        return $previous !== null && $previous->equalTo($anchor);
    }

    /**
     * The day the Nth occurrence falls on — how "repeat N times" becomes an end DATE, once, at write
     * time. NULL when the cadence does not have that many occurrences within the engine's horizon.
     *
     * That null is not paranoia: a cadence can be anchored on a real occurrence and still have its next
     * one decades away (the fifth Monday of February happens in 2016 and then not again until 2044),
     * and the engine gives up at ten years. Silently storing a shorter series would answer a question
     * nobody asked; the caller turns this into a 422 that says so.
     *
     * IT COUNTS SQUARES, NOT FIRINGS, and the distinction is the whole correctness of this method.
     *
     * "Repeat 5 times" is a promise about the GRID: five is what the user asked for and five is what
     * must be drawn. The shared engine, though, fires TWICE on a fall-back day when the series' hour is
     * the one the zone repeats, and the read path collapses those two firings back to ONE square
     * ({@see firstPerDay()} — the rule the whole module rests on). Counted in firings, that day spends
     * TWO of the N and the series ends a day early: `count=5` across Europe/Warsaw 2026-10-25 (02:30),
     * America/Havana 2026-11-01 (00:30) or Australia/Lord_Howe 2026-04-05 (01:45 — a HALF-hour fold, so
     * this is not a property of whole hours) drew four. And it is silent: the computed end lands in
     * `recurrence_until` for good, with nothing anywhere recording that a fifth day was owed.
     *
     * SO THE WALK IS COLLAPSED BY THE SAME RULE THE READ IS, and specifically NOT by the shared layer's
     * day-shaped seam, which is the obvious alternative and is subtly the wrong question. That seam
     * projects at DAY_ANCHOR — noon, which no zone skips — so it answers "which days does this CADENCE
     * fall on". The grid answers "which days does this SERIES draw on", and those two part company at a
     * SPRING-FORWARD transition: a Lord Howe series at 02:15 has no firing at all on 2026-10-04 (the
     * zone jumps 02:00 → 02:30), so the day seam counts a day the grid leaves blank and the series ends
     * a day EARLY again, in the opposite direction. Only the read's own arithmetic can match the read.
     *
     * THE WALK IS INCREMENTAL rather than over-asked, because the shortfall is the exception: it asks
     * for exactly the days still missing, so a series with no transition inside it costs exactly N
     * projections — the figure `config/calendar.php` budgets the `recurrence_count_max` cap against —
     * and only a fold costs an extra one.
     *
     * Exclusions count, exactly as before: the FULL descriptor is projected, so an anchor day the user
     * has already removed is not day number one. That is what makes this count what a viewer SEES.
     */
    public function endDayForCount(CalendarRecurrence $recurrence, CarbonImmutable $anchor, int $count): ?string
    {
        if ($count < 1) {
            return null;
        }

        $timezone = $recurrence->timezone();

        /** @var array<string, true> $days keyed by day, insertion-ordered and therefore ascending */
        $days = [];

        // Seeded ONE MINUTE BEFORE the anchor rather than at it, because the engine's forward projection
        // is strictly-after by contract and the anchor itself must be the first thing counted. A minute
        // cannot admit anything else: the cadence grid is minute-granular by construction.
        $cursor = $anchor->subMinute();

        // A calendar day carries at most TWO firings of one wall-clock hour (a zone folds at most once a
        // day), so N days can never need more than 2N of them. This is a TERMINATOR, not a policy: it is
        // only ever reached by a cadence that has already run out of days to give.
        $budget = $count * 2;

        while (count($days) < $count && $budget > 0) {
            try {
                $occurrences = $this->engine->nextOccurrences(
                    $recurrence->descriptor,
                    min($budget, $count - count($days)),
                    $cursor,
                );
            } catch (Throwable) {
                // MEASURED, NOT DEFENSIVE. The forward walk is the one direction the engine does NOT
                // guard: its backward helper catches the cron library's failure per expression, its
                // forward one does not, and the library raises "Impossible CRON expression" when it
                // exhausts its search — which is exactly what a genuinely sparse cadence does. The fifth
                // Monday of February reaches this line. Treating it as "that many occurrences are not
                // reachable" is the same verdict the short-list branch below gives, and it is the true one.
                return null;
            }

            if ($occurrences === []) {
                break;
            }

            foreach ($occurrences as $occurrence) {
                $budget--;

                $instant = CarbonImmutable::instance($occurrence)->utc();
                $cursor = $instant;

                // Ascending by the engine's contract, so a day seen twice is the fall-back duplicate and
                // the second one costs nothing — exactly as it costs nothing on the grid.
                $days[$instant->setTimezone($timezone)->format('Y-m-d')] = true;
            }
        }

        return count($days) < $count ? null : (string) array_key_last($days);
    }

    /**
     * Whether a series falls on a given calendar day, reckoned on the series' STAMPED clock.
     *
     * Asked through the shared layer's day-shaped seam, so no instant crosses the boundary in either
     * direction and exclusions are honoured — an already-excluded day is correctly not an occurrence,
     * which is what makes "remove this occurrence" refuse the second time rather than silently
     * duplicating an exclusion.
     *
     * KNOWS NOTHING OF THE SERIES' BOUNDS. The anchor and the end date are the Calendar's own limits,
     * not the descriptor's, so a caller asking whether a day belongs to a series must check
     * {@see dayIsWithinSeries()} as well.
     */
    public function isOccurrenceDay(CalendarRecurrence $recurrence, string $day): bool
    {
        try {
            return $this->engine->occurrenceDaysBetween($recurrence->descriptor, $day, $day, 1) === [$day];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The series a stored row carries, or null when it does not repeat. The one way the write and
     * validation paths read a row's rule back, so a malformed column is degraded in exactly one place.
     */
    public function seriesOf(CalendarEvent $event): ?CalendarRecurrence
    {
        return $this->fromStored($event->recurrence, $event->recurrenceUntilString());
    }

    /**
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * THE CLOCK A WRITE MUST KEEP — or NULL when it is free to stamp the workspace's current one
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * The stamped zone rules how a stored series is READ (its cadence, its anchor day, its squares).
     * Until this method existed, the workspace's CURRENT zone ruled how a series was RE-VALIDATED on
     * save — and nothing reconciled the two. After a workspace changed its timezone, saving a series
     * re-stamped the new zone and then checked the anchor on it, so a series whose anchor instant lands
     * on a different weekday under the new offset became UNSAVEABLE: editing only the TITLE, with the
     * rule echoed back verbatim from the read, returned 422 on `starts_at` — a field the user never
     * touched. Both ways out of it lose data (moving the start erases the series' past; picking another
     * preset silently rewrites the cadence), so it was a lock-out, not an inconvenience.
     *
     * THE RULE: A SERIES IS RE-STAMPED ONLY WHEN ITS ANCHOR ACTUALLY MOVES. The stamp records the clock
     * a series was LAID OUT on; changing it as a side effect of an unrelated edit is exactly the
     * surprise. When the write moves the anchor the author IS laying the series out again, and the
     * current clock is then the honest one to stamp.
     *
     * "THE ANCHOR" IS THE ONE THIS WRITE IS ABOUT, which for a `following` split is not the row's own
     * start but the OCCURRENCE the split is made at — the new row continues an existing series from a
     * point that series already answers for, so a split is a CUT, not a re-authoring. Passing that date
     * in is what stops the split path from being the same lock-out one door further along.
     *
     * WHAT COUNTS AS "MOVED" FOLLOWS THE MODULE'S OWN VOCABULARY, and it differs by shape in time
     * because the two shapes are not the same kind of thing:
     *   - a TIMED series is anchored at an INSTANT, so the payload's instant must equal the subject's;
     *   - an ALL-DAY series is anchored on a zone-free DAY, so the payload's day must equal it. Its
     *     instant is noon on whatever clock built it and comparing those would mix two clocks — the
     *     error {@see seriesAnchorDay()} exists to prevent.
     * A payload that FLIPS the discriminator has moved the anchor by definition and never carries.
     *
     * @param  CalendarEvent  $event  the row being edited
     * @param  bool  $allDay  the payload's shape in time
     * @param  string|null  $startDate  the payload's day, for an all-day payload
     * @param  CarbonImmutable|null  $startsAt  the payload's instant, for a timed payload
     * @param  string|null  $occurrenceDate  the occurrence a `following` split is made at, if any
     */
    public function timezoneCarriedBy(
        CalendarEvent $event,
        bool $allDay,
        ?string $startDate,
        ?CarbonImmutable $startsAt,
        ?string $occurrenceDate = null,
    ): ?string {
        $series = $this->seriesOf($event);

        // Nothing to carry: the row does not repeat (or carries a rule this module can no longer read,
        // which `seriesOf` has already logged). A rule being added to a single event is a series being
        // authored for the first time, and it is authored on the clock the workspace keeps today.
        if ($series === null || (bool) $event->all_day !== $allDay) {
            return null;
        }

        if ($allDay) {
            $subjectDay = $occurrenceDate ?? $this->seriesAnchorDay($event);

            return $startDate !== null && $subjectDay !== null && $startDate === $subjectDay
                ? $series->timezone()
                : null;
        }

        $subjectInstant = $occurrenceDate !== null
            ? CarbonImmutable::parse($occurrenceDate . ' ' . $series->hour(), $series->timezone())
            : ($event->starts_at !== null ? CarbonImmutable::instance($event->starts_at) : null);

        return $startsAt !== null && $subjectInstant !== null && $startsAt->equalTo($subjectInstant)
            ? $series->timezone()
            : null;
    }

    /**
     * The day a stored series is anchored on, on its own STAMPED clock — never the workspace's current
     * one, which may have changed since. Null when the row does not repeat.
     *
     * AN ALL-DAY SERIES ANSWERS FROM ITS COLUMN AND NEVER FROM AN INSTANT, and that is not a shortcut.
     * {@see anchorInstant()} builds noon on the workspace's CURRENT clock — correct at write time, when
     * the current clock is the one being stamped, and WRONG here, where the stamped clock may be a
     * different one. Reading that instant back in the stamped zone mixes two clocks: with twelve hours
     * or more between them the printed day FLIPS (a workspace stamped on Asia/Tokyo and later moved to
     * America/Los_Angeles reads its anchor a day early; the reverse reads it a day late).
     *
     * The consequences were not cosmetic. A day LATE made `deleteFollowing` treat the second occurrence
     * as the first and soft-delete the entire event — destroying the occurrence the caller explicitly
     * asked to keep, with no restore endpoint to undo it. A day EARLY made a split close the series
     * before its own anchor, leaving exactly the unreachable ghost row the split is arranged to avoid.
     *
     * A stored all-day date has no zone to convert BETWEEN, which is the whole point of storing it as a
     * date. So it is re-printed, never converted — the same rule the rest of this module follows.
     */
    public function seriesAnchorDay(CalendarEvent $event): ?string
    {
        $series = $this->seriesOf($event);

        if ($series === null) {
            return null;
        }

        if ((bool) $event->all_day) {
            return $event->startDateString();
        }

        $anchor = $event->starts_at !== null ? CarbonImmutable::instance($event->starts_at) : null;

        return $anchor === null ? null : $this->anchorDay($series, $anchor);
    }

    /**
     * How far ahead an OPEN-ENDED series is asked to prove itself. Ten years mirrors the shared engine's
     * own search horizon — beyond which it returns "no occurrence" regardless of what is asked — and it
     * is STATED here rather than read from there because that constant is private to the engine.
     *
     * A cadence with nothing inside a decade is empty for every purpose a calendar has, so treating the
     * two as the same answer costs nothing real. The projection stops at the FIRST hit, so a daily
     * series pays for one day, not for ten years.
     */
    private const OPEN_ENDED_REACH_YEARS = 10;

    /**
     * Whether the series falls on at least one day between two bounds, judged against the FULL
     * descriptor — exclusions included.
     *
     * This is the check the anchor check is not. See the class docblock: the anchor proves the CADENCE
     * fires, and a series is the cadence minus its exclusions inside its own end date. A series with no
     * day at all is not refused by anything else, would draw nothing once the projection lands in B5,
     * and would have been accepted with a 201.
     *
     * ONE projection, capped at one hit, so the cost does not grow with the window.
     *
     * @param  string|null  $until  the series' end, or null for an open-ended one (see the reach above)
     */
    public function hasOccurrenceBetween(CalendarRecurrence $recurrence, string $from, ?string $until): bool
    {
        $to = $until ?? CarbonImmutable::parse($from . ' 12:00', 'UTC')
            ->addYears(self::OPEN_ENDED_REACH_YEARS)
            ->format('Y-m-d');

        if ($to < $from) {
            return false;
        }

        try {
            return $this->engine->occurrenceDaysBetween($recurrence->descriptor, $from, $to, 1) !== [];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * THE PROJECTION THE GRID READS — and why it goes BACKWARDS as well as forwards
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * The two methods below are the whole of R3 B5's arithmetic: given a series and a window, which
     * days (or instants) does it fall on. They differ from the sibling schedule source in ONE rule,
     * and the difference is deliberate enough to be written down where somebody comparing the two
     * will find it.
     *
     * A WORKFLOW SCHEDULE IS PROJECTED FORWARD ONLY. That is correct there: a computed occurrence in
     * the past is a claim about EXECUTION, and the automation may have been deactivated, edited or
     * over its run budget — the scheduler has an explicit doctrine in which a due slot is CONSUMED
     * without running. So a past projection would misstate history on every grid that contains
     * yesterday, which is every grid.
     *
     * AN EVENT HAS NO EXECUTION HISTORY TO MISSTATE. Nothing ever runs because an event exists (the
     * fence on {@see CalendarEvent}), so there is no record of "what actually happened" for a
     * projection to disagree with: the RULE IS THE WHOLE TRUTH. A weekly meeting recorded last spring
     * happened exactly as many times as its rule says, and a user paging back to March expects to see
     * it. Refusing to draw the past here would not be caution — it would delete data the row
     * unambiguously contains.
     *
     * Both walks are therefore bounded by the WINDOW, never by a count, and never by now(). The window
     * bound is not a preference either: asked by COUNT, a yearly rule read in August walks SIXTY-SIX
     * YEARS forward to fill sixty-six slots, and unlike the schedule source there is no stored
     * next-fire column here to pre-filter such a series out. The cap is a fuse against the opposite
     * failure (a dense cadence), which is why the caller asks for one more than it can render.
     */

    /**
     * The DAYS an ALL-DAY series falls on inside a window, ascending `Y-m-d`, reckoned on the series'
     * own stamped clock.
     *
     * DAYS IN, DAYS OUT — no instant crosses this boundary in either direction. An all-day series has
     * no hour to speak of, and the hour the shared layer supplies internally to make its cron grid fire
     * is an implementation detail of that layer; letting it out here is how a zone-free day acquires a
     * timezone and lands on the wrong square.
     *
     * The span is the INTERSECTION of three things: the window, the series' anchor (its first
     * occurrence — nothing before it belongs to the series, even where the bare cadence would fire) and
     * the series' end. Day strings compare lexicographically, which for `Y-m-d` is chronologically, so
     * the intersection is plain string arithmetic on values that have no zone to convert between.
     *
     * @param  string  $anchorDay  the series' first occurrence, on its stamped clock
     * @param  int  $cap  the most days to return; a fuse, not the bound (see above)
     * @return array<int, string>
     */
    public function occurrenceDaysIn(CalendarRecurrence $recurrence, string $anchorDay, string $windowStart, string $windowEnd, int $cap): array
    {
        $from = max($anchorDay, $windowStart);
        $to = $recurrence->until === null ? $windowEnd : min($recurrence->until, $windowEnd);

        if ($cap < 1 || $to < $from) {
            return [];
        }

        try {
            return $this->engine->occurrenceDaysBetween($recurrence->descriptor, $from, $to, $cap);
        } catch (Throwable) {
            // Same verdict as everywhere else on this class's read path: a descriptor the engine cannot
            // project has no occurrences, and a 500 on a grid that is mostly other modules' data is
            // never the better answer. The registry would catch a throw and blank the whole EVENTS
            // source; degrading here costs one series instead.
            return [];
        }
    }

    /**
     * The INSTANTS a TIMED series falls on inside a window, ascending UTC.
     *
     * The span is the same three-way intersection as the day-shaped variant, expressed in instants:
     *   - the window's true UTC edges;
     *   - the ANCHOR, because a rule fires on days before the event it belongs to began (a
     *     weekly-Mondays series starting on the 10th would otherwise draw the 3rd as well);
     *   - the series' END, which is a DAY on the stamped clock and is turned into the exact instant of
     *     its last possible occurrence — that day at the series' own hour. Exact, not approximate,
     *     because a calendar series has exactly ONE wall-clock hour by construction
     *     ({@see CalendarRecurrence}); anything later than that instant is on a later day. Taking
     *     end-of-day instead would have to reason about whether 23:59 exists in the stamped zone.
     *
     * The result is then reduced to ONE INSTANT PER DAY — see {@see firstPerDay()}, which is not
     * tidying but the rule the rest of this module is built on.
     *
     * THE CAP COUNTS DAYS, AND IT IS APPLIED AFTER THE COLLAPSE, which is the only way it can mean what
     * the caller means. A caller asks for ONE MORE than it can render so that an overrun is DETECTABLE
     * rather than looking like a series that happened to end there — the fuse idiom the sibling schedule
     * source uses. Spending the cap on INSTANTS breaks that: a fall-back day contributes two firings and
     * one square, so the extra slot is eaten by a duplicate and a series with more days to give comes
     * back looking exactly complete — no `dense` flag, no truncation report. So the engine is asked for
     * TWICE the days wanted and the cap is applied to what survives the collapse.
     *
     * TWICE IS EXACT, NOT GENEROUS: a wall-clock hour occurs at most twice in one local day (a zone folds
     * at most once a day), so 2n firings always contain n distinct days whenever the series has that many.
     * The doubling is free here — the Calendar's cadence subset has no sub-daily mode, so the densest
     * series it can express contributes one square a day and the fuse is nowhere near being reached at
     * the shipped configuration (see the per-item cap in `config/calendar.php`).
     *
     * @param  int  $cap  the most DAYS to return; a fuse, not the bound
     * @return array<int, CarbonImmutable>
     */
    public function occurrenceInstantsIn(CalendarRecurrence $recurrence, CarbonImmutable $anchor, CarbonImmutable $windowStart, CarbonImmutable $windowEnd, int $cap): array
    {
        $from = $anchor->greaterThan($windowStart) ? $anchor : $windowStart;
        $to = $this->seriesEndInstant($recurrence, $windowEnd);

        if ($cap < 1 || $from->greaterThan($to)) {
            return [];
        }

        try {
            $occurrences = $this->engine->occurrencesBetween($recurrence->descriptor, $from, $to, $cap * 2);
        } catch (Throwable) {
            return [];
        }

        return array_slice($this->firstPerDay($occurrences, $recurrence->timezone()), 0, $cap);
    }

    /**
     * ONE INSTANT PER CALENDAR DAY on the series' stamped clock, keeping the EARLIEST — and this is a
     * correctness rule of this module, not a defensive tidy-up.
     *
     * THE SHARED ENGINE FIRES TWICE ON A DAYLIGHT-SAVING FALL-BACK DAY, deliberately and by pinned
     * test: when the series' wall-clock hour lies inside the hour the zone repeats, "02:30" happens at
     * two distinct UTC instants (Europe/Warsaw, 2026-10-25: 00:30Z and 01:30Z). For an automation that
     * is right — a schedule that says 02:30 really does fire twice that night. For a CALENDAR SERIES it
     * is not, because everything above this layer holds that a DAY names an occurrence: the id the grid
     * keys by is `{event}:{Y-m-d}`, the write surface names an occurrence by `occurrence_date`, and
     * removing one occurrence adds a DATE to the rule's exclusions. Two instants on one day would mint
     * two squares with the SAME id — a duplicate render key — spend two slots of the per-item cap on one
     * day, and leave `occurrence_date` naming a square the user cannot single out.
     *
     * THE DAY-SHAPED SIBLING NEEDS NO SUCH RULE, and the asymmetry is worth naming because it looks
     * like an inconsistency. {@see ScheduleEngine::occurrenceDaysBetween()} projects at the shared
     * layer's DAY_ANCHOR — noon, chosen precisely because it is the one wall-clock hour measured never
     * to be skipped or repeated in any zone. That guarantee is a property of THAT HOUR, and it does not
     * transfer to a path projecting at an hour a user picked.
     *
     * THE COLLAPSE MUST NOT BE PAID FOR OUT OF THE CALLER'S FUSE, and it briefly was: the caller asked
     * the engine for one instant more than it could render in order to LEARN there was more, and a
     * fall-back day handed back a duplicate in that slot. The series then reported itself complete on
     * the one day of the year it was not. {@see occurrenceInstantsIn()} now over-asks and caps AFTER
     * this collapse, so a day removed here is never a day the caller was never told about.
     *
     * @param  array<int, Carbon>  $occurrences  ascending, as the engine returns them
     * @return array<int, CarbonImmutable>
     */
    private function firstPerDay(array $occurrences, string $timezone): array
    {
        $byDay = [];

        foreach ($occurrences as $occurrence) {
            $instant = CarbonImmutable::instance($occurrence)->utc();

            // Ascending by the engine's contract, so the first instant seen for a day IS the earliest
            // one — which is the one a person who wrote "02:30" meant.
            $byDay[$instant->setTimezone($timezone)->format('Y-m-d')] ??= $instant;
        }

        return array_values($byDay);
    }

    /**
     * The last instant a series may place an occurrence on inside a window: its end day at its own
     * hour, or the window's end when it has no end or ends later.
     *
     * ON A FALL-BACK DAY THE END HOUR IS AMBIGUOUS, and the resolution matters: PHP resolves a repeated
     * local time to the LATER offset, so the bound lands on the second pass through that hour and the
     * day's occurrence survives. The opposite resolution would silently drop the final occurrence of a
     * series that ends on such a day. Verified rather than assumed — Europe/Warsaw 2026-10-25 02:30
     * resolves to 01:30Z, and the engine's second firing that day is exactly 01:30Z.
     */
    private function seriesEndInstant(CalendarRecurrence $recurrence, CarbonImmutable $windowEnd): CarbonImmutable
    {
        if ($recurrence->until === null) {
            return $windowEnd;
        }

        $end = CarbonImmutable::parse($recurrence->until . ' ' . $recurrence->hour(), $recurrence->timezone())->utc();

        return $end->lessThan($windowEnd) ? $end : $windowEnd;
    }

    /**
     * WHETHER A SPLIT AT $occurrenceDate WOULD LEAVE ANYTHING BEHIND — the question both the request and
     * the writer branch on, asked once so they cannot answer it differently.
     *
     * Comparing the split point against the anchor DATE is the obvious test and it is not the same
     * question: a series whose first occurrence has since been removed one at a time still has an anchor
     * day before the split, and closing it there leaves a row that draws nothing and can never be
     * reached again. The projection over the outgoing span answers the real question, and answers the
     * anchor-day case for free — that span is inverted, and therefore empty, whenever the split lands on
     * or before the anchor.
     */
    public function splitLeavesSomethingBehind(CalendarEvent $event, CalendarRecurrence $series, string $occurrenceDate): bool
    {
        $anchorDay = $this->seriesAnchorDay($event);

        if ($anchorDay === null) {
            return false;
        }

        return $this->hasOccurrenceBetween($series, $anchorDay, $this->dayBefore($occurrenceDate));
    }

    /** Whether a day falls inside the series' own bounds: at or after its anchor, at or before its end. */
    public function dayIsWithinSeries(CalendarRecurrence $recurrence, string $anchorDay, string $day): bool
    {
        if ($day < $anchorDay) {
            return false;
        }

        return $recurrence->until === null || $day <= $recurrence->until;
    }

    /** The calendar day an anchor instant falls on, on the series' stamped clock. */
    public function anchorDay(CalendarRecurrence $recurrence, CarbonImmutable $anchor): string
    {
        return $anchor->setTimezone($recurrence->timezone())->format('Y-m-d');
    }

    /**
     * The day BEFORE a given one — where a split closes the outgoing series.
     *
     * Plain calendar arithmetic on a zone-free day: the previous day is the previous day in every
     * timezone, and parsing this through a zone would be the conversion the whole module is arranged
     * to avoid.
     */
    public function dayBefore(string $day): string
    {
        return CarbonImmutable::parse($day . ' 12:00', 'UTC')->subDay()->format('Y-m-d');
    }

    /**
     * WHETHER A SCOPED OPERATION MAKES SENSE AGAINST THIS ROW — the whole rule, in one place, because
     * `PUT` and `DELETE` ask exactly the same question and an answer written twice is an answer that
     * drifts.
     *
     * Returned as `[path, message]` pairs rather than added to a bag, so each request reports them
     * under its own keys. In order:
     *
     *   - the DEFAULT scope must not carry an occurrence date. Ignoring a stray one would let a client
     *     believe it had edited a single occurrence while the whole series was rewritten — the same
     *     silence the all-day discriminator is refused for.
     *   - a scoped operation needs a date, and a row that repeats. A single event has no occurrences
     *     to name.
     *   - the date must be a REAL occurrence: on the cadence, not already excluded, at or after the
     *     anchor, and at or before the end. The engine answers the first two; the anchor and the end
     *     are the Calendar's own bounds and it has to check them itself.
     *   - removing one occurrence must fit in the rule's exclusion list. When it does not, the message
     *     says to split the series, because that is the actual remedy: a series with fifty holes in it
     *     is two series.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function scopeViolations(CalendarEvent $event, CalendarEventScope $scope, ?string $occurrenceDate): array
    {
        if (!$scope->needsOccurrenceDate()) {
            return $occurrenceDate === null
                ? []
                : [['occurrence_date', __('calendar.validation.recurrence.occurrence_date_without_scope')]];
        }

        if ($occurrenceDate === null) {
            return [['occurrence_date', __('calendar.validation.recurrence.occurrence_date_required')]];
        }

        $series = $this->seriesOf($event);

        if ($series === null) {
            return [['scope', __('calendar.validation.recurrence.event_does_not_repeat')]];
        }

        $anchorDay = $this->seriesAnchorDay($event);

        if ($anchorDay === null
            || !$this->dayIsWithinSeries($series, $anchorDay, $occurrenceDate)
            || !$this->isOccurrenceDay($series, $occurrenceDate)) {
            return [['occurrence_date', __('calendar.validation.recurrence.not_an_occurrence')]];
        }

        // Only REMOVING an occurrence grows the exclusion list. A split shortens the old series
        // instead, which is why it is the remedy the message names.
        if ($scope === CalendarEventScope::OCCURRENCE
            && count($series->exclusionDates()) >= ScheduleLimits::EXCLUSIONS_DATES_MAX) {
            return [['occurrence_date', __('calendar.validation.recurrence.exclusions_full', [
                'max' => ScheduleLimits::EXCLUSIONS_DATES_MAX,
            ])]];
        }

        return [];
    }

    /**
     * ONE grammar violation as one sentence a person reading a CALENDAR would recognise.
     *
     * A `match` with no default arm, deliberately — see the class docblock. Public because the
     * exhaustiveness test calls it directly: a test that went through the request would only ever reach
     * the codes the Calendar's own subset can produce, which is precisely the set that does NOT need
     * guarding.
     */
    public function message(RecurrenceViolation $violation): string
    {
        $field = $violation->context('field');

        return match ($violation->code) {
            RecurrenceViolationCode::MODE_REQUIRED => __('calendar.validation.recurrence.mode_required'),
            RecurrenceViolationCode::MODE_LIST_REQUIRED => __('calendar.validation.recurrence.list_required', ['field' => $field]),
            RecurrenceViolationCode::FIELD_NOT_ALLOWED_FOR_MODE => __('calendar.validation.recurrence.field_not_allowed', ['field' => $field]),
            RecurrenceViolationCode::SPECIAL_REQUIRED => __('calendar.validation.recurrence.special_required'),
            RecurrenceViolationCode::SPECIAL_NEEDS_ORDINAL => __('calendar.validation.recurrence.special_needs_ordinal'),
            RecurrenceViolationCode::SPECIAL_NEEDS_WEEKDAY => __('calendar.validation.recurrence.special_needs_weekday'),

            // Codes about parts of the grammar the Calendar's subset makes unreachable: the numeric
            // step fields and their windows (every_n_* / every_minutes / every_hours), the
            // last-working-day restriction this module satisfies by construction, exclusion keys it
            // never writes, and the emptiness verdict it never asks for. Rendered rather than
            // defaulted, so a NEW code cannot hide behind them.
            RecurrenceViolationCode::MODE_FIELD_REQUIRED,
            RecurrenceViolationCode::WINDOW_INCOMPLETE,
            RecurrenceViolationCode::WINDOW_START_NOT_TIME,
            RecurrenceViolationCode::WINDOW_END_NOT_TIME,
            RecurrenceViolationCode::WINDOW_TIMES_NOT_ASCENDING,
            RecurrenceViolationCode::WINDOW_START_NOT_HOUR,
            RecurrenceViolationCode::WINDOW_END_NOT_HOUR,
            RecurrenceViolationCode::WINDOW_HOURS_NOT_ASCENDING,
            RecurrenceViolationCode::WINDOW_BOUNDS_NOT_ASCENDING,
            RecurrenceViolationCode::SPECIAL_REQUIRES_AT_TIME,
            RecurrenceViolationCode::EXCLUSION_KEY_NOT_ALLOWED,
            RecurrenceViolationCode::NO_OCCURRENCE => __('calendar.validation.recurrence.unsupported'),
        };
    }
}
