<script setup lang="ts">
// CalendarView — the workspace's timeline: one screen, two surfaces, four sources.
//
// ONE WINDOW FOR BOTH SURFACES (spec D2). The grid and the agenda read the SAME 42 days,
// so switching between them does not touch the network, one set of loss notices covers
// both, and a user toggling back and forth can never be told two different stories about
// the same month. Only `month`, `sources` and `q` refetch; `mode` never does.
//
// EVERYTHING THIS SCREEN KNOWS ABOUT SOURCES COMES FROM `meta.sources`. The filter chips,
// the legend and the icons are built from it, with a fallback glyph for an id this build
// has never seen. That is the entire mechanism behind "R4 Publishing appears here without
// a frontend change" — and it only stays true if nobody ever writes the list down.
//
// "TODAY" IS THE WORKSPACE'S, NOT THE BROWSER'S. `workspaceToday(meta.timezone)` decides
// which square is highlighted, whether the Today button is available, and which month
// opens by default. A user in another zone must be able to tell whose midnight they are
// looking at — hence the permanent zone chip, which turns into a warning when the two
// zones actually differ.
//
// URL IS THE STATE (spec §3.3):
// `?month=&mode=&sources=&q=&event=&edit=&new=&date=&on=&at=&scope=`.
// Filters and navigation `replace` (they must not fill the Back stack); opening an overlay
// `push`es, so Back closes it. There is no `useRouteQueryHydration` in `next` — that lives
// on the legacy side of the boundary — so the sync is the manual `computed` + `router`
// pattern `TasksView` and `WorkflowsView` use.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import FilterBar, { type ActiveFilter } from '../../ui/patterns/FilterBar.vue';
import FilterTabBar from '../../ui/patterns/FilterTabBar.vue';
import SaveViewModal, { type SaveViewSubmit } from '../../ui/patterns/SaveViewModal.vue';
import ConfirmDialog from '../../ui/overlay/ConfirmDialog.vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import Surface from '../../ui/layout/Surface.vue';
import Alert from '../../ui/feedback/Alert.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import MonthGrid from './MonthGrid.vue';
import AgendaList from './AgendaList.vue';
import DayPopover from './DayPopover.vue';
import OccurrencePopover from './OccurrencePopover.vue';
import EventDrawer from './EventDrawer.vue';
import TruncationNotices from './TruncationNotices.vue';
import { useI18n } from '../../app/i18n';
import { useToast } from '../../app/composables/useToast';
import { useFilterTabs, type FilterSnapshot } from '../../app/composables/useFilterTabs';
import { useFilterTabsStore, type FilterTab } from '../../app/stores/filterTabs';
import { toIconEnumValue } from '../../ui/forms/filterTabIcon';
import { useCalendarStore } from '../../app/stores/calendar';
import { bucketByDay } from './occurrenceGroups';
import { isRetryableUnavailability, sourceIcon } from './calendarMeta';
import { addMonthsToMonth, browserTimeZone, monthOf, monthStartDate, workspaceToday } from './calendarZone';
import { buildMonthWeeks, monthNames, monthYearLabel } from '../../ui/forms/date/dateCore';
import type { CalendarEventScope, CalendarMode, CalendarOccurrence, IsoDay } from './types';

const route = useRoute();
const router = useRouter();
const { t, currentLocale } = useI18n();
const toast = useToast();
const store = useCalendarStore();
const filterTabsStore = useFilterTabsStore();

const SAVED_VIEWS_CONTEXT = 'calendar';

/** Normalize a query value that Vue Router may hand back as an array or as undefined. */
const str = (v: unknown): string => (Array.isArray(v) ? String(v[0] ?? '') : String(v ?? ''));
/** A repeated query param (`?sources=a&sources=b`) as a list. */
const list = (v: unknown): string[] =>
  v == null ? [] : (Array.isArray(v) ? v : [v]).map((x) => String(x ?? '')).filter((x) => x !== '');

// ── Viewport ────────────────────────────────────────────────────────────────
// The presentation depends on real width, not on a CSS breakpoint alone, because the
// number of chips a cell shows also decides the "+N more" COUNT — and a counter computed
// for a layout that is not on screen would be a lie. Below `next-md` the grid stops being
// readable at all, so the agenda takes over; `?mode` is left untouched so the user's
// preference survives a rotation (the same contract `Table responsive="stack"` honours).
const viewportWidth = ref(typeof window === 'undefined' ? 1440 : window.innerWidth);
function onResize(): void {
  viewportWidth.value = window.innerWidth;
}
onMounted(() => window.addEventListener('resize', onResize, { passive: true }));
onBeforeUnmount(() => window.removeEventListener('resize', onResize));

/** < next-md (48rem): the grid is replaced by the agenda, whatever `?mode` says. */
const forcedAgenda = computed(() => viewportWidth.value < 768);
/** 3 chips at ≥ next-xl, 2 at ≥ next-lg, and the compact dot cell below that. */
const chipLimit = computed(() => (viewportWidth.value >= 1280 ? 3 : viewportWidth.value >= 1024 ? 2 : 0));

// ── URL state ───────────────────────────────────────────────────────────────
const modeParam = computed<CalendarMode>(() => (str(route.query.mode) === 'agenda' ? 'agenda' : 'grid'));
const effectiveMode = computed<CalendarMode>(() => (forcedAgenda.value ? 'agenda' : modeParam.value));

/**
 * The month on screen. Before the first response there is no `meta.timezone` to reckon
 * "now" in, so the browser's month seeds the first request; once the real zone arrives,
 * `syncDefaultMonth` corrects it — but ONLY when the user has not named a month
 * themselves. This costs one extra request for a user sitting on a month boundary in a
 * distant zone, and buys never opening the wrong month for everyone else.
 */
const fallbackMonth = monthOf(workspaceToday(browserTimeZone() ?? 'UTC'));
const month = computed<string>(() => {
  const raw = str(route.query.month);
  return /^\d{4}-\d{2}$/.test(raw) ? raw : fallbackMonth;
});
const monthPinned = computed(() => /^\d{4}-\d{2}$/.test(str(route.query.month)));

const sourceFilter = ref<string[]>(list(route.query.sources));
const search = ref<string>(str(route.query.q));

const eventParam = computed<string | null>(() => str(route.query.event) || null);
const isEditing = computed(() => str(route.query.edit) === '1');
const isCreating = computed(() => str(route.query.new) === '1');
const seedDate = computed<IsoDay | null>(() => str(route.query.date) || null);

/**
 * WHICH occurrence of a series is being pointed at, and HOW MUCH of the series a write will
 * touch. Two keys for the occurrence, and they are not two spellings of one thing:
 *
 *   `on` — the DAY, exactly as the server published it on the square
 *          (`occurrence.occurrence_date`). This is the IDENTIFIER: it is reckoned on the
 *          series' own stamped clock and is the string the write surface names an occurrence
 *          by, so it is carried verbatim and never re-derived from an instant and a zone.
 *   `at` — the occurrence's own INSTANT (`occurrence.starts_at`), carried only so a scoped
 *          edit can seed its date + time from the square that was clicked rather than from
 *          the series' anchor. Absent for an all-day series, which has no instant at all.
 *
 * The day/instant split is the same discriminator that runs through the whole module, and
 * keeping them apart is what stops either from being guessed from the other.
 */
const occurrenceParam = computed<IsoDay | null>(() => str(route.query.on) || null);
const occurrenceAtParam = computed<string | null>(() => str(route.query.at) || null);
/** `series` is the default and is never written to the URL. */
const scopeParam = computed<CalendarEventScope>(() => {
  const raw = str(route.query.scope);
  return raw === 'occurrence' || raw === 'following' ? raw : 'series';
});

/**
 * The square the drawer was opened from, found again in the window currently loaded — so its
 * own `cadence_label` (server prose, from the same refresh the user is looking at) can reach
 * the drawer. Null after a reload that landed on a different month, where the drawer falls
 * back to the event's own `recurrence_label`; the client composes neither.
 */
const selectedOccurrence = computed<CalendarOccurrence | null>(() => {
  const id = eventParam.value;
  if (!id) return null;
  return (
    store.occurrences.find(
      (occurrence) =>
        occurrence.subject?.id === id &&
        (!occurrenceParam.value || occurrence.occurrence_date === occurrenceParam.value),
    ) ?? null
  );
});

/** Write query keys, dropping the ones set to null. `replace` for state, `push` for overlays. */
function setQuery(patch: Record<string, unknown>, mode: 'replace' | 'push' = 'replace'): void {
  const query: Record<string, unknown> = { ...route.query };
  for (const [key, value] of Object.entries(patch)) {
    if (value == null || value === '' || (Array.isArray(value) && value.length === 0)) delete query[key];
    else query[key] = value;
  }
  void (mode === 'push' ? router.push({ query }) : router.replace({ query }));
}

// Filters live in refs (the FilterBar debounces search, and saved views write them
// wholesale), and are mirrored into the URL so a view is shareable.
watch([sourceFilter, search], () => {
  setQuery({ sources: sourceFilter.value, q: search.value });
});

// ── Window ──────────────────────────────────────────────────────────────────
const weeks = computed(() => buildMonthWeeks(monthStartDate(month.value) ?? new Date(), 1));
const windowDays = computed<IsoDay[]>(() => weeks.value.flat().map((cell) => cell.iso));
const windowFrom = computed(() => windowDays.value[0] ?? '');
const windowTo = computed(() => windowDays.value[windowDays.value.length - 1] ?? '');

/** The fetch identity. Mode is NOT part of it — switching surfaces must not refetch. */
const windowKey = computed(() =>
  JSON.stringify([windowFrom.value, windowTo.value, [...sourceFilter.value].sort(), search.value.trim()]),
);

function fetchWindow(): void {
  if (!windowFrom.value || !windowTo.value) return;
  void store.fetchOccurrences({
    from: windowFrom.value,
    to: windowTo.value,
    sources: sourceFilter.value.length ? [...sourceFilter.value] : undefined,
    q: search.value.trim() || undefined,
  });
}

watch(windowKey, () => fetchWindow());

/**
 * Once the WORKSPACE zone is known, correct an unpinned default month. Runs at most once
 * per mount: after it, either the query carries a month or the browser and the workspace
 * agree on which one it is.
 */
let defaultMonthSynced = false;
watch(
  () => store.timezone,
  (tz) => {
    if (defaultMonthSynced || !store.loaded || monthPinned.value) return;
    defaultMonthSynced = true;
    const workspaceMonth = monthOf(workspaceToday(tz));
    if (workspaceMonth !== month.value) setQuery({ month: workspaceMonth });
  },
);

// ── Derived view data ───────────────────────────────────────────────────────
const timezone = computed(() => store.timezone);
const today = computed(() => workspaceToday(timezone.value));
const locale = computed(() => currentLocale.value);
const buckets = computed(() => bucketByDay(store.occurrences, timezone.value));

const monthLabel = computed(() => {
  const start = monthStartDate(month.value);
  return start ? monthYearLabel(start, locale.value) : month.value;
});
/**
 * Reserve the width of the longest month name in this locale so paging months never
 * shuffles the arrows sideways — the same `headingMinWidth` trick `CalendarPanel` uses,
 * and the reason holding `›` down feels like paging rather than like the UI twitching.
 */
const headingMinWidth = computed(() => {
  const longest = monthNames(locale.value, 'long').reduce((max, name) => Math.max(max, name.length), 0);
  return `${longest + 5}ch`;
});

/** The Today button is pointless — and must SAY so — when today is already on screen. */
const todayInView = computed(() => windowDays.value.includes(today.value));

const browserZone = browserTimeZone();
const zoneMismatch = computed(() => !!browserZone && browserZone !== timezone.value);

function shiftMonth(months: number): void {
  setQuery({ month: addMonthsToMonth(month.value, months) });
}
function goToToday(): void {
  setQuery({ month: monthOf(today.value) });
}

// ── Filters ─────────────────────────────────────────────────────────────────
/**
 * Built ONLY from `meta.sources` (spec D6). The catalogue is deliberately independent of
 * the current filter — a list that shrank to the selection would delete the chip the user
 * just switched off, leaving no way to switch it back on.
 */
const sourceOptions = computed<SegmentOption[]>(() =>
  store.sources.map((source) => ({
    value: source.id,
    // Server prose. Never translated here — that is what keeps a new source label-correct
    // without a frontend release.
    label: source.label,
    icon: sourceIcon(source.id),
  })),
);

const hasActiveFilters = computed(() => sourceFilter.value.length > 0 || search.value.trim() !== '');

function sourceChipLabel(id: string): string {
  return t('calendar.filters.chip.source', '', { value: store.sourceLabel(id) });
}

const activeFilters = computed<ActiveFilter[]>(() => {
  const chips: ActiveFilter[] = [];
  if (sourceFilter.value.length) {
    // ONE chip per value — never "3 selected". The user must see what is on.
    chips.push({
      key: 'source',
      values: sourceFilter.value.map((id) => ({ key: `source:${id}`, label: sourceChipLabel(id) })),
    });
  }
  if (search.value.trim()) {
    chips.push({ key: 'q', label: t('calendar.filters.chip.search', '', { value: search.value.trim() }) });
  }
  return chips;
});

function removeFilter(key: string): void {
  if (key.startsWith('source:')) {
    const id = key.slice('source:'.length);
    sourceFilter.value = sourceFilter.value.filter((v) => v !== id);
    return;
  }
  if (key === 'q') search.value = '';
}
function clearAll(): void {
  sourceFilter.value = [];
  search.value = '';
}

// ── Saved views ─────────────────────────────────────────────────────────────
// The snapshot carries `sources`, `q` and `mode` — but NOT the month (spec D9). A view
// pinned to "August 2026" is stale the moment the month turns, which is the same reason
// this screen has no date-range control: the grid's navigation IS the range.
function serializeSnapshot(): FilterSnapshot {
  const snap: FilterSnapshot = {};
  if (sourceFilter.value.length) snap.sources = [...sourceFilter.value];
  if (search.value.trim()) snap.q = search.value.trim();
  if (modeParam.value !== 'grid') snap.mode = modeParam.value;
  return snap;
}
function snapArr(v: unknown): string[] {
  return Array.isArray(v) ? v.map(String) : v != null && v !== '' ? [String(v)] : [];
}
function applySnapshot(snap: FilterSnapshot): void {
  sourceFilter.value = snapArr(snap.sources);
  search.value = typeof snap.q === 'string' ? snap.q : '';
  setQuery({ mode: snap.mode === 'agenda' ? 'agenda' : null });
}
function normalizeSnapshot(snap: FilterSnapshot): string[] {
  const keys: string[] = [];
  snapArr(snap.sources).sort().forEach((v) => keys.push(`source:${v}`));
  if (snap.q) keys.push('q');
  if (snap.mode === 'agenda') keys.push('mode');
  return keys;
}
function restoreSnapshotValue(key: string, snap: FilterSnapshot): void {
  if (key.startsWith('source:')) {
    const id = key.slice('source:'.length);
    if (!sourceFilter.value.includes(id)) sourceFilter.value = [...sourceFilter.value, id];
    return;
  }
  if (key === 'q' && typeof snap.q === 'string') search.value = snap.q;
  if (key === 'mode') setQuery({ mode: 'agenda' });
}

const savedViews = useFilterTabs(SAVED_VIEWS_CONTEXT, {
  serialize: serializeSnapshot,
  apply: applySnapshot,
  normalize: normalizeSnapshot,
  restoreValue: restoreSnapshotValue,
});
const savingView = ref(false);

const decoratedFilters = computed<ActiveFilter[]>(() => {
  const decorated = savedViews.decorateActiveFilters(activeFilters.value);
  return decorated.map((f) =>
    f.tabState === 'tab-disabled' && f.label === f.key && f.key.startsWith('source:')
      ? { ...f, label: sourceChipLabel(f.key.slice('source:'.length)) }
      : f,
  );
});

async function onSaveActiveView(): Promise<void> {
  savingView.value = true;
  try {
    await savedViews.saveActive();
    toast.success(t('tasks.savedViews.toast.updated'));
  } catch {
    toast.danger(t('tasks.savedViews.toast.saveError'));
  } finally {
    savingView.value = false;
  }
}

const saveModalOpen = ref(false);
const saveModalMode = ref<'create' | 'edit'>('create');
const editingView = ref<FilterTab | null>(null);
const saveModalNameError = ref<string | null>(null);

function onSaveAs(): void {
  saveModalMode.value = 'create';
  editingView.value = null;
  saveModalNameError.value = null;
  saveModalOpen.value = true;
}
function onEditView(v: FilterTab): void {
  saveModalMode.value = 'edit';
  editingView.value = v;
  saveModalNameError.value = null;
  saveModalOpen.value = true;
}
async function onSaveModalSubmit(payload: SaveViewSubmit): Promise<void> {
  saveModalNameError.value = null;
  savingView.value = true;
  const iconEnum = toIconEnumValue(payload.icon);
  try {
    if (saveModalMode.value === 'edit' && editingView.value) {
      await filterTabsStore.update(SAVED_VIEWS_CONTEXT, editingView.value.id, {
        name: payload.name,
        icon: iconEnum,
        filters: serializeSnapshot(),
      });
      toast.success(t('tasks.savedViews.toast.updated'));
    } else {
      await savedViews.saveAs(payload.name, iconEnum);
      toast.success(t('tasks.savedViews.toast.created'));
    }
    saveModalOpen.value = false;
  } catch (err: unknown) {
    const e = err as { fieldErrors?: Record<string, string> };
    const fieldErrors = e.fieldErrors ?? {};
    if (fieldErrors.name) saveModalNameError.value = fieldErrors.name;
    else toast.danger(t('tasks.savedViews.toast.saveError'));
  } finally {
    savingView.value = false;
  }
}

const deleteConfirmOpen = ref(false);
const viewToDelete = ref<FilterTab | null>(null);
const deleteMessage = computed(() =>
  t('tasks.savedViews.confirm.deleteMessage', '', { name: viewToDelete.value?.name ?? '' }),
);
function onDeleteView(v: FilterTab): void {
  viewToDelete.value = v;
  deleteConfirmOpen.value = true;
}
async function onConfirmDeleteView(): Promise<void> {
  if (!viewToDelete.value) return;
  savingView.value = true;
  const wasActive = String(viewToDelete.value.id) === String(savedViews.activeTabId.value);
  try {
    await filterTabsStore.remove(SAVED_VIEWS_CONTEXT, viewToDelete.value.id);
    if (wasActive) savedViews.clearActive();
    toast.success(t('tasks.savedViews.toast.deleted'));
    deleteConfirmOpen.value = false;
    viewToDelete.value = null;
  } catch {
    toast.danger(t('tasks.savedViews.toast.saveError'));
  } finally {
    savingView.value = false;
  }
}
async function moveView(v: FilterTab, dir: -1 | 1): Promise<void> {
  const all = savedViews.tabs.value;
  const idx = all.findIndex((x) => String(x.id) === String(v.id));
  const target = idx + dir;
  if (idx < 0 || target < 0 || target >= all.length) return;
  const ids = all.map((x) => x.id);
  [ids[idx], ids[target]] = [ids[target], ids[idx]];
  try {
    await filterTabsStore.reorder(SAVED_VIEWS_CONTEXT, ids);
    toast.success(t('tasks.savedViews.toast.reordered'));
  } catch {
    toast.danger(t('tasks.savedViews.errors.invalidReorder'));
    void savedViews.load();
  }
}

// ── Overlays ────────────────────────────────────────────────────────────────
const dayPopoverIso = ref<IsoDay | null>(null);
const dayPopoverOpen = computed<boolean>({
  get: () => dayPopoverIso.value !== null,
  set: (open) => {
    if (!open) dayPopoverIso.value = null;
  },
});
const dayPopoverList = computed(() => (dayPopoverIso.value ? (buckets.value.get(dayPopoverIso.value) ?? []) : []));

const previewOccurrence = ref<CalendarOccurrence | null>(null);
const previewOpen = computed<boolean>({
  get: () => previewOccurrence.value !== null,
  set: (open) => {
    if (!open) previewOccurrence.value = null;
  },
});

/**
 * A click on any chip. An EVENT is ours and opens the drawer (a real URL, so it is
 * shareable and Back closes it); anything else opens the read-only preview, because this
 * screen knows nothing about the subject beyond what the occurrence carries.
 */
function onSelectOccurrence(occurrence: CalendarOccurrence): void {
  dayPopoverIso.value = null;
  if (occurrence.subject?.type === 'calendar_event' && occurrence.subject.id) {
    // The square's own `occurrence_date` travels with it — null for a one-off event, which
    // drops the key. NEVER the scope: a click opens the drawer to be READ, and which
    // occurrences a change would touch is a separate, explicit question asked afterwards.
    setQuery(
      {
        event: occurrence.subject.id,
        on: occurrence.occurrence_date,
        at: occurrence.all_day ? null : occurrence.starts_at,
        edit: null,
        new: null,
        date: null,
        scope: null,
      },
      'push',
    );
    return;
  }
  previewOccurrence.value = occurrence;
}

function onOpenSubject(to: { path: string; query?: Record<string, string> }): void {
  previewOccurrence.value = null;
  void router.push(to);
}

function openCreate(iso: IsoDay | null): void {
  dayPopoverIso.value = null;
  setQuery({ new: '1', date: iso, event: null, edit: null, on: null, at: null, scope: null }, 'push');
}

/** Every key the drawer owns, cleared together — one place, so none is ever left behind. */
const DRAWER_KEYS_CLEARED = {
  event: null,
  edit: null,
  new: null,
  date: null,
  on: null,
  at: null,
  scope: null,
} as const;

const drawerOpen = computed<boolean>({
  get: () => isCreating.value || eventParam.value !== null,
  set: (open) => {
    if (!open) setQuery({ ...DRAWER_KEYS_CLEARED });
  },
});
const drawerMode = computed<'view' | 'edit' | 'create'>(() => {
  if (isCreating.value) return 'create';
  return isEditing.value ? 'edit' : 'view';
});

function onEventSaved(): void {
  // The id that came back may be a NEW row (a detached occurrence, the far half of a split).
  // It is deliberately NOT pushed into the URL: the user is looking back at the grid, and the
  // window refetch below is what tells them what actually happened.
  setQuery({ ...DRAWER_KEYS_CLEARED });
  fetchWindow();
}
function onEventDeleted(): void {
  setQuery({ ...DRAWER_KEYS_CLEARED });
  fetchWindow();
}

/**
 * Switch into edit mode AT A SCOPE the user has just chosen. `replace`, not `push`: choosing
 * a scope is a state change inside one screen, not a place to come Back to.
 */
function onRequestEdit(scope: CalendarEventScope): void {
  setQuery({ edit: '1', scope: scope === 'series' ? null : scope });
}

/**
 * "Show the start / the end of the series." The drawer closes with it — the whole point is to
 * look at a month that has something in it, and an open drawer would cover the answer.
 */
function onGoToMonth(month: string): void {
  setQuery({ ...DRAWER_KEYS_CLEARED, month });
}

// ── Loss / failure affordances ──────────────────────────────────────────────
const filterBarRef = ref<HTMLElement | null>(null);

/** Take the user to the control that can actually shrink the answer. */
function focusFilters(selector: string): void {
  const root = filterBarRef.value;
  if (!root) return;
  root.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  root.querySelector<HTMLElement>(selector)?.focus();
}
const onNarrowFilters = (): void => focusFilters('[role="checkbox"]');
const onSearchByName = (): void => focusFilters('input[type="search"]');

/**
 * The failed sources, split by the ONE question a reader has: is clicking something going
 * to help? Names, not ids, and already joined — the alert reads them straight out.
 *
 * Two groups rather than one sentence with a caveat, because the two halves ask for
 * opposite things: one says "try again", the other says "this will not change on its own".
 * A single message would have to be vague enough to cover both, which is where the previous
 * wording ended up. Every entry lands in exactly one group — an unrecognised reason is
 * reported as unrecoverable rather than dropped (see `isRetryableUnavailability`).
 */
const unavailableGroups = computed(() => {
  const retryable: string[] = [];
  const permanent: string[] = [];
  for (const entry of store.unavailableSources) {
    const bucket = isRetryableUnavailability(entry.reason) ? retryable : permanent;
    bucket.push(store.sourceLabel(entry.source));
  }
  return { retryable: retryable.join(', '), permanent: permanent.join(', ') };
});

// ── Surface state ───────────────────────────────────────────────────────────
const initialLoading = computed(() => store.loading && !store.loaded);
const showError = computed(() => store.errored && !store.loaded);
const isEmpty = computed(() => store.loaded && store.occurrences.length === 0);
/** Deterministic, so the skeleton does not flicker between renders. */
const skeletonChips = (index: number): number => index % 4;

const modeOptions = computed<SegmentOption<CalendarMode>[]>(() => [
  { value: 'grid', label: t('calendar.mode.grid'), icon: 'calendar' },
  { value: 'agenda', label: t('calendar.mode.agenda'), icon: 'list' },
]);
const modeModel = computed<CalendarMode>({
  get: () => modeParam.value,
  set: (value) => setQuery({ mode: value === 'agenda' ? 'agenda' : null }),
});

onMounted(() => {
  void savedViews.load();
  fetchWindow();
});
onBeforeUnmount(() => store.resetAll());
</script>

<template>
  <div class="flex flex-col gap-next-6">
    <PageHeader icon="calendar" :title="t('calendar.title')" :description="t('calendar.subtitle')">
      <template #actions>
        <!-- The mode switch DISAPPEARS below next-md rather than going disabled: disabled
             would suggest something is broken, when in fact the grid simply is not a thing
             a phone can show. `?mode` is left in the URL, so rotating the device brings the
             user's own choice back. -->
        <SegmentedControl
          v-if="!forcedAgenda"
          v-model="modeModel"
          size="sm"
          :options="modeOptions"
          :aria-label="t('calendar.mode.label')"
        />
        <Button variant="primary" leading-icon="plus" @click="openCreate(null)">
          {{ t('calendar.event.new') }}
        </Button>
      </template>
    </PageHeader>

    <div ref="filterBarRef">
      <FilterBar
        v-model:search="search"
        searchable
        :search-placeholder="t('calendar.filters.searchPlaceholder')"
        :active-filters="decoratedFilters"
        :clear-all-label="t('calendar.filters.clearAll')"
        @remove-filter="removeFilter"
        @clear-all="clearAll"
        @restore-filter="savedViews.restoreFilter"
      >
        <template #top>
          <FilterTabBar
            :tabs="savedViews.tabs.value"
            :active-tab-id="savedViews.activeTabId.value"
            :dirty="savedViews.dirty.value"
            :loading="savedViews.loading.value"
            :error="savedViews.loadError.value"
            :has-active-filters="hasActiveFilters"
            :busy="savingView"
            @activate="savedViews.applyTab"
            @deactivate="savedViews.clearActive()"
            @save="onSaveActiveView"
            @save-as="onSaveAs"
            @edit="onEditView"
            @delete="onDeleteView"
            @move-up="(v) => moveView(v, -1)"
            @move-down="(v) => moveView(v, 1)"
            @retry="savedViews.load"
          />
        </template>

        <!-- Sources, straight from `meta.sources`. NO date-range control lives here, and
             that is deliberate (spec §9.3): the month navigation IS the range, and a second
             date control would be overwritten by the first click on `›`. -->
        <div class="flex min-w-0 flex-1 basis-full flex-col gap-next-1_5">
          <span class="text-next-xs font-next-medium text-next-muted-foreground">
            {{ t('calendar.filters.sources') }}
          </span>
          <SegmentedControl
            v-model="sourceFilter"
            multiple
            select-all
            size="sm"
            :options="sourceOptions"
            :aria-label="t('calendar.filters.sources')"
          />
        </div>

        <template #results>
          <span class="flex items-center gap-next-2">
            <span>
              {{
                store.occurrences.length > 0
                  ? t('calendar.results.count', '', { n: store.occurrences.length })
                  : t('calendar.results.none')
              }}
            </span>
            <Button v-if="hasActiveFilters" variant="ghost" size="xs" @click="clearAll">
              {{ t('calendar.filters.clearAll') }}
            </Button>
          </span>
        </template>
      </FilterBar>
    </div>

    <!-- A SOURCE FAILED — which is not the same thing as an empty calendar, and must never
         read like one. The backend is fail-soft and returned everything else, so the grid
         below is real, current data. Above the truncation notices and in a different tone:
         this is a breakage, not a limit.

         ONE alert, up to two lines, and the button appears ONLY when there is something a
         click can fix. The line naming the retryable sources sits directly above it, so
         "try again" is never ambiguous about which of them it is offering to help. -->
    <Alert v-if="store.unavailableSources.length" variant="danger" size="sm">
      <span class="flex flex-col gap-next-1">
        <span v-if="unavailableGroups.retryable">
          {{ t('calendar.unavailable.retryable', '', { sources: unavailableGroups.retryable }) }}
        </span>
        <span v-if="unavailableGroups.permanent">
          {{ t('calendar.unavailable.permanent', '', { sources: unavailableGroups.permanent }) }}
        </span>
        <span class="text-next-muted-foreground">{{ t('calendar.unavailable.rest') }}</span>
      </span>
      <template v-if="unavailableGroups.retryable" #actions>
        <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="store.refresh()">
          {{ t('calendar.unavailable.retry') }}
        </Button>
      </template>
    </Alert>

    <TruncationNotices
      :truncations="store.truncations"
      :source-label-of="store.sourceLabel"
      @narrow-filters="onNarrowFilters"
      @search-by-name="onSearchByName"
    />

    <!-- Month navigation. Stays live even while a window loads and even when the whole
         request failed — changing the month IS the retry a user will reach for first. -->
    <div class="flex flex-wrap items-center gap-next-2">
      <Button
        variant="ghost"
        size="icon-sm"
        leading-icon="chevron-left"
        :aria-label="t('calendar.nav.prevMonth')"
        @click="shiftMonth(-1)"
      />
      <h2
        class="text-center text-next-lg font-next-semibold capitalize text-next-fg"
        :style="{ minWidth: headingMinWidth }"
      >
        {{ monthLabel }}
      </h2>
      <Button
        variant="ghost"
        size="icon-sm"
        leading-icon="chevron-right"
        :aria-label="t('calendar.nav.nextMonth')"
        @click="shiftMonth(1)"
      />
      <Button
        variant="secondary"
        size="sm"
        :disabled="todayInView"
        :title="todayInView ? t('calendar.nav.todayDisabled') : undefined"
        @click="goToToday"
      >
        {{ t('calendar.nav.today') }}
      </Button>

      <!-- The zone chip is PERMANENT, for everyone. A chip that only appears for "foreign"
           users is a chip nobody learns to read — and the interface cannot know who is
           foreign anyway. It escalates to a warning when the two zones genuinely differ. -->
      <span class="ml-auto">
        <Badge
          :variant="zoneMismatch ? 'warning' : 'neutral'"
          tone="subtle"
          :icon="zoneMismatch ? 'alert-triangle' : 'clock'"
          :aria-label="
            zoneMismatch
              ? t('calendar.timezone.mismatch', '', { tz: timezone, localTz: browserZone ?? '' })
              : undefined
          "
          :title="
            zoneMismatch
              ? t('calendar.timezone.mismatch', '', { tz: timezone, localTz: browserZone ?? '' })
              : undefined
          "
        >
          {{ t('calendar.timezone.chip', '', { tz: timezone }) }}
        </Badge>
      </span>
    </div>

    <!-- LOADING: the skeleton IS the grid — weekday header, real cells, chip-shaped blocks
         inside them — because a spinner tells you nothing about what is coming. -->
    <div v-if="initialLoading" role="status" :aria-label="t('calendar.loading')">
      <div class="grid grid-cols-7 border-b border-next-border" aria-hidden="true">
        <div v-for="n in 7" :key="`wd-${n}`" class="px-next-1_5 py-next-2">
          <Skeleton variant="text" width="2rem" />
        </div>
      </div>
      <div class="overflow-hidden rounded-b-next-lg border-l border-t border-next-border" aria-hidden="true">
        <div v-for="w in 6" :key="`wk-${w}`" class="grid grid-cols-7">
          <div
            v-for="d in 7"
            :key="`c-${w}-${d}`"
            class="flex min-h-[6.5rem] flex-col gap-next-1 border-b border-r border-next-border p-next-1_5"
          >
            <Skeleton variant="text" width="1.5rem" />
            <Skeleton
              v-for="s in skeletonChips((w - 1) * 7 + d)"
              :key="`s-${w}-${d}-${s}`"
              variant="rect"
              height="1.25rem"
              radius="sm"
            />
          </div>
        </div>
      </div>
    </div>

    <!-- THE WHOLE REQUEST FAILED (not one source). The header, filters and month nav above
         stay usable so the user can change something and retry by doing. -->
    <EmptyState
      v-else-if="showError"
      variant="error"
      :title="t('calendar.error.title')"
      :description="store.error ?? t('calendar.error.description')"
    >
      <template #action>
        <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="store.refresh()">
          {{ t('calendar.error.retry') }}
        </Button>
      </template>
    </EmptyState>

    <!-- AGENDA. Genuinely empty when it has nothing, so it gets an EmptyState — unlike the
         grid, whose empty squares are still a calendar and still somewhere to create. -->
    <template v-else-if="effectiveMode === 'agenda'">
      <EmptyState
        v-if="isEmpty"
        :variant="hasActiveFilters ? 'search' : 'default'"
        :icon="hasActiveFilters ? 'search' : 'calendar'"
        :title="hasActiveFilters ? t('calendar.empty.filtered.title') : t('calendar.empty.title')"
        :description="hasActiveFilters ? t('calendar.empty.filtered.description') : t('calendar.empty.description')"
      >
        <template #action>
          <Button v-if="hasActiveFilters" variant="outline" size="sm" @click="clearAll">
            {{ t('calendar.empty.filtered.action') }}
          </Button>
          <Button v-else variant="primary" size="sm" leading-icon="plus" @click="openCreate(null)">
            {{ t('calendar.empty.action') }}
          </Button>
        </template>
      </EmptyState>

      <AgendaList
        v-else
        :days="windowDays"
        :buckets="buckets"
        :timezone="timezone"
        :locale="locale"
        :today="today"
        :busy="store.refreshing"
        :source-label-of="store.sourceLabel"
        @select="onSelectOccurrence"
        @open-day="(iso) => (dayPopoverIso = iso)"
      />
    </template>

    <!-- GRID. Deliberately NO EmptyState (spec D8): an empty month is still a month, its
         days are still clickable, and an overlay would cover the surface the user is about
         to create something on. The count lives in the FilterBar's results slot. -->
    <MonthGrid
      v-else
      :weeks="weeks"
      :buckets="buckets"
      :timezone="timezone"
      :locale="locale"
      :today="today"
      :month-label="monthLabel"
      :chip-limit="chipLimit"
      :busy="store.refreshing"
      :source-label-of="store.sourceLabel"
      @select="onSelectOccurrence"
      @open-day="(iso) => (dayPopoverIso = iso)"
      @create="openCreate"
      @shift-month="shiftMonth"
    />

    <!-- LEGEND. It explains the ICON, not the colour: a chip's colour comes from urgency or
         state, so one source (`task`) shows danger and neutral chips at once and a colour
         legend would be a lie. A source with nothing this month stays NORMAL — a past month
         has no scheduled automations BY DEFINITION, and dimming it would imply a failure.
         Dimming is reserved for a source that actually failed. -->
    <Surface v-if="store.sources.length" bg="muted" radius="lg" class="p-next-3">
      <div class="flex flex-col gap-next-2">
        <div class="flex flex-wrap items-center gap-next-2">
          <span class="text-next-xs font-next-medium text-next-muted-foreground">
            {{ t('calendar.legend.sources') }}
          </span>
          <span
            v-for="source in store.sources"
            :key="source.id"
            class="inline-flex items-center gap-next-1 text-next-xs"
            :class="
              store.isSourceUnavailable(source.id)
                ? 'text-next-muted-foreground line-through opacity-60'
                : 'text-next-fg'
            "
            :title="store.isSourceUnavailable(source.id) ? t('calendar.legend.unavailable') : undefined"
          >
            <Icon
              :name="store.isSourceUnavailable(source.id) ? 'alert-circle' : sourceIcon(source.id)"
              aria-hidden="true"
            />
            {{ source.label }}
          </span>
        </div>
        <p class="text-next-xs text-next-muted-foreground">{{ t('calendar.legend.colorNote') }}</p>
      </div>
    </Surface>

    <!-- One day, unfolded — and the keyboard's only route to an individual chip. -->
    <DayPopover
      v-model:open="dayPopoverOpen"
      :iso="dayPopoverIso"
      :occurrences="dayPopoverList"
      :timezone="timezone"
      :locale="locale"
      :source-label-of="store.sourceLabel"
      @select="onSelectOccurrence"
      @create="openCreate"
    />

    <!-- A read-only occurrence, built from its own fields only. -->
    <OccurrencePopover
      v-model:open="previewOpen"
      :occurrence="previewOccurrence"
      :timezone="timezone"
      :locale="locale"
      :source-label="previewOccurrence ? store.sourceLabel(previewOccurrence.source) : ''"
      @open="onOpenSubject"
    />

    <EventDrawer
      v-model:open="drawerOpen"
      :key="eventParam ?? 'new'"
      :event-id="eventParam"
      :mode="drawerMode"
      :timezone="timezone"
      :browser-zone="browserZone"
      :locale="locale"
      :seed-date="seedDate"
      :scope="scopeParam"
      :occurrence-date="occurrenceParam"
      :occurrence-starts-at="occurrenceAtParam"
      :occurrence-cadence-label="selectedOccurrence?.cadence_label ?? null"
      @saved="onEventSaved"
      @deleted="onEventDeleted"
      @request-edit="onRequestEdit"
      @cancel-edit="setQuery({ edit: null, scope: null })"
      @go-to-month="onGoToMonth"
    />

    <SaveViewModal
      v-model:open="saveModalOpen"
      :mode="saveModalMode"
      :initial-name="editingView?.name ?? ''"
      :initial-icon="editingView?.icon ?? null"
      :submitting="savingView"
      :name-error="saveModalNameError"
      @submit="onSaveModalSubmit"
    />

    <ConfirmDialog
      v-model:open="deleteConfirmOpen"
      variant="danger"
      :title="t('tasks.savedViews.confirm.deleteTitle')"
      :message="deleteMessage"
      :confirm-label="t('common.delete')"
      :cancel-label="t('common.cancel')"
      :loading="savingView"
      @confirm="onConfirmDeleteView"
    />
  </div>
</template>
