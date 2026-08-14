// calendarMeta — the Calendar's static presentation maps, in ONE place and PURE.
//
// Three maps, each with a MANDATORY fallback, because every one of them will be asked
// about a value that did not exist when this file was written (R4 Publishing adds a
// fifth source, and with it a fifth `subject.type`):
//
//   1. `color`        → Tailwind token classes. WHITELISTED literals: Tailwind v4 only
//                       emits classes it can SEE in the source, so a class assembled at
//                       runtime (`bg-next-${color}-subtle`) would resolve to nothing.
//   2. `source` id    → an icon. The LABEL never comes from here — it is server prose
//                       from `meta.sources`, rendered verbatim (that is the whole
//                       mechanism keeping "a new source needs no frontend change" true).
//   3. `subject.type` → a deep link, or null when this frontend has no route for it.
//                       Null is a NORMAL answer, not an error: the chip renders in full
//                       and simply offers no "Open".
//
// No i18n here (the module is pure + framework-free); callers translate.

import type { IconName } from '../../ui/primitives/icons';
import type { CalendarColor, CalendarSubject } from './types';

// ── 1. Colors ────────────────────────────────────────────────────────────────

/** The token classes ONE occurrence colour resolves to. Literal strings — see above. */
export interface CalendarColorTokens {
  /** The solid 1-unit rail down the chip's leading edge. */
  bar: string;
  /** The chip's own tinted surface + its readable foreground. */
  surface: string;
  /** A standalone dot (legend, colour picker) when no surface is available. */
  dot: string;
}

const COLOR_TOKENS: Record<CalendarColor, CalendarColorTokens> = {
  neutral: {
    bar: 'bg-next-muted-foreground',
    surface: 'bg-next-muted text-next-fg',
    dot: 'bg-next-muted-foreground',
  },
  primary: {
    bar: 'bg-next-primary',
    surface: 'bg-next-primary-subtle text-next-primary-subtle-foreground',
    dot: 'bg-next-primary',
  },
  success: {
    bar: 'bg-next-success',
    surface: 'bg-next-success-subtle text-next-success-subtle-foreground',
    dot: 'bg-next-success',
  },
  warning: {
    bar: 'bg-next-warning',
    surface: 'bg-next-warning-subtle text-next-warning-subtle-foreground',
    dot: 'bg-next-warning',
  },
  danger: {
    bar: 'bg-next-danger',
    surface: 'bg-next-danger-subtle text-next-danger-subtle-foreground',
    dot: 'bg-next-danger',
  },
  info: {
    bar: 'bg-next-info',
    surface: 'bg-next-info-subtle text-next-info-subtle-foreground',
    dot: 'bg-next-info',
  },
};

/**
 * Normalise ANY incoming colour to the closed vocabulary. Callers pass `occurrence.color`
 * or `badge.color`; an event resource carries no colour at all (ADR-0051 D10 — colour is a
 * meaning here, and an event has none of its own). The server's enum may grow, so an
 * unrecognised value lands on `neutral` rather than on an empty class string.
 */
export function normalizeColor(color: string | null | undefined): CalendarColor {
  return color != null && color in COLOR_TOKENS ? (color as CalendarColor) : 'neutral';
}

export function colorTokens(color: string | null | undefined): CalendarColorTokens {
  return COLOR_TOKENS[normalizeColor(color)];
}

/**
 * The `Badge` variant for a colour. `CalendarColor`'s six values were chosen to BE the
 * Badge variants, so this is an identity with a guard — the point is that nothing
 * downstream builds a variant by string concatenation.
 */
export function colorBadgeVariant(
  color: string | null | undefined,
): 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info' {
  return normalizeColor(color);
}

// ── 2. Sources ───────────────────────────────────────────────────────────────

/**
 * Icon per KNOWN source id. Deliberately NOT exported as a list: nothing may enumerate
 * sources from here — the catalogue is `meta.sources`, which the server owns.
 *
 * The glyphs are chosen to be distinguishable at chip size and to echo where each thing
 * lives: task deadlines wear the Tasks nav glyph, a planned automation wears a clock (it
 * has not happened yet), an executed one wears the Workflows glyph, an event the calendar.
 */
const SOURCE_ICONS: Record<string, IconName> = {
  task: 'list-checks',
  workflow_schedule: 'clock',
  workflow_run: 'workflow',
  event: 'calendar',
};

/** The FALLBACK glyph for a source this build has never heard of. */
export const UNKNOWN_SOURCE_ICON: IconName = 'circle';

/**
 * A source's icon, with the fallback that makes the "fifth source needs no frontend
 * change" promise survive contact with the filter chips and the legend.
 */
export function sourceIcon(id: string): IconName {
  return SOURCE_ICONS[id] ?? UNKNOWN_SOURCE_ICON;
}

// ── 3. Deep links ────────────────────────────────────────────────────────────

/** A router target for a subject, plus which ACTION word describes following it. */
export interface SubjectLink {
  /** A `router.push` / `<RouterLink :to>` location inside the `next` app. */
  to: { path: string; query?: Record<string, string> };
  /**
   * Which label the affordance carries:
   *   `open` — the link opens THAT thing;
   *   `list` — the link only reaches the SCREEN it lives on (see `workflow_run` below).
   * A button that says "Open" and lands on a list is a small lie repeated every day.
   */
  kind: 'open' | 'list';
}

/**
 * Where a subject alias opens, or null when this frontend has no route for it.
 *
 * VERIFIED against the routes as they are, not against the spec's table — and the two
 * disagree in one place, so this is worth reading rather than skimming:
 *
 *   • `task`           → `/tasks?task=<id>` opens the task drawer.
 *                        (`TasksView.vue`: `drawerTaskId = route.query.task`.)
 *   • `workflow`       → `/workflows?workflow=<id>` opens the workflow editor drawer.
 *                        (`WorkflowsModuleLayout.vue`: `workflowParam`.)
 *   • `workflow_run`   → the runs LIST, with NO id. The UX spec (§11.3) routes this to
 *                        `/workflows?run=<id>`, but on that layout `?run=` opens the
 *                        RUN-NOW target picker and its param is a WORKFLOW id
 *                        (`TargetPickerModal :workflow-id="runParam"`) — handing it a run
 *                        id would open a broken modal against a workflow that does not
 *                        exist. The only run-detail deep link that DOES exist is
 *                        `?run_detail=` on `/workflows/<workflowId>/runs`, and it needs
 *                        the workflow id, which a calendar occurrence does not carry
 *                        (its subject is the RUN). `GET /workflows/{id}/runs/{run}` is
 *                        nested under the workflow too, so the id cannot be resolved
 *                        client-side either. Reaching the runs list is therefore the most
 *                        this contract supports — and it is labelled as such.
 *   • `calendar_event` → NOT here. An event opens IN PLACE (`?event=<id>` on the calendar
 *                        route); returning a route would send the user away from the very
 *                        screen that owns the thing.
 *   • anything else    → null. Required, not defensive: R4 will add an alias this build
 *                        has never seen, and the popover must render without a dead link.
 */
export function subjectLink(subject: CalendarSubject | null | undefined): SubjectLink | null {
  if (!subject?.type || !subject.id) return null;

  switch (subject.type) {
    case 'task':
      return { to: { path: '/tasks', query: { task: subject.id } }, kind: 'open' };
    case 'workflow':
      return { to: { path: '/workflows', query: { workflow: subject.id } }, kind: 'open' };
    case 'workflow_run':
      return { to: { path: '/workflows/runs' }, kind: 'list' };
    default:
      return null;
  }
}

// ── 4. Loss reporting ────────────────────────────────────────────────────────

/**
 * The i18n key for ONE truncation row.
 *
 * The COUNT decides the KEY, never the number inside it. `omitted_occurrences` /
 * `affected_items` are `int | null`, and `null` means GENUINELY UNKNOWN — the backend
 * merges counts with a poisoning rule precisely so it never has to report a known part as
 * the whole. `count ?? 0` would turn "we do not know" into "nothing is missing", which is
 * the one statement the whole truncation contract exists to avoid making.
 */
export function truncationKey(kind: string, count: number | null): string {
  const suffix = count == null ? 'unknown' : 'withCount';
  switch (kind) {
    case 'window_trimmed':
      return `calendar.truncation.windowTrimmed.${suffix}`;
    case 'items_dropped':
      return `calendar.truncation.itemsDropped.${suffix}`;
    case 'item_densified':
      return `calendar.truncation.itemDensified.${suffix}`;
    default:
      // An unknown kind cannot be worded honestly, so it is not worded at all — the
      // caller drops the row rather than inventing a sentence about it.
      return '';
  }
}

/**
 * WHICH count a kind reports. Read from the sources rather than assumed:
 * `window_trimmed` carries `omitted_occurrences` (and null when a source bounded its own
 * query), `item_densified` carries `affected_items`, `items_dropped` carries neither.
 * The UI must not depend on that regularity — the contract allows null anywhere — so this
 * only says which FIELD to look at, and the null-ness still chooses the key.
 */
export function truncationCount(
  kind: string,
  omittedOccurrences: number | null,
  affectedItems: number | null,
): number | null {
  return kind === 'window_trimmed' ? omittedOccurrences : affectedItems;
}

/**
 * `items_dropped` is the only loss at which a user can make a WRONG DECISION by trusting
 * an empty square: they are not looking at a partial view of everything, they are looking
 * at a complete view of some things and no view at all of others. It gets the alarming
 * glyph; the other two get `info`.
 */
export function truncationIcon(kind: string): IconName {
  return kind === 'items_dropped' ? 'alert-triangle' : 'info';
}

// ── 4. Unavailability: is trying again worth anything? ───────────────────────

/**
 * Whether a source's failure is worth a RETRY.
 *
 * The whole reason `unavailable_sources` stopped being a list of ids. A user who is told
 * "the schedules couldn't be loaded" has exactly one question — do I click something or do
 * I go and tell somebody — and a bare list forced the UI to answer it the same way for all
 * three failures. So the retry button appeared next to failures no click could ever fix,
 * which is a control that does nothing: the same defect as an "Open" that lands on a list.
 *
 * ONLY `failed` IS RETRYABLE. It means the source was asked and threw — a timeout, a bad
 * minute, a module briefly down. `not_constructed` is configuration or a broken boot and is
 * identical on the next request; `malformed` is a defect in that source's code and
 * reproduces exactly. Offering a retry for either would be a promise nothing can keep.
 *
 * AN UNKNOWN CODE IS TREATED AS NOT RETRYABLE, deliberately. The vocabulary is closed today,
 * but a fourth reason arriving from a newer backend must not silently inherit the button —
 * "we do not know whether this can recover" is not a reason to promise that it can. The
 * source is still NAMED either way; nothing is ever dropped for being unrecognised.
 */
export function isRetryableUnavailability(reason: string): boolean {
  return reason === 'failed';
}
