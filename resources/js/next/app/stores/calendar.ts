// Calendar store (R3) for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for the one calendar screen: the current 42-day WINDOW of merged
// occurrences plus its `meta` (timezone, loss reports, source catalogue, unavailable
// sources), and the CRUD for the only thing the Calendar itself owns — calendar events.
//
// Backend contract (VERIFIED against app/modules/Calendar — do NOT invent fields):
//   GET    /calendar/occurrences?from=&to=&sources[]=&q=
//            → { data: CalendarOccurrence[], meta: { timezone, truncated, truncations[],
//                                                    sources[], unavailable_sources[] } }
//            NO pagination, NO `tz` param, NO `total`. Window > 62 days → 422 on `to`.
//   POST   /calendar/events              → { data: CalendarEvent }
//   GET    /calendar/events/{uuid}       → { data: CalendarEvent }
//   PUT    /calendar/events/{uuid}       → { data: CalendarEvent }
//   DELETE /calendar/events/{uuid}       → 204, no body
//   There is deliberately NO `GET /calendar/events` list and NO restore endpoint.
//
// TWO behaviours worth knowing before touching this file:
//
//   • REFETCH KEEPS THE OLD WINDOW ON SCREEN. Paging months must not blank the grid, so a
//     non-initial load sets `refreshing` and leaves `occurrences`/`meta` in place until the
//     new answer lands. A request token drops any response that a newer request has
//     already superseded — without it, holding `›` down settles on whichever month the
//     network happened to answer last.
//
//   • A SINGLE SOURCE FAILING IS NOT AN ERROR. The backend is fail-soft: a source that
//     throws is skipped, it is reported in `meta.unavailable_sources` WITH THE REASON, and
//     the rest of the grid is returned normally. So `errored` means the WHOLE REQUEST
//     failed; an unavailable source is ordinary data that the page reports separately —
//     and the reason decides whether that report may offer a retry.
//
// Self-contained: NO import from the legacy `resources/js/`.
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { api } from '../lib/api';
import { composeWallClock, zonedWallClockToInstant } from '../../pages/calendar/calendarZone';
import type {
  CalendarEvent,
  CalendarEventPayload,
  CalendarEventResponse,
  CalendarMeta,
  CalendarOccurrence,
  CalendarOccurrencesResponse,
  CalendarWindowQuery,
} from '../../pages/calendar/types';

/**
 * Serialize the window query. `sources` goes out as REPEATED `sources[]=` params — the
 * shape `CalendarWindowRequest` validates (`sources.*` with `Rule::in`) and the same
 * convention `serializeRunFilters` uses for the runs list. Empty / null entries are
 * skipped; an EMPTY `sources` array is omitted entirely, which the backend reads as
 * "every source" — the same thing an all-selected filter means.
 */
export function serializeWindowQuery(query: CalendarWindowQuery): URLSearchParams {
  const params = new URLSearchParams();
  params.append('from', query.from);
  params.append('to', query.to);
  for (const source of query.sources ?? []) {
    if (source != null && String(source) !== '') params.append('sources[]', String(source));
  }
  const q = (query.q ?? '').trim();
  if (q !== '') params.append('q', q);
  return params;
}

/**
 * The editable half of a calendar event — what the drawer's controls hold. The two time
 * groups are kept SIDE BY SIDE on purpose: flipping `all_day` back and forth must not
 * destroy what the user typed, even though only one group is ever sent.
 *
 * NO `color`: a human does not choose one. Colour in this module is a dictionary of
 * meanings (priority / run result / "a projection, not a fact"), and the event drawer was
 * the single place it was offered as decoration. See `CalendarEventPayload`.
 */
export interface CalendarEventDraft {
  title: string;
  description: string;
  all_day: boolean;
  /** Used iff `all_day` — an `yyyy-mm-dd` day, never converted. */
  start_date: string | null;
  /** Used iff `!all_day` — a WALL CLOCK in the workspace zone, split across two controls. */
  starts_day: string | null;
  starts_time: string | null;
  ends_day: string | null;
  ends_time: string | null;
}

/**
 * Build the WHOLE-EVENT write body from the form draft plus the object the GET returned.
 *
 * Pure, and exported, because three separate invariants meet here and every one of them
 * fails SILENTLY when it is wrong:
 *
 *   1. `PUT` IS NOT A PATCH. `CalendarEventService::attributes()` writes every column on
 *      every save, so a key left out is a column cleared. The one thing the form does not
 *      own — the `subject` pointer a `create_event` workflow step may have set — is
 *      therefore carried over from `existing`. Drop it and the run's link to what it
 *      produced is severed, with nothing anywhere reporting it.
 *
 *   2. EXACTLY ONE TIME GROUP. The unused group is FORBIDDEN (a 422), not ignored, so it
 *      is omitted entirely rather than sent empty.
 *
 *   3. EVERY MOMENT CARRIES AN EXPLICIT OFFSET, computed from the WORKSPACE zone. A bare
 *      `2026-08-09T14:30` is not a moment at all until somebody supplies a zone, and
 *      "somebody" is whichever layer happens to look at it; the offset makes the value name
 *      one instant and round-trip unchanged, regardless of how any of them would have
 *      resolved it. See `zonedWallClockToInstant`.
 */
export function buildEventPayload(
  draft: CalendarEventDraft,
  existing: CalendarEvent | null,
  timeZone: string,
): CalendarEventPayload {
  // No `color` key, ever. It is not omitted here for the form's convenience — the write
  // surface has none: an event's colour is server-assigned and constant (see
  // `CalendarEventPayload`). Sending one would be inventing a field.
  const payload: CalendarEventPayload = {
    title: draft.title.trim(),
    description: draft.description.trim() === '' ? null : draft.description,
    all_day: draft.all_day,
  };

  if (draft.all_day) {
    payload.start_date = draft.start_date ?? '';
  } else {
    const startLocal = composeWallClock(draft.starts_day, draft.starts_time);
    payload.starts_at = startLocal ? zonedWallClockToInstant(startLocal, timeZone) : '';
    const endLocal = composeWallClock(draft.ends_day, draft.ends_time);
    // Explicit null rather than omission: both clear the column on a whole-event write,
    // but the null says out loud that clearing is what was meant.
    payload.ends_at = endLocal ? zonedWallClockToInstant(endLocal, timeZone) : null;
  }

  const carried = existing?.subject ?? null;
  if (carried) {
    payload.subject_type = carried.type;
    payload.subject_id = carried.id;
  }

  return payload;
}

/**
 * A normalized write failure. `fieldErrors` maps a field → the FIRST server message
 * (already translated — `lang/{pl,en}/calendar.php`), which the drawer renders verbatim
 * rather than substituting copy of its own. Mirrors `filterTabs`' `toFilterTabError`.
 */
export interface CalendarWriteError {
  status: number | null;
  fieldErrors: Record<string, string>;
  message: string | null;
}

function toWriteError(err: unknown): CalendarWriteError {
  const res = (
    err as {
      response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } };
    }
  )?.response;
  const fieldErrors: Record<string, string> = {};
  for (const [field, messages] of Object.entries(res?.data?.errors ?? {})) {
    if (Array.isArray(messages) && messages.length) fieldErrors[field] = messages[0];
  }
  return {
    status: res?.status ?? null,
    fieldErrors,
    message: res?.data?.message ?? null,
  };
}

function extractMessage(err: unknown): string | null {
  const res = (err as { response?: { data?: { message?: string } } })?.response;
  return res?.data?.message ?? null;
}

/** A window with nothing in it — the shape `meta` takes before the first answer arrives. */
function emptyMeta(): CalendarMeta {
  return {
    // The browser's zone is a PLACEHOLDER for the pre-first-response render only. Every
    // day boundary the screen draws comes from a real `meta.timezone`; the grid is not
    // rendered from this value (the page waits for data before bucketing anything).
    timezone: 'UTC',
    truncated: false,
    truncations: [],
    sources: [],
    unavailable_sources: [],
  };
}

export const useCalendarStore = defineStore('next-calendar', () => {
  // --- Window state --------------------------------------------------------
  const occurrences = ref<CalendarOccurrence[]>([]);
  const meta = ref<CalendarMeta>(emptyMeta());
  /** True only while the FIRST window of a screen is loading (drives the skeleton). */
  const loading = ref(false);
  /** True while a SUBSEQUENT window loads — the previous one stays on screen. */
  const refreshing = ref(false);
  const errored = ref(false);
  const error = ref<string | null>(null);
  /** True once any window has been answered — so "empty" can be told from "not asked yet". */
  const loaded = ref(false);

  /** The window currently on screen (echoed back so a retry repeats exactly it). */
  const currentQuery = ref<CalendarWindowQuery | null>(null);

  let token = 0;

  /**
   * Load one window. Always a full replacement — a calendar window is not paginated and
   * has no cursor, so there is nothing to append.
   *
   * The previous window is kept visible unless this is the first load (or the screen is
   * currently showing an error, where stale data would be worse than a skeleton).
   */
  async function fetchOccurrences(query: CalendarWindowQuery): Promise<void> {
    const myToken = (token += 1);
    currentQuery.value = { ...query };

    const isInitial = !loaded.value || errored.value;
    if (isInitial) loading.value = true;
    else refreshing.value = true;
    errored.value = false;
    error.value = null;

    try {
      const response = await api.get<CalendarOccurrencesResponse>(
        `/calendar/occurrences?${serializeWindowQuery(query).toString()}`,
      );
      if (myToken !== token) return; // superseded by a newer window

      // The ORDER is part of the contract (day → all-day before timed → time → id) and is
      // never re-sorted here: the client buckets by day and keeps the array's order inside
      // each bucket. Re-sorting would silently drop the all-day-first rule.
      occurrences.value = response.data ?? [];
      meta.value = response.meta ?? emptyMeta();
      loaded.value = true;
    } catch (err: unknown) {
      if (myToken !== token) return;
      errored.value = true;
      // The 422 the window ceiling produces is already translated server-side, so it is
      // shown verbatim: it is a defect signal (42 < 62), not a user state to re-word.
      error.value = extractMessage(err);
    } finally {
      if (myToken === token) {
        loading.value = false;
        refreshing.value = false;
      }
    }
  }

  /** Re-run the window currently on screen (the retry affordances). */
  async function refresh(): Promise<void> {
    if (currentQuery.value) await fetchOccurrences(currentQuery.value);
  }

  /** Every registered source, translated by its own module. The filter/legend catalogue. */
  const sources = computed(() => meta.value.sources);
  /**
   * Sources that were asked and could not answer, each with WHY (fail-soft; NOT an error
   * state — the rest of the window is real, current data).
   *
   * `{source, reason}` objects, not ids: the reason decides whether a retry is offered.
   * See `isRetryableUnavailability`.
   */
  const unavailableSources = computed(() => meta.value.unavailable_sources ?? []);
  /** The ids of the above, as a set — the membership test the legend does per source. */
  const unavailableSourceIds = computed(
    () => new Set(unavailableSources.value.map((entry) => entry.source)),
  );
  /** Loss reports. Present only when `meta.truncated`; one row per (source, kind). */
  const truncations = computed(() => (meta.value.truncated ? (meta.value.truncations ?? []) : []));
  /** The zone EVERY day boundary in the loaded window was reckoned in. */
  const timezone = computed(() => meta.value.timezone);

  /** A source id → its server-translated label, falling back to the raw id. */
  function sourceLabel(id: string): string {
    return meta.value.sources.find((s) => s.id === id)?.label ?? id;
  }

  /** Whether THIS source is one of the ones that could not answer (the legend's dimming). */
  function isSourceUnavailable(id: string): boolean {
    return unavailableSourceIds.value.has(id);
  }

  // --- Event detail (the drawer) -------------------------------------------
  const eventDetail = ref<CalendarEvent | null>(null);
  const eventLoading = ref(false);
  const eventError = ref<string | null>(null);
  const saving = ref(false);

  let eventToken = 0;

  /**
   * Read ONE event in full. The drawer holds the WHOLE object because `PUT` is a
   * whole-event write, not a patch: the write path zeroes every column it is not given,
   * so a payload rebuilt from the form alone would clear `description` and — the expensive
   * one — the `subject` pointer a `create_event` workflow step set.
   */
  async function fetchEvent(id: string): Promise<CalendarEvent | null> {
    const myToken = (eventToken += 1);
    eventLoading.value = true;
    eventError.value = null;
    eventDetail.value = null;
    try {
      const response = await api.get<CalendarEventResponse>(`/calendar/events/${id}`);
      if (myToken !== eventToken) return null;
      eventDetail.value = response.data;
      return response.data;
    } catch (err: unknown) {
      if (myToken !== eventToken) return null;
      eventError.value = extractMessage(err);
      return null;
    } finally {
      if (myToken === eventToken) eventLoading.value = false;
    }
  }

  /** Forget the loaded event (the drawer closing). */
  function clearEvent(): void {
    eventToken += 1;
    eventDetail.value = null;
    eventLoading.value = false;
    eventError.value = null;
  }

  /**
   * Create or update. The normalized `CalendarWriteError` is THROWN (not stored) so the
   * caller can route 422 field messages under their controls and anything else into an
   * alert — the two need different treatment and a single stored flag cannot tell them
   * apart.
   */
  async function saveEvent(payload: CalendarEventPayload, id: string | null): Promise<CalendarEvent> {
    saving.value = true;
    try {
      const response = id
        ? await api.put<CalendarEventResponse>(`/calendar/events/${id}`, payload)
        : await api.post<CalendarEventResponse>('/calendar/events', payload);
      eventDetail.value = response.data;
      return response.data;
    } catch (err: unknown) {
      throw toWriteError(err);
    } finally {
      saving.value = false;
    }
  }

  /** Delete (204, no body). Soft-deleted server-side, but there is NO restore endpoint —
   *  so nothing in the UI may promise that this can be undone. */
  async function deleteEvent(id: string): Promise<void> {
    saving.value = true;
    try {
      await api.delete(`/calendar/events/${id}`);
      if (eventDetail.value?.id === id) eventDetail.value = null;
    } catch (err: unknown) {
      throw toWriteError(err);
    } finally {
      saving.value = false;
    }
  }

  /** Drop everything (the screen unmounting). */
  function resetAll(): void {
    token += 1;
    occurrences.value = [];
    meta.value = emptyMeta();
    loading.value = false;
    refreshing.value = false;
    errored.value = false;
    error.value = null;
    loaded.value = false;
    currentQuery.value = null;
    clearEvent();
  }

  return {
    // window state
    occurrences,
    meta,
    loading,
    refreshing,
    errored,
    error,
    loaded,
    currentQuery,
    // derived
    sources,
    unavailableSources,
    truncations,
    timezone,
    sourceLabel,
    isSourceUnavailable,
    // window actions
    fetchOccurrences,
    refresh,
    // event detail
    eventDetail,
    eventLoading,
    eventError,
    saving,
    fetchEvent,
    clearEvent,
    saveEvent,
    deleteEvent,
    resetAll,
  };
});
