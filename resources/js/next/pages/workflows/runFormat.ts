// Pure formatting helpers for the Workflows RUNS surfaces (next, Batch 6c).
//
// Extracted so the number/time logic is unit-testable in isolation (no Vue, no
// i18n side effects). The RUNS row + run-detail drawer consume these for the
// duration chip and the started/created timestamp; a null duration ("—" while a
// run is still in flight) is handled by the CALLER (these return null so the
// template can render the "—" placeholder / i18n dash).

/**
 * Format a run's `duration_seconds` (wall-clock, integer seconds) into a short
 * human string. Returns `null` when the value is null/undefined (an unfinished
 * run) so the caller renders its own placeholder.
 *
 *   0        → "0s"
 *   45       → "45s"
 *   90       → "1m 30s"
 *   3600     → "1h 0m"
 *   3661     → "1h 1m"      (seconds dropped once we're past an hour — the row
 *                            only needs an at-a-glance magnitude)
 */
export function formatDuration(seconds: number | null | undefined): string | null {
  if (seconds == null || Number.isNaN(seconds)) return null;
  const s = Math.max(0, Math.floor(seconds));
  if (s < 60) return `${s}s`;
  const minutes = Math.floor(s / 60);
  const remSeconds = s % 60;
  if (minutes < 60) return `${minutes}m ${remSeconds}s`;
  const hours = Math.floor(minutes / 60);
  const remMinutes = minutes % 60;
  return `${hours}h ${remMinutes}m`;
}

/**
 * Format an ISO timestamp for display. Uses `toLocaleString()` — the same
 * approach the detail/overview surfaces use — so the value reads in the user's
 * locale/timezone. Returns the raw string if it can't be parsed and `''` when
 * absent, so a template can `v-if` on it.
 */
export function formatTimestamp(raw: string | null | undefined): string {
  if (!raw) return '';
  const d = new Date(raw);
  return Number.isNaN(d.getTime()) ? raw : d.toLocaleString();
}

/**
 * Truncate a (possibly multi-line) error string to a single-line preview for the
 * run row. Collapses whitespace/newlines and clamps to `max` chars with an
 * ellipsis. Returns '' for a null/empty error.
 */
export function truncateError(error: string | null | undefined, max = 120): string {
  if (!error) return '';
  const oneLine = error.replace(/\s+/g, ' ').trim();
  if (oneLine.length <= max) return oneLine;
  return `${oneLine.slice(0, max - 1).trimEnd()}…`;
}
