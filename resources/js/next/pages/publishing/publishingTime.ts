// publishingTime — reading and writing moments on the WORKSPACE's clock, not the browser's.
//
// Every instant in this module is a CHWILA, never a day: a publication goes out at a minute
// somebody chose, and `CalendarInstantResolver` reads a zone-less string sent to the server
// on the WORKSPACE's clock. A browser-local reading would be right for whoever wrote the
// draft and wrong for everybody else on the team — and the failure is silent, because both
// readings produce a plausible time.
//
// The zone arithmetic itself is REUSED from the Calendar (`pages/calendar/calendarZone.ts`),
// which already owns the only zone database a browser ships and is already tested. A second
// implementation is the one that disagrees about a DST boundary at two in the morning.
import { browserTimeZone, composeWallClock, instantToWallClock } from '../calendar/calendarZone';

/** The zone to reckon in when the workspace has not stated one. */
const FALLBACK_ZONE = 'UTC';

/**
 * An ISO instant as `yyyy-mm-ddTHH:mm` on the workspace's clock — the shape a
 * `DateTimePicker` edits and the shape the server parses back on the same clock.
 *
 * A null zone means "inherit the application clock", which this client does not know. It
 * reckons in UTC rather than substituting the browser's zone: being consistently off by a
 * known amount is recoverable, while quietly re-anchoring somebody's 09:00 to their own
 * midnight is a moment nobody chose.
 */
export function instantToLocalInput(iso: string | null, timeZone: string | null): string | null {
  if (!iso) return null;
  const { day, time } = instantToWallClock(iso, timeZone ?? FALLBACK_ZONE);
  return composeWallClock(day, time);
}

/**
 * Render an instant for a reader, in the workspace's zone.
 *
 * `Intl` does the zone work, so a date near a DST change reads as the platform will see it.
 * Returns an empty string for null so a caller can `v-if` on it rather than printing "—"
 * where a missing moment is the point (a draft has no moment, and saying so is the job of
 * the sentence around this, not of a dash).
 */
export function formatInstant(
  iso: string | null | undefined,
  timeZone: string | null,
  locale: string,
  options: Intl.DateTimeFormatOptions = {},
): string {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  try {
    return new Intl.DateTimeFormat(locale, {
      day: 'numeric',
      month: 'short',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
      timeZone: timeZone ?? FALLBACK_ZONE,
      ...options,
    }).format(date);
  } catch {
    // An unknown zone name must not take a screen down; the instant still renders.
    return new Intl.DateTimeFormat(locale, {
      day: 'numeric',
      month: 'short',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
      ...options,
    }).format(date);
  }
}

/** The same, with the year — for the detail's metadata, where "10 Sep" is not enough. */
export function formatInstantLong(
  iso: string | null | undefined,
  timeZone: string | null,
  locale: string,
): string {
  return formatInstant(iso, timeZone, locale, { year: 'numeric' });
}

/**
 * "Now" as a local wall-clock string on the workspace's clock — what "Publish now" sends.
 *
 * The server tolerates 60 seconds in the past, which is exactly what makes this legal: the
 * round trip and any ordinary clock skew fit inside that window.
 */
export function nowAsLocalInput(timeZone: string | null, now: Date = new Date()): string {
  return instantToLocalInput(now.toISOString(), timeZone) ?? '';
}

/**
 * Whether the reader's browser sits in a different zone from the workspace.
 *
 * Used for ONE thing: a warning beside a time field. It never changes what is stored or what
 * is rendered — the workspace's clock decides both.
 */
export function zoneMismatch(timeZone: string | null): { local: string | null; differs: boolean } {
  const local = browserTimeZone();
  return { local, differs: !!timeZone && !!local && local !== timeZone };
}
