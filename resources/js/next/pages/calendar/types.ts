// Calendar (R3) domain types for the isolated "next" frontend.
//
// These MIRROR the VERIFIED backend contract 1:1 — read from the code, not from prose:
//   • CalendarOccurrenceResource        → CalendarOccurrence
//   • CalendarOccurrenceController meta → CalendarMeta
//   • CalendarEventResource             → CalendarEvent
//   • StoreCalendarEventRequest         → CalendarEventPayload
//
// NOTHING here is invented. One field the UI would like and the contract does NOT have is
// deliberately absent rather than faked (see docs/next/calendar-uxui-spec.md §23):
//   • a label for an event's `subject` pointer — gap L5. The Calendar never dereferences
//     a morph alias, so the client cannot either.
//
// THE TIME DISCRIMINATOR. Read `all_day` FIRST. All three time keys are always present
// (the house shape-stable convention) but exactly one group is populated:
//   all_day = true  → `start_date` is a plain calendar day 'Y-m-d'. It has NO zone: never
//                     parse it as an instant, never convert it. That is precisely how a
//                     deadline lands on the wrong square.
//   all_day = false → `starts_at` (and possibly `ends_at`) are ISO-8601 UTC instants,
//                     rendered in `meta.timezone` — never in the browser's zone.
//
// Self-contained: NO import from the legacy `resources/js/`.
import type { Creator } from '../../ui/patterns/creator';

/** A plain calendar day, `yyyy-mm-dd`. No zone, ever. */
export type IsoDay = string;

/**
 * The closed `CalendarColor` vocabulary. A source picks a MEANING; the design system
 * picks the pixels (calendarMeta.ts).
 *
 * IT IS A DICTIONARY OF MEANINGS, NOT A PALETTE, AND NOBODY PICKS FROM IT BY HAND. A task
 * deadline is coloured by its PRIORITY, a workflow run by its RESULT, a schedule by the
 * fixed colour that says "this is a projection, not a fact" — and an event by one constant
 * the server assigns. The event drawer used to offer these six values as a free choice,
 * which made red mean "urgent", "failed" and nothing at all in the same grid; that choice
 * is gone (see `CalendarEventPayload`). Only the READING of a colour lives here now.
 */
export type CalendarColor = 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info';

/** The vocabulary, enumerated — every value must resolve to real token classes (§16.1). */
export const CALENDAR_COLORS: CalendarColor[] = [
  'neutral',
  'primary',
  'success',
  'warning',
  'danger',
  'info',
];

/**
 * A ready, SERVER-TRANSLATED badge. `label` is finished prose in the reader's language,
 * produced by the module that owns the vocabulary — the client renders it VERBATIM and
 * never looks it up in a catalog. That is the single mechanism keeping "a fifth source
 * needs no frontend change" true.
 */
export interface CalendarBadge {
  label: string;
  color: CalendarColor;
}

/**
 * A morph ALIAS + id. The Calendar deliberately does NOT dereference it, so neither may
 * we: everything sayable about the subject is already on the occurrence.
 * Known aliases today: `task`, `workflow`, `workflow_run`, `calendar_event`. An unknown
 * one is expected (R4 Publishing) and must degrade, never throw.
 */
export interface CalendarSubject {
  type: string;
  id: string;
}

/** ONE thing on the grid, from any source (`CalendarOccurrenceResource`). */
export interface CalendarOccurrence {
  /** Stable across refetches (`task:{uuid}`, `workflow_schedule:{uuid}:{iso}`). The v-for key. */
  id: string;
  /** The source id — drives the filter, the icon and the legend. NEVER hardcode the set. */
  source: string;
  /** Affordance HINT only. The truth about permissions is the event's own `can_*` flags. */
  editable: boolean;
  /** THE DISCRIMINATOR. Read first. */
  all_day: boolean;
  /** `Y-m-d`, set iff `all_day`. Never converted. */
  start_date: IsoDay | null;
  /** ISO-8601 UTC, set iff `!all_day`. Rendered in `meta.timezone`. */
  starts_at: string | null;
  /** ISO-8601 UTC, or null (still running / no known end). */
  ends_at: string | null;
  title: string;
  color: CalendarColor;
  badge: CalendarBadge | null;
  /** "There is more of this than you can see here." */
  dense: boolean;
  /**
   * HOW OFTEN the subject repeats — "Every 5 min", "Every 2 h, 09:00–17:00" — as finished,
   * SERVER-TRANSLATED prose, or null when the source has no interval to state.
   *
   * Same doctrine as `badge.label`, for the same reason: rendered VERBATIM, never looked up
   * in the client catalog and never re-worded here. A coded cadence would need a client-side
   * vocabulary per source, and the next source to grow one would need a frontend change —
   * which is precisely the promise this screen exists to keep.
   *
   * OPTIONAL AND OFTEN NULL, AND THAT IS ORDINARY. The server labels only the interval modes
   * ("daily at 09:00" would be a lie for a schedule whose day axis is `month_days: [1]`), so
   * most subjects have none. ABSENT AND `null` ARE THE SAME CASE — the key is always on the
   * wire, but no consumer may branch on which of the two it got.
   */
  cadence_label: string | null;
  /**
   * WHETHER THIS SQUARE WAS COMPUTED FROM A REPEATING RULE — a fact about what the square IS,
   * never a permission. `true` for every event-series occurrence and every schedule
   * projection, `false` for a task deadline, a workflow run and a one-off event.
   *
   * THIS, AND NEVER `cadence_label !== null`, IS THE SERIES TEST. The prose is SUFFICIENT
   * evidence of a series but not NECESSARY: a schedule in fixed-times mode repeats and has
   * nothing to say about its cadence, so it carries `null`. A client branching on the prose
   * would call every one of those squares a one-off — silently, and with no way to notice.
   *
   * Always present, from every source; a source with no notion of it leaves it `false`.
   */
  recurring: boolean;
  /**
   * WHICH occurrence of its series this is — the plain day the write surface addresses it by,
   * reckoned on the SERIES' own stamped clock (`CalendarEvent.recurrence_timezone`), never
   * the workspace's current one.
   *
   * NEVER DERIVED HERE, AND NEVER PARSED OUT OF `id`. It is computed by the same expression
   * the occurrence `id` uses, so the name a client sends back as `occurrence_date` cannot
   * drift from the key the grid renders by.
   *
   * `null` for a one-off event AND for every schedule projection — the same null for two
   * unrelated reasons (nothing addresses one firing of a schedule). The combination that
   * actually means "open the scope dialog" is `editable && recurring`, and that combination
   * always carries a non-null value here.
   */
  occurrence_date: IsoDay | null;
  subject: CalendarSubject;
}

/**
 * The three structurally different kinds of loss. Closed and owned by the Calendar (a
 * source cannot add one), which is why the UI can word exactly three cases and still
 * need no per-source knowledge.
 */
export type CalendarTruncationKind = 'window_trimmed' | 'item_densified' | 'items_dropped';

/**
 * One loss report, at most one per `(source, kind)`.
 *
 * BOTH COUNTS MAY BE `null`, AND `null` MEANS UNKNOWN — never zero. The merge rule is
 * deliberately poisoning (`CalendarQueryService::addOrUnknown`): an unknown addend makes
 * the sum unknown, because reporting the known part as the whole would be a smaller lie
 * but still a lie. `count ?? 0` is therefore FORBIDDEN in every consumer — an unknown
 * count selects a DIFFERENT i18n key, never a different number.
 */
export interface CalendarTruncation {
  source: string;
  kind: CalendarTruncationKind;
  omitted_occurrences: number | null;
  affected_items: number | null;
}

/**
 * One entry of the source CATALOGUE. `label` is server prose (a source's own module
 * translates it; an unconstructible source falls back to its raw id) — rendered
 * literally, never translated or prettified client-side.
 */
export interface CalendarSourceInfo {
  id: string;
  label: string;
}

/**
 * WHY a source could not answer — a closed vocabulary owned by the Calendar
 * (`CalendarUnavailableReason`), exactly like `CalendarTruncationKind`.
 *
 * IT IS A CODE RATHER THAN PROSE BECAUSE THE CLIENT HAS TO BRANCH ON IT. The axis that
 * matters to a reader is the only one this answers: is trying again worth anything?
 *   `failed`          — the source was asked and THREW. A bad minute. Retrying may work.
 *   `not_constructed` — it never came up (configuration, a broken boot). Identical next time.
 *   `malformed`       — it answered with something that is not a calendar answer. A defect;
 *                       retrying reproduces it exactly.
 *
 * A bare list of ids could not say which of those a user was looking at, so the retry button
 * had to be offered to everyone or to no one — a control that does nothing, or a fix nobody
 * is told about. See `isRetryableUnavailability` for the one place that reads the axis.
 */
export type CalendarUnavailableReason = 'not_constructed' | 'failed' | 'malformed';

/**
 * One "this source could not answer" report — shaped like `CalendarTruncation` (a `source`
 * plus a code) so the two partial-answer channels read the same way.
 */
export interface CalendarUnavailableSource {
  source: string;
  reason: CalendarUnavailableReason;
}

/** `meta` of `GET /calendar/occurrences`. */
export interface CalendarMeta {
  /** IANA zone every day boundary in this response was reckoned in. The ONLY truth about "today". */
  timezone: string;
  /** Roll-up: whether to render the loss section AT ALL. Never a source of message text. */
  truncated: boolean;
  truncations: CalendarTruncation[];
  /**
   * EVERY registered source, independent of the `sources` filter — the list does not
   * shrink to the selection, or switching a chip off would delete the chip.
   */
  sources: CalendarSourceInfo[];
  /**
   * Sources asked that could not answer, each with WHY. Always present (empty array != absent
   * key), and empty in the ordinary case.
   *
   * NOT a list of ids: the reason is here to be BRANCHED ON. See `CalendarUnavailableReason`.
   */
  unavailable_sources: CalendarUnavailableSource[];
}

/** `GET /calendar/occurrences` envelope. */
export interface CalendarOccurrencesResponse {
  data: CalendarOccurrence[];
  meta: CalendarMeta;
}

/**
 * Query params for `GET /calendar/occurrences` (`CalendarWindowRequest`).
 * There is NO `tz` param — the server resolves the workspace zone and the client gets no
 * vote. There is NO pagination. An UNKNOWN source id is a 422, not a silent drop.
 */
export interface CalendarWindowQuery {
  /** `Y-m-d`, required. A calendar day in the workspace zone. */
  from: IsoDay;
  /** `Y-m-d`, required, `after_or_equal:from`, inclusive. Window ≤ 62 days. */
  to: IsoDay;
  /** Repeated `sources[]=` params. Omit for ALL sources. */
  sources?: string[];
  /** Free text, ≤255. Each source decides what "matches" means. */
  q?: string;
}

/**
 * HOW MUCH OF A SERIES a `PUT`/`DELETE` touches (`CalendarEventScope`).
 *
 * ABSENT ON THE WIRE MEANS `series`, and that default is a COMPATIBILITY GUARANTEE, not a
 * convenience: a request naming no scope behaves byte for byte as it did before series
 * existed. Which is exactly why the client must name its scope EXPLICITLY rather than let
 * `undefined` decide — "I forgot" and "rewrite the whole series, history included" must
 * never be the same request.
 *
 * `occurrence` DETACHES: the day joins `exclusions` and a new, non-repeating event is created
 * from the payload (201, a different id). `following` SPLITS: the old series closes the day
 * before, a new one starts there (201) — unless nothing would be left behind, in which case
 * the server collapses it to `series` (200). Both `prohibited` on `POST`.
 */
export type CalendarEventScope = 'series' | 'occurrence' | 'following';

/**
 * The DAY axis of a recurrence rule. An absent axis means "every day".
 * `weekdays` is `0..6` with **0 = Sunday** — the shared engine's convention, which is also
 * Carbon's and cron's, and NOT the ISO one.
 */
export interface CalendarRecurrenceDayAxis {
  mode: 'every_day' | 'weekdays' | 'month_days' | 'special';
  weekdays?: number[];
  days?: number[];
  special?: 'last_day' | 'nth_weekday' | 'last_weekday';
  ordinal?: number;
  weekday?: number;
}

/** The MONTH axis. An absent axis means "every month". */
export interface CalendarRecurrenceMonthAxis {
  mode: 'every_month' | 'months';
  months?: number[];
}

/**
 * The days a series skips. `dates` is the ONE exclusion list the Calendar accepts — a month
 * or weekday exclusion is refused (422) because it is already expressible as the complementary
 * set on the corresponding axis, and said the other way round it can rule out a whole dimension.
 */
export interface CalendarRecurrenceExclusions {
  dates?: IsoDay[] | null;
}

/**
 * A rule AS READ (`CalendarEventResource.recurrence`). Round-trips verbatim to the write
 * endpoint — which is why `recurrence_label` deliberately sits OUTSIDE it: growing this block
 * with a key the endpoint does not accept would break every read-edit-write loop.
 *
 * THERE IS NO `count` HERE, AND THAT IS THE CONTRACT. A count is walked to a real day once, at
 * write time; the row has no column for it and this resource never returns one (gap L11).
 * There is no `time`/`tz` either: the hour is the event's own and the zone is the workspace's,
 * both server-authored, both `prohibited` on the way in.
 */
export interface CalendarRecurrenceRule {
  day: CalendarRecurrenceDayAxis | null;
  month: CalendarRecurrenceMonthAxis | null;
  exclusions: CalendarRecurrenceExclusions | null;
  until: IsoDay | null;
}

/**
 * A rule AS WRITTEN. Same axes, plus the one key that exists only on the way out.
 *
 * `until` and `count` are MUTUALLY EXCLUSIVE (422 `end_is_one_thing`) — send one, never both.
 * `count` is a way of SAYING a date: the server resolves it and only `until` ever comes back.
 */
export interface CalendarRecurrenceWrite {
  day?: CalendarRecurrenceDayAxis | null;
  month?: CalendarRecurrenceMonthAxis | null;
  exclusions?: CalendarRecurrenceExclusions | null;
  until?: IsoDay | null;
  count?: number | null;
}

/**
 * ONE calendar event (`CalendarEventResource`) — the shape the drawer reads and writes back.
 *
 * NOTE the asymmetry with an occurrence's `subject`, which is intentional: on the GRID an
 * event's subject is the EVENT ITSELF; HERE `subject` is what the event REFERS TO (an
 * optional pointer). Two different questions, neither renamed to look like the other.
 */
export interface CalendarEvent {
  id: string;
  title: string;
  /** Plain text (there is no markdown contract), ≤5000. */
  description: string | null;
  all_day: boolean;
  start_date: IsoDay | null;
  starts_at: string | null;
  ends_at: string | null;
  // NO `color`. The resource does not carry one: an event's colour is not authored, it is
  // the ONE constant the server gives every event occurrence. What the grid draws arrives
  // on `CalendarOccurrence.color`; there is nothing about it to read on the event itself.
  /**
   * The series' rule, or `null` for an event that happens once. ROUND-TRIPS: send it back
   * unchanged on every whole-event write, `exclusions` included.
   *
   * OMITTING IT ON A `PUT` REMOVES THE RECURRENCE, exactly as omitting `description` clears
   * the description — this endpoint is a whole-event write, not a patch. Dropping only
   * `exclusions.dates` while keeping the rest RESURRECTS every day somebody deleted one at
   * a time.
   */
  recurrence: CalendarRecurrenceRule | null;
  /**
   * The zone the rule was STAMPED with — the clock its occurrence DAYS are reckoned on, which
   * is not necessarily today's workspace zone.
   *
   * RE-READ IT FROM THE RESPONSE OF EVERY WHOLE-SERIES WRITE, never hold one across an edit:
   * a `scope=series` save re-stamps the rule with the workspace's CURRENT zone, so an edit
   * that only meant to change the title can move it (gap L13). A stale value makes the next
   * scoped write land on the wrong day — or, more often, be refused (`not_an_occurrence`).
   */
  recurrence_timezone: string | null;
  /**
   * The series' cadence as finished, SERVER-TRANSLATED prose — the same sentence every square
   * of this series carries as `cadence_label` on the grid, published here because the grid is
   * not always there (a deep link, or a series with no occurrence in the window on screen).
   *
   * A DIFFERENT KEY FROM THE OCCURRENCE'S `cadence_label`, deliberately: two resources, two
   * sources of truth, and one name for both would collide (gap L9). Read-only and derived —
   * never sent back, and never composed here from `recurrence.day.*`. `null` covers two cases
   * a client treats identically: the event does not repeat, and its rule is one the server
   * cannot put into a sentence.
   */
  recurrence_label: string | null;
  /** The event's optional POINTER at something else. Never dereferenced (gap L5). */
  subject: CalendarSubject | null;
  /** `whenLoaded('creator')` — polymorphic: user | workflow_run | bot. */
  creator?: Creator | null;
  /**
   * HUMAN authorship only. For an event created by a workflow run this is always false
   * while `can_be_edited` may well be true (the workspace owner). NEVER gate UI on it.
   */
  is_owner: boolean;
  /** The policy verdict (creator OR workspace owner). Gate every action on THESE. */
  can_be_edited: boolean;
  can_be_deleted: boolean;
  created_at: string | null;
  updated_at: string | null;
}

/** `GET|POST|PUT /calendar/events[/{id}]` envelope. */
export interface CalendarEventResponse {
  data: CalendarEvent;
}

/**
 * The `POST` / `PUT` body (`StoreCalendarEventRequest`; `PUT` inherits the SAME rules).
 *
 * ⚠️ PUT IS A WHOLE-EVENT WRITE, NOT A PATCH. `CalendarEventService::attributes()` writes
 * every column on every save and zeroes the unused time group, so a payload that omits
 * `description` / `subject_*` CLEARS them. The drawer therefore keeps the full object from
 * the GET and rebuilds the payload from it (see calendar store `buildEventPayload`).
 *
 * THERE IS NO `color` KEY, AND ITS ABSENCE IS THE CONTRACT — not an omission this shape is
 * being sloppy about. Colour on this screen is a dictionary of MEANINGS (priority, run
 * result, "this is only a projection"); an event that let a human pick from the same six
 * values was the one place the dictionary was decoration, so red meant "urgent", "failed"
 * and nothing at all in a single grid. The choice is gone from the drawer AND from the
 * write surface. Occurrences still carry a colour — the server assigns it.
 *
 * THE DISCRIMINATOR IS ENFORCED BOTH WAYS — an over-complete payload is a 422 naming the
 * offending field, not a silently narrowed save:
 *   all_day = true  → `start_date` REQUIRED, `starts_at`/`ends_at` FORBIDDEN.
 *   all_day = false → `starts_at` REQUIRED, `start_date` FORBIDDEN.
 *
 * `starts_at`/`ends_at` MUST carry an explicit UTC offset (`2026-08-09T14:30:00+02:00`),
 * computed from `meta.timezone` via `zonedWallClockToInstant`. The offset is what makes the
 * value SELF-DESCRIBING: it names one instant no matter which zone reads it, so the moment
 * that comes back from the GET is the moment the user typed. See `zonedWallClockToInstant`
 * for why that is the right shape independently of how the server resolves a zone-less one.
 */
export interface CalendarEventPayload {
  title: string;
  description: string | null;
  all_day: boolean;
  start_date?: IsoDay;
  starts_at?: string;
  ends_at?: string | null;
  /** BOTH or NEITHER. Carried over from the GET on edit — never authored in the UI (§22). */
  subject_type?: string | null;
  subject_id?: string | null;
  /**
   * The series' rule. ABSENT MEANS "HAPPENS ONCE" — and on a `PUT` that is a REMOVAL, not a
   * no-op, so an edit that means to keep the series has to send the rule back.
   *
   * An EMPTY-ISH object is not the same as an absent key: `{day: null, month: null}` is
   * `filled()` server-side and compiles to "every day, forever". The builder emits the key or
   * nothing at all — never `{}`.
   *
   * Forbidden entirely under `scope: 'occurrence'` (422 `occurrence_has_no_rule`): one
   * occurrence of a series is not itself a series.
   */
  recurrence?: CalendarRecurrenceWrite;
  /**
   * How much of the series this write touches. OMITTED means `series` — so it is named
   * explicitly whenever it is anything else, and the omission is a decision rather than a
   * default nobody chose. `prohibited` on create (422 `scope_on_create`).
   */
  scope?: CalendarEventScope;
  /**
   * WHICH occurrence, as the plain day the server publishes on the occurrence itself.
   * Required exactly when `scope !== 'series'`, forbidden when it is.
   */
  occurrence_date?: IsoDay;
}

/** Which surface the one calendar screen is showing (a presentation choice, not a query). */
export type CalendarMode = 'grid' | 'agenda';

/** The occurrence-chip density variants (§6). */
export type OccurrenceVariant = 'grid' | 'agenda' | 'list';
