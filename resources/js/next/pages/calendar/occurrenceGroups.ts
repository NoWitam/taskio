// occurrenceGroups — the two pure list transforms the calendar surfaces share.
//
// Both the month grid and the agenda have to answer the same two questions, and they must
// answer them IDENTICALLY or the same month reads as two different months depending on
// which surface you switched to:
//
//   1. Which DAY does this occurrence belong to? (`bucketByDay`)
//   2. What does one day's list look like once a runaway series is folded? (`collapseDense`)
//
// Kept pure and Vue-free so both are unit-testable, and so the day-bucketing rule — the
// single most dangerous piece of arithmetic on this screen — sits in one place with its
// reasoning attached.

import type { CalendarOccurrence } from './types';
import type { IsoDay } from './types';
import { instantToZonedParts } from './calendarZone';

/**
 * One ROW on a surface. Usually one occurrence; for a folded series, the first occurrence
 * of that series standing in for the whole group.
 */
export interface DisplayOccurrence {
  /** The occurrence that represents this row (the group's FIRST, in API order). */
  occurrence: CalendarOccurrence;
  /** How many occurrences this row stands for. 1 for an ordinary row. */
  shown: number;
  /** True when this row folds a dense series (drives the series marker). */
  folded: boolean;
}

/**
 * The day an occurrence belongs to, IN THE WORKSPACE ZONE — or null when it carries
 * neither shape (which the contract does not allow, but a null is cheaper than a throw).
 *
 * THE WHOLE POINT OF THIS FUNCTION IS THE FIRST BRANCH. An all-day occurrence's
 * `start_date` is a plain calendar day with NO zone; it is returned untouched. Handing it
 * to `new Date()` would make it a UTC instant, and rendering that instant in a zone west
 * of UTC moves a task deadline to the previous day. The regression test for this screen is
 * exactly that: `start_date: '2026-08-09'` in `Pacific/Kiritimati` (UTC+14) must land on
 * `2026-08-09`.
 */
export function dayOf(occurrence: CalendarOccurrence, timeZone: string): IsoDay | null {
  if (occurrence.all_day) return occurrence.start_date ?? null;
  if (!occurrence.starts_at) return null;
  return instantToZonedParts(occurrence.starts_at, timeZone)?.day ?? null;
}

/**
 * Bucket a window's occurrences by day, PRESERVING the array order inside each bucket.
 *
 * The server orders `data[]` (day → all-day before timed → time → id) and that ordering is
 * part of the contract. Re-sorting client-side would silently drop the all-day-first rule
 * and put a deadline underneath a 09:00 meeting, so nothing here sorts anything.
 */
export function bucketByDay(
  occurrences: CalendarOccurrence[],
  timeZone: string,
): Map<IsoDay, CalendarOccurrence[]> {
  const buckets = new Map<IsoDay, CalendarOccurrence[]>();
  for (const occurrence of occurrences) {
    const day = dayOf(occurrence, timeZone);
    if (!day) continue;
    const bucket = buckets.get(day);
    if (bucket) bucket.push(occurrence);
    else buckets.set(day, [occurrence]);
  }
  return buckets;
}

/** The key a day's occurrences group by: their SUBJECT, falling back to their own id. */
function groupKey(occurrence: CalendarOccurrence): string {
  return occurrence.subject?.id || occurrence.id;
}

/**
 * Fold DENSE series inside ONE day's list.
 *
 * A series that repeats faster than the grid can draw would otherwise fill a cell with
 * near-identical chips and push everything else behind a "+N more" — the day would look
 * like nothing but that one automation. So a group whose occurrences carry `dense: true`
 * collapses to a SINGLE row: the group's first occurrence (which carries the earliest time
 * of that day, the order being the server's), marked as standing for `shown` of them.
 *
 * Groups WITHOUT a dense flag are left alone — three ordinary meetings on the same task are
 * three real rows and folding them would hide information the user can act on.
 *
 * WHAT `shown` MEANS, AND WHAT IT DOES NOT. It is the number of occurrences this row is
 * STANDING IN FOR — not the size of the series, which remains unknown here (the
 * `item_densified` notice is what says so). How OFTEN the series repeats is a separate fact
 * and comes from a separate field: `cadence_label`, finished prose from the source, which
 * the chip prefers over this count precisely because a count of what we folded is a number
 * about the grid rather than about the thing on it. Nothing in this file computes or guesses
 * a cadence; a group with none simply has none.
 */
export function collapseDense(dayList: CalendarOccurrence[]): DisplayOccurrence[] {
  // Pass 1: how big is each group, and does any member claim density?
  const sizes = new Map<string, number>();
  const isDense = new Map<string, boolean>();
  for (const occurrence of dayList) {
    const key = groupKey(occurrence);
    sizes.set(key, (sizes.get(key) ?? 0) + 1);
    if (occurrence.dense) isDense.set(key, true);
  }

  // Pass 2: emit in the SERVER's order, one row per non-dense occurrence and one row per
  // dense GROUP (at the position of its first member).
  const emitted = new Set<string>();
  const rows: DisplayOccurrence[] = [];
  for (const occurrence of dayList) {
    const key = groupKey(occurrence);
    if (!isDense.get(key)) {
      rows.push({ occurrence, shown: 1, folded: false });
      continue;
    }
    if (emitted.has(key)) continue;
    emitted.add(key);
    rows.push({ occurrence, shown: sizes.get(key) ?? 1, folded: true });
  }
  return rows;
}

/**
 * Every occurrence belonging to the same group as `occurrence` — the UNFOLDED list a day
 * popover shows when a folded row is opened. Order preserved.
 */
export function groupMembers(
  dayList: CalendarOccurrence[],
  occurrence: CalendarOccurrence,
): CalendarOccurrence[] {
  const key = groupKey(occurrence);
  return dayList.filter((candidate) => groupKey(candidate) === key);
}

/**
 * Split a folded day list into the rows a cell can show and the count behind "+N more".
 *
 * Called with the ALREADY-FOLDED list, and that order matters: counting before folding
 * makes the badge promise more rows than the popover contains, which is the sort of small
 * lie that teaches people to stop trusting counters.
 */
export function splitOverflow(
  rows: DisplayOccurrence[],
  max: number,
): { visible: DisplayOccurrence[]; overflow: number } {
  if (max <= 0 || rows.length <= max) return { visible: rows, overflow: 0 };
  return { visible: rows.slice(0, max), overflow: rows.length - max };
}
