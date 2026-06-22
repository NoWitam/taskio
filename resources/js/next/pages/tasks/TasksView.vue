<script setup lang="ts">
// TasksView — the Tasks BROWSE page for the isolated "next" frontend (Batch 1:
// list / filters / board). Detail Drawer + create Modal land in Batch 2 (seams
// are marked below).
//
// Composition (all design-system components, all strings via t()):
//   • PageHeader with a "New task" primary action (SEAM → Batch 2 create Modal;
//     for now routes to `?new=1`).
//   • FilterBar (all controls `md` via the bar's ambient size): debounced search,
//     priority Select (flag icon), assignee UserSelect (people picker, chips show
//     who is selected), labels LabelSelect (label picker with the AND/OR operator
//     INSIDE its dropdown via v-model:operator), and a SINGLE DateRangeFilter that
//     bundles deadline presets + an explicit from/to range + the "hide without
//     deadline" toggle in one popover. Active-filter chips list each selected
//     person/label by name + an "Any/All" note for labels; clear-all.
//   • A board-only view with Active / Archive / Trash Tabs (legacy parity). The
//     active tab drives which statuses are fetched + shown: Active = the four
//     primary columns (to_do, in_progress, in_test, done); Archive = the archive
//     bucket (with an auto-archive info Alert); Trash = the trash bucket. Each
//     column has independent infinite scroll + skeleton + EmptyState.
//
// Filter state lives HERE and is pushed to the store (reset on change, debounced
// for search) + mirrored into the router query (URL sync).
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import FilterBar, { type ActiveFilter } from '../../ui/patterns/FilterBar.vue';
import FilterTabBar from '../../ui/patterns/FilterTabBar.vue';
import SaveViewModal, {
  type SaveViewSubmit,
  type SaveViewDateModes,
} from '../../ui/patterns/SaveViewModal.vue';
import ConfirmDialog from '../../ui/overlay/ConfirmDialog.vue';
import Tabs, { type TabItem } from '../../ui/navigation/Tabs.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import UserSelect from '../../ui/forms/UserSelect.vue';
import LabelSelect from '../../ui/forms/LabelSelect.vue';
import DateRangeFilter, {
  type DateRangeFilterPreset,
  type DateRangeFilterValue,
} from '../../ui/forms/DateRangeFilter.vue';
import Button from '../../ui/primitives/Button.vue';
import TaskBoardColumn from './TaskBoardColumn.vue';
import TaskDetailsDrawer from './TaskDetailsDrawer.vue';
import TaskFormModal from './TaskFormModal.vue';
import { useTasksStore } from '../../app/stores/tasks';
import { useDebounce } from '../../app/composables/useDebounce';
import { api } from '../../app/lib/api';
import { useI18n } from '../../app/i18n';
import { useToast } from '../../app/composables/useToast';
import {
  useFilterTabs,
  type FilterSnapshot,
} from '../../app/composables/useFilterTabs';
import type { FilterTab } from '../../app/stores/filterTabs';
import { useFilterTabsStore } from '../../app/stores/filterTabs';
import { toIconEnumValue } from '../../ui/forms/filterTabIcon';
import { today, fromIsoDate, toIsoDate, addDays } from '../../ui/forms/date/dateCore';
import {
  ALL_PRIORITIES,
  BOARD_STATUSES,
  priorityMeta,
  statusMeta,
  type TaskFilters,
  type TaskListItem,
  type TaskPriority,
  type TaskStatus,
} from './types';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const store = useTasksStore();
const toast = useToast();
const filterTabsStore = useFilterTabsStore();

// Saved-views context for the Tasks list (per the verified backend contract).
const SAVED_VIEWS_CONTEXT = 'tasks';

// The board exposes three tabs (legacy parity): the Active board (4 columns),
// the Archive bucket (1 column), and the Trash bucket (1 column). The active tab
// drives which statuses are fetched + shown.
type BoardTab = 'active' | 'archive' | 'trash';
const TAB_STATUSES: Record<BoardTab, TaskStatus[]> = {
  active: BOARD_STATUSES,
  archive: ['archive'],
  trash: ['trash'],
};
const ALL_TABS: BoardTab[] = ['active', 'archive', 'trash'];

// --- Filter + tab state (owned here) -------------------------------------
const search = ref('');
const priority = ref<TaskPriority | null>(null);
const assignees = ref<string[]>([]);
const labels = ref<string[]>([]);
const labelOperator = ref<'AND' | 'OR'>('OR');
// Deadline filter is now a SINGLE control: preset + explicit range + the
// "hide without deadline" toggle all live in one model (mirrors the backend
// query params date_preset / date_from / date_to / hide_without_deadline).
const deadline = ref<DateRangeFilterValue>({
  preset: '',
  from: null,
  to: null,
  hide_without_deadline: false,
});

// Resolved option objects (id → {value,label}) surfaced by the multi-selects, so
// the FilterBar can render a chip PER selected person/label with the real name.
const selectedAssignees = ref<{ value: string; label: string }[]>([]);
const selectedLabels = ref<{ value: string; label: string }[]>([]);

// Sticky id→name caches. They ACCUMULATE every name we ever resolve (from the
// multi-selects + the seed lookups) and never forget — so a value REMOVED from
// the active saved view (now a `tab-disabled` "ghost" chip) still shows its real
// name, not its id, even though it's no longer in `selected*`.
const assigneeNameCache = ref<Record<string, string>>({});
const labelNameCache = ref<Record<string, string>>({});

function rememberAssigneeNames(pairs: Array<{ id: string; name: string }>): void {
  if (!pairs.length) return;
  const next = { ...assigneeNameCache.value };
  for (const p of pairs) next[p.id] = p.name;
  assigneeNameCache.value = next;
}
function rememberLabelNames(pairs: Array<{ id: string; name: string }>): void {
  if (!pairs.length) return;
  const next = { ...labelNameCache.value };
  for (const p of pairs) next[p.id] = p.name;
  labelNameCache.value = next;
}

watch(
  selectedAssignees,
  (list) => rememberAssigneeNames(list.map((o) => ({ id: o.value, name: o.label }))),
  { deep: true },
);
watch(
  selectedLabels,
  (list) => rememberLabelNames(list.map((o) => ({ id: o.value, name: o.label }))),
  { deep: true },
);

function assigneeName(id: string): string {
  return assigneeNameCache.value[id] ?? `#${id}`;
}
function labelName(id: string): string {
  return labelNameCache.value[id] ?? `#${id}`;
}

// Seeds fed to the multi-selects so a HARD REFRESH (ids from the URL) resolves to
// real names immediately — the backend resolves ids via `?ids[]=` (same contract
// the legacy app used). Without this the chips would show `#id` until the dropdown
// is opened.
const assigneeSeed = ref<Array<{ id: string; name: string; email?: string | null; avatar?: string | null }>>([]);
const labelSeed = ref<Array<{ id: string; name: string; color?: string | null; icon?: string | null }>>([]);

async function resolveSeedNames(): Promise<void> {
  if (assignees.value.length) {
    try {
      const qs = new URLSearchParams();
      assignees.value.forEach((id) => qs.append('ids[]', id));
      const res = await api.get<{ data: Array<Record<string, unknown>> }>(`/users?${qs.toString()}`);
      assigneeSeed.value = (res.data ?? []).map((u) => ({
        id: String(u.id),
        name: String(u.name ?? u.id),
        email: (u.email as string | null) ?? null,
        avatar: (u.avatar as string | null) ?? null,
      }));
      rememberAssigneeNames(assigneeSeed.value.map((u) => ({ id: u.id, name: u.name })));
    } catch {
      /* best-effort: chips fall back to the id until a dropdown loads */
    }
  }
  if (labels.value.length) {
    try {
      const qs = new URLSearchParams();
      labels.value.forEach((id) => qs.append('ids[]', id));
      const res = await api.get<{ data: Array<Record<string, unknown>> }>(`/labels?${qs.toString()}`);
      labelSeed.value = (res.data ?? []).map((l) => ({
        id: String(l.id),
        name: String(l.name ?? l.id),
        color: (l.color as string | null) ?? null,
        icon: (l.icon as string | null) ?? null,
      }));
      rememberLabelNames(labelSeed.value.map((l) => ({ id: l.id, name: l.name })));
    } catch {
      /* best-effort */
    }
  }
}

const tab = ref<BoardTab>('active');

// --- Options --------------------------------------------------------------
const priorityOptions = computed<SelectOption[]>(() =>
  ALL_PRIORITIES.map((p) => ({ value: p, label: t(`tasks.priorities.${p}`), icon: priorityMeta(p).icon })),
);
const deadlinePresets = computed<DateRangeFilterPreset[]>(() =>
  (['today', 'this_week', 'last_week', 'this_month'] as const).map((p) => ({
    id: p,
    label: t(`tasks.datePresets.${p}`),
  })),
);
// Tabs: Active / Archive / Trash, each with its status icon + i18n label.
const tabItems = computed<TabItem<BoardTab>[]>(() => [
  { value: 'active', label: t('tasks.tabs.active'), icon: 'layout-dashboard' },
  { value: 'archive', label: t('tasks.tabs.archive'), icon: statusMeta('archive').icon },
  { value: 'trash', label: t('tasks.tabs.trash'), icon: statusMeta('trash').icon },
]);

// Columns rendered for the active tab.
const tabStatuses = computed<TaskStatus[]>(() => TAB_STATUSES[tab.value]);

// --- Aggregated filter object (mirrors the query params 1:1) ---------------
const filters = computed<TaskFilters>(() => ({
  search: search.value || undefined,
  priority: priority.value ?? undefined,
  user_id: assignees.value.length ? assignees.value : undefined,
  labels: labels.value.length ? labels.value : undefined,
  // labelOperator only matters when labels are selected.
  labelOperator: labels.value.length ? labelOperator.value : undefined,
  date_from: deadline.value.from ?? undefined,
  date_to: deadline.value.to ?? undefined,
  date_preset: (deadline.value.preset || undefined) as TaskFilters['date_preset'],
  hide_without_deadline: deadline.value.hide_without_deadline || undefined,
}));

const hasActiveFilters = computed(
  () =>
    !!search.value ||
    !!priority.value ||
    assignees.value.length > 0 ||
    labels.value.length > 0 ||
    !!deadline.value.from ||
    !!deadline.value.to ||
    !!deadline.value.preset ||
    deadline.value.hide_without_deadline,
);

// --- Active-filter chips for the FilterBar ---------------------------------
// Names come from the sticky caches (assigneeName / labelName) so chips keep the
// real name even after a value leaves `selected*` (e.g. a removed view value).

// ISO `yyyy-mm-dd` → display `dd.mm.yyyy` for the deadline chips.
function displayDateYmd(ymd: string | null): string {
  if (!ymd) return '';
  const m = String(ymd).match(/^(\d{4})-(\d{2})-(\d{2})$/);
  return m ? `${m[3]}.${m[2]}.${m[1]}` : String(ymd);
}

// Scalar chip keys ENCODE their value (e.g. `priority=high`) so a scalar whose
// value changed against the active view reads as two distinct chips: the new
// value as `extra` and the old value as `tab-disabled` — instead of one chip
// wrongly marked as still matching the view. Multi-value keys already carry the
// id (`user_id:42`), so only scalars need this. Keys MUST stay in lockstep with
// `normalizeSnapshot` so the trit-state comparison lines up.
const activeFilters = computed<ActiveFilter[]>(() => {
  const chips: ActiveFilter[] = [];
  if (search.value) {
    chips.push({ key: `search=${search.value}`, label: t('tasks.filters.chip.search', '', { value: search.value }) });
  }
  if (priority.value) {
    chips.push({
      key: `priority=${priority.value}`,
      label: t('tasks.filters.chip.priority', '', { value: t(`tasks.priorities.${priority.value}`) }),
    });
  }
  // Assignees: one chip PER person, prefixed so the source input is clear.
  if (assignees.value.length) {
    chips.push({
      key: 'user_id',
      values: assignees.value.map((id) => ({
        key: `user_id:${id}`,
        label: `${t('tasks.filters.assignee')}: ${assigneeName(id)}`,
      })),
    });
  }
  // Labels: one chip PER label (prefixed) + the AND/OR operator note when ≥2.
  if (labels.value.length) {
    chips.push({
      key: 'labels',
      values: labels.value.map((id) => ({
        key: `labels:${id}`,
        label: `${t('tasks.filters.labels')}: ${labelName(id)}`,
      })),
      operatorLabel: t('tasks.filters.labels') + ': ' +
        (labelOperator.value === 'AND' ? t('tasks.filters.and') : t('tasks.filters.or')),
    });
  }
  // Deadline range: from and to are INDEPENDENT, so each shows as its own chip.
  if (deadline.value.from) {
    chips.push({
      key: `date_from=${deadline.value.from}`,
      label: t('tasks.filters.chip.dateFrom', '', { value: displayDateYmd(deadline.value.from) }),
    });
  }
  if (deadline.value.to) {
    chips.push({
      key: `date_to=${deadline.value.to}`,
      label: t('tasks.filters.chip.dateTo', '', { value: displayDateYmd(deadline.value.to) }),
    });
  }
  if (deadline.value.preset) {
    chips.push({
      key: `datePreset=${deadline.value.preset}`,
      label: t('tasks.filters.chip.datePreset', '', { value: t(`tasks.datePresets.${deadline.value.preset}`) }),
    });
  }
  if (deadline.value.hide_without_deadline) {
    chips.push({ key: 'hideWithoutDeadline', label: t('tasks.filters.chip.hideWithoutDeadline') });
  }
  return chips;
});

// Split a value-encoded scalar key (`priority=high`) into its base + value.
// Multi-value keys use `:` and have no `=`, so they pass through untouched.
function splitKey(key: string): { base: string; value: string } {
  const eq = key.indexOf('=');
  return eq >= 0
    ? { base: key.slice(0, eq), value: key.slice(eq + 1) }
    : { base: key, value: '' };
}

// Build a readable label for a snapshot-only (`tab-disabled`) chip key. The value
// is carried IN the key (the normalized snapshot key), so the struck-through chip
// names the removed filter using the SNAPSHOT's value — and names persist via the
// sticky caches even after the value left `selected*`.
function disabledChipLabel(key: string): string | null {
  if (key.startsWith('user_id:')) {
    return `${t('tasks.filters.assignee')}: ${assigneeName(key.slice('user_id:'.length))}`;
  }
  if (key.startsWith('labels:')) {
    return `${t('tasks.filters.labels')}: ${labelName(key.slice('labels:'.length))}`;
  }
  const { base, value } = splitKey(key);
  switch (base) {
    case 'search':
      return t('tasks.filters.chip.search', '', { value });
    case 'priority':
      return t('tasks.filters.chip.priority', '', { value: t(`tasks.priorities.${value}`) });
    case 'date_from':
      return t('tasks.filters.chip.dateFrom', '', { value: displayDateYmd(value) });
    case 'date_to':
      return t('tasks.filters.chip.dateTo', '', { value: displayDateYmd(value) });
    case 'datePreset':
      return t('tasks.filters.chip.datePreset', '', { value: t(`tasks.datePresets.${value}`) });
    case 'hideWithoutDeadline':
      return t('tasks.filters.chip.hideWithoutDeadline');
    default:
      return null;
  }
}

// Decorate the page's chips with their saved-view trit-state (no-op when no
// view is active → identical to today). Also injects `tab-disabled` chips for
// snapshot keys removed from the current state, relabeled for readability.
const decoratedFilters = computed<ActiveFilter[]>(() => {
  const decorated = savedViews.decorateActiveFilters(activeFilters.value);
  return decorated.map((f) =>
    f.tabState === 'tab-disabled' && f.label === f.key
      ? { ...f, label: disabledChipLabel(f.key) ?? f.label }
      : f,
  );
});

function removeFilter(key: string): void {
  // Multi-value chips carry a composite key (e.g. `user_id:42`) → remove one value.
  if (key.startsWith('user_id:')) {
    const id = key.slice('user_id:'.length);
    assignees.value = assignees.value.filter((x) => x !== id);
    return;
  }
  if (key.startsWith('labels:')) {
    const id = key.slice('labels:'.length);
    labels.value = labels.value.filter((x) => x !== id);
    if (!labels.value.length) labelOperator.value = 'OR';
    return;
  }
  // Scalar chip keys encode their value (`priority=high`) → switch on the base.
  switch (splitKey(key).base) {
    case 'search':
      search.value = '';
      break;
    case 'priority':
      priority.value = null;
      break;
    case 'user_id':
      assignees.value = [];
      break;
    case 'labels':
      labels.value = [];
      labelOperator.value = 'OR';
      break;
    case 'date_from':
      deadline.value = { ...deadline.value, preset: '', from: null };
      break;
    case 'date_to':
      deadline.value = { ...deadline.value, preset: '', to: null };
      break;
    case 'datePreset':
      deadline.value = { ...deadline.value, preset: '', from: null, to: null };
      break;
    case 'hideWithoutDeadline':
      deadline.value = { ...deadline.value, hide_without_deadline: false };
      break;
  }
}

function clearAll(): void {
  search.value = '';
  priority.value = null;
  assignees.value = [];
  labels.value = [];
  labelOperator.value = 'OR';
  deadline.value = { preset: '', from: null, to: null, hide_without_deadline: false };
}

// --- Saved views (FilterTabs Stage 2) -------------------------------------
// D1 date coding: a concrete deadline endpoint is persisted as either
//   { mode:'absolute', date:'yyyy-mm-dd' }  — a fixed calendar day, or
//   { mode:'relative', offset:<int days from today> } — recomputed on apply.
// Presets persist as-is (date_preset). The bucket (Active/Archive/Trash) is
// NEVER part of the snapshot (D3). Search participates as a plain scalar (D2).
type CodedDate =
  | null
  | { mode: 'absolute'; date: string }
  | { mode: 'relative'; offset: number };

// The per-endpoint date format chosen in the modal for the NEXT save. Live
// serialization (dirty/trit-state) ignores the mode (it only compares
// presence), so a default of 'absolute' is fine outside of an explicit save.
const pendingDateModes = ref<SaveViewDateModes>({ from: 'absolute', to: 'absolute' });

function codeDate(ymd: string | null, mode: 'absolute' | 'relative'): CodedDate {
  if (!ymd) return null;
  if (mode === 'absolute') return { mode: 'absolute', date: ymd };
  const d = fromIsoDate(ymd);
  if (!d) return { mode: 'absolute', date: ymd };
  const offset = Math.round((d.getTime() - today().getTime()) / 86_400_000);
  return { mode: 'relative', offset };
}

function decodeDate(coded: unknown): string | null {
  if (!coded || typeof coded !== 'object') return null;
  const c = coded as { mode?: string; date?: string; offset?: number };
  if (c.mode === 'absolute' && typeof c.date === 'string') return c.date;
  if (c.mode === 'relative' && typeof c.offset === 'number') {
    return toIsoDate(addDays(today(), c.offset));
  }
  return null;
}

function serializeFiltersSnapshot(): FilterSnapshot {
  const snap: FilterSnapshot = {};
  if (search.value) snap.search = search.value;
  if (priority.value) snap.priority = priority.value;
  if (assignees.value.length) snap.user_id = [...assignees.value];
  if (labels.value.length) {
    snap.labels = [...labels.value];
    snap.labelOperator = labelOperator.value;
  }
  if (deadline.value.preset) snap.date_preset = deadline.value.preset;
  const from = codeDate(deadline.value.from, pendingDateModes.value.from);
  const to = codeDate(deadline.value.to, pendingDateModes.value.to);
  if (from) snap.date_from = from;
  if (to) snap.date_to = to;
  if (deadline.value.hide_without_deadline) snap.hide_without_deadline = true;
  return snap;
}

function applyFiltersSnapshot(snap: FilterSnapshot): void {
  search.value = typeof snap.search === 'string' ? snap.search : '';
  priority.value =
    typeof snap.priority === 'string' && (ALL_PRIORITIES as string[]).includes(snap.priority)
      ? (snap.priority as TaskPriority)
      : null;
  assignees.value = Array.isArray(snap.user_id) ? snap.user_id.map(String) : [];
  labels.value = Array.isArray(snap.labels) ? snap.labels.map(String) : [];
  labelOperator.value = snap.labelOperator === 'AND' ? 'AND' : 'OR';
  const preset =
    typeof snap.date_preset === 'string' &&
    ['today', 'this_week', 'last_week', 'this_month'].includes(snap.date_preset)
      ? (snap.date_preset as TaskFilters['date_preset'])
      : '';
  deadline.value = {
    preset: preset ?? '',
    from: decodeDate(snap.date_from),
    to: decodeDate(snap.date_to),
    hide_without_deadline: snap.hide_without_deadline === true,
  };
  // Re-resolve names for assignees/labels restored from the snapshot.
  void resolveSeedNames();
}

// Canonical key SET for dirty-state + chip trit-state (order-insensitive). Keys
// MATCH the chip `key`s produced by `activeFilters` below.
function normalizeSnapshot(snap: FilterSnapshot): string[] {
  const keys: string[] = [];
  // Scalars are value-encoded (matching `activeFilters`) so a changed value
  // produces a DIFFERENT key → old reads as removed, new as added.
  if (snap.search) keys.push(`search=${String(snap.search)}`);
  if (snap.priority) keys.push(`priority=${String(snap.priority)}`);
  if (Array.isArray(snap.user_id)) snap.user_id.map(String).sort().forEach((id) => keys.push(`user_id:${id}`));
  if (Array.isArray(snap.labels)) snap.labels.map(String).sort().forEach((id) => keys.push(`labels:${id}`));
  if (snap.date_preset) keys.push(`datePreset=${String(snap.date_preset)}`);
  const from = decodeDate(snap.date_from);
  const to = decodeDate(snap.date_to);
  if (from) keys.push(`date_from=${from}`);
  if (to) keys.push(`date_to=${to}`);
  if (snap.hide_without_deadline === true) keys.push('hideWithoutDeadline');
  return keys;
}

// Restore ONE chip key from the active view's snapshot (FilterBar "restore").
function restoreSnapshotValue(key: string, snap: FilterSnapshot): void {
  if (key.startsWith('user_id:')) {
    const id = key.slice('user_id:'.length);
    if (!assignees.value.includes(id)) assignees.value = [...assignees.value, id];
    void resolveSeedNames();
    return;
  }
  if (key.startsWith('labels:')) {
    const id = key.slice('labels:'.length);
    if (!labels.value.includes(id)) labels.value = [...labels.value, id];
    if (typeof snap.labelOperator === 'string') {
      labelOperator.value = snap.labelOperator === 'AND' ? 'AND' : 'OR';
    }
    void resolveSeedNames();
    return;
  }
  switch (splitKey(key).base) {
    case 'search':
      search.value = typeof snap.search === 'string' ? snap.search : '';
      break;
    case 'priority':
      priority.value =
        typeof snap.priority === 'string' ? (snap.priority as TaskPriority) : null;
      break;
    case 'date_from':
      deadline.value = { ...deadline.value, preset: '', from: decodeDate(snap.date_from) };
      break;
    case 'date_to':
      deadline.value = { ...deadline.value, preset: '', to: decodeDate(snap.date_to) };
      break;
    case 'datePreset':
      deadline.value = {
        ...deadline.value,
        preset: (snap.date_preset as TaskFilters['date_preset']) ?? '',
        from: null,
        to: null,
      };
      break;
    case 'hideWithoutDeadline':
      deadline.value = { ...deadline.value, hide_without_deadline: true };
      break;
  }
}

const savedViews = useFilterTabs(SAVED_VIEWS_CONTEXT, {
  serialize: serializeFiltersSnapshot,
  apply: applyFiltersSnapshot,
  normalize: normalizeSnapshot,
  restoreValue: restoreSnapshotValue,
});

// Mutation-in-flight flag for the bar's Save / Save-as buttons + delete dialog.
const savingView = ref(false);

function onActivateView(tab: FilterTab): void {
  savedViews.applyTab(tab);
}

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

// --- Save / edit modal ----------------------------------------------------
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
function onEditView(tab: FilterTab): void {
  saveModalMode.value = 'edit';
  editingView.value = tab;
  saveModalNameError.value = null;
  saveModalOpen.value = true;
}

/** Map a 422 fieldErrors map → a name-field i18n key (raw backend key). */
function nameErrorKey(fieldErrors: Record<string, string>): string | null {
  // The backend returns the i18n key as the message (e.g. filter_tabs.errors.name_taken).
  return fieldErrors.name ?? null;
}
/** Non-name 422 keys → a single toast key. */
function otherErrorKey(fieldErrors: Record<string, string>): string | null {
  return (
    fieldErrors.context ?? fieldErrors.filters ?? fieldErrors.icon ?? null
  );
}

async function onSaveModalSubmit(payload: SaveViewSubmit): Promise<void> {
  saveModalNameError.value = null;
  pendingDateModes.value = payload.dateModes;
  savingView.value = true;
  const iconEnum = toIconEnumValue(payload.icon);
  try {
    if (saveModalMode.value === 'edit' && editingView.value) {
      await filterTabsStore.update(SAVED_VIEWS_CONTEXT, editingView.value.id, {
        name: payload.name,
        icon: iconEnum,
        filters: serializeFiltersSnapshot(),
      });
      toast.success(t('tasks.savedViews.toast.updated'));
    } else {
      await savedViews.saveAs(payload.name, iconEnum);
      toast.success(t('tasks.savedViews.toast.created'));
    }
    saveModalOpen.value = false;
  } catch (err: unknown) {
    const e = err as { status?: number; fieldErrors?: Record<string, string> };
    const fieldErrors = e.fieldErrors ?? {};
    const nameKey = nameErrorKey(fieldErrors);
    if (nameKey) {
      saveModalNameError.value = nameKey;
    } else {
      const otherKey = otherErrorKey(fieldErrors);
      toast.danger(otherKey ? t(otherKey) : t('tasks.savedViews.toast.saveError'));
    }
  } finally {
    savingView.value = false;
    // Reset the modes so live dirty-state comparison uses the default again.
    pendingDateModes.value = { from: 'absolute', to: 'absolute' };
  }
}

// --- Delete view (ConfirmDialog) ------------------------------------------
const deleteConfirmOpen = ref(false);
const viewToDelete = ref<FilterTab | null>(null);

function onDeleteView(tab: FilterTab): void {
  viewToDelete.value = tab;
  deleteConfirmOpen.value = true;
}
const deleteMessage = computed(() =>
  t('tasks.savedViews.confirm.deleteMessage', '', { name: viewToDelete.value?.name ?? '' }),
);
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

// --- Reorder via menu (move up/down) --------------------------------------
async function moveView(tab: FilterTab, dir: -1 | 1): Promise<void> {
  const list = savedViews.tabs.value;
  const idx = list.findIndex((t) => String(t.id) === String(tab.id));
  const target = idx + dir;
  if (idx < 0 || target < 0 || target >= list.length) return;
  const ids = list.map((t) => t.id);
  [ids[idx], ids[target]] = [ids[target], ids[idx]];
  try {
    await filterTabsStore.reorder(SAVED_VIEWS_CONTEXT, ids);
    toast.success(t('tasks.savedViews.toast.reordered'));
  } catch (err: unknown) {
    const e = err as { fieldErrors?: Record<string, string> };
    const key = e.fieldErrors?.ids ?? null;
    toast.danger(key ? t(key) : t('tasks.savedViews.errors.invalidReorder'));
    // Refetch to recover the authoritative order on an invalid set.
    void savedViews.load();
  }
}

function onRestoreFilter(key: string): void {
  savedViews.restoreFilter(key);
}

// --- Fetch orchestration --------------------------------------------------
// Which statuses are currently visible (driven by the active tab).
const visibleStatuses = computed<TaskStatus[]>(() => tabStatuses.value);

function refetchVisible(): void {
  visibleStatuses.value.forEach((status) =>
    store.fetchByStatus(status, filters.value, { reset: true }),
  );
}

// Debounce when filters are "applied" (the API call): wait until the user has
// finished choosing rather than firing on every intermediate pick.
const debouncedRefetch = useDebounce(refetchVisible, 800);

// Refetch when the (debounced) filter object changes.
watch(
  filters,
  () => debouncedRefetch(),
  { deep: true },
);

// Refetch immediately when the visible status set changes (view / list status).
watch(visibleStatuses, () => refetchVisible(), { deep: true });

// --- URL sync (filters + view + status) -----------------------------------
let hydrating = false;

function hydrateFromQuery(): void {
  hydrating = true;
  const q = route.query;
  const str = (v: unknown): string => (Array.isArray(v) ? String(v[0] ?? '') : String(v ?? ''));
  const arr = (v: unknown): string[] =>
    v == null ? [] : Array.isArray(v) ? v.map((x) => String(x)) : [String(v)];

  search.value = str(q.search);
  const p = str(q.priority);
  priority.value = (ALL_PRIORITIES as string[]).includes(p) ? (p as TaskPriority) : null;
  assignees.value = arr(q.user_id);
  labels.value = arr(q.labels);
  labelOperator.value = str(q.labelOperator) === 'AND' ? 'AND' : 'OR';
  const preset = str(q.date_preset);
  deadline.value = {
    from: str(q.date_from) || null,
    to: str(q.date_to) || null,
    preset: ['today', 'this_week', 'last_week', 'this_month'].includes(preset) ? preset : '',
    hide_without_deadline: str(q.hide_without_deadline) === '1',
  };

  const tb = str(q.tab);
  tab.value = (ALL_TABS as string[]).includes(tb) ? (tb as BoardTab) : 'active';
  hydrating = false;
}

function syncQuery(): void {
  if (hydrating) return;
  const query: Record<string, string | string[]> = {};
  // Preserve the overlay state keys (detail Drawer / form Modal) so a filter
  // change — or the initial hydrate watcher — never strips a deep-linked task.
  const keep = (key: string): void => {
    const v = route.query[key];
    if (v != null && v !== '') query[key] = Array.isArray(v) ? v.map(String) : String(v);
  };
  keep('task');
  keep('edit');
  keep('new');
  if (search.value) query.search = search.value;
  if (priority.value) query.priority = priority.value;
  if (assignees.value.length) query.user_id = assignees.value;
  if (labels.value.length) {
    query.labels = labels.value;
    query.labelOperator = labelOperator.value;
  }
  if (deadline.value.from) query.date_from = deadline.value.from;
  if (deadline.value.to) query.date_to = deadline.value.to;
  if (deadline.value.preset) query.date_preset = deadline.value.preset;
  if (deadline.value.hide_without_deadline) query.hide_without_deadline = '1';
  if (tab.value !== 'active') query.tab = tab.value;

  void router.replace({ query });
}

watch(
  [filters, tab],
  () => syncQuery(),
  { deep: true },
);

// --- Detail Drawer + create/edit Modal (driven by the route query) --------
// `?task=<id>`            → open the detail Drawer for that task.
// `?task=<id>&edit=1`     → also open the form Modal in EDIT mode.
// `?new=1`                → open the form Modal in CREATE mode.
const str = (v: unknown): string =>
  Array.isArray(v) ? String(v[0] ?? '') : String(v ?? '');

const drawerTaskId = computed<string | null>(() => str(route.query.task) || null);
const drawerOpen = computed<boolean>({
  get: () => drawerTaskId.value !== null,
  set: (value) => {
    if (!value) closeDrawer();
  },
});

const isEditing = computed(() => str(route.query.edit) === '1');
const isCreating = computed(() => str(route.query.new) === '1');

// The form Modal is open for create (?new=1) OR edit (?task=&edit=1).
const formOpen = computed<boolean>({
  get: () => isCreating.value || (drawerTaskId.value !== null && isEditing.value),
  set: (value) => {
    if (!value) closeForm();
  },
});
// In edit mode the store's loaded `detail` (fetched by the Drawer) prefills it.
const formTask = computed(() =>
  isEditing.value && store.detail && String(store.detail.id) === drawerTaskId.value
    ? store.detail
    : null,
);

function dropQueryKeys(keys: string[]): void {
  const query = { ...route.query };
  keys.forEach((k) => delete query[k]);
  void router.replace({ query });
}

function onSelect(task: TaskListItem): void {
  void router.push({ query: { ...route.query, task: String(task.id) } });
}

function onNewTask(): void {
  void router.push({ query: { ...route.query, new: '1' } });
}

function closeDrawer(): void {
  dropQueryKeys(['task', 'edit']);
}

function onDrawerEdit(id: string | number): void {
  void router.replace({ query: { ...route.query, task: String(id), edit: '1' } });
}

function closeForm(): void {
  // Closing CREATE drops `?new`; closing EDIT drops only `?edit` (keep the Drawer).
  if (isCreating.value) dropQueryKeys(['new']);
  else dropQueryKeys(['edit']);
}

// After a create, refresh the visible buckets so a brand-new task surfaces even
// in a bucket the store hadn't initialized yet (upsert only touches fetched
// buckets). Edits/status/delete are reconciled in-place by the store.
function onFormSaved(): void {
  if (isCreating.value) refetchVisible();
}

onMounted(() => {
  hydrateFromQuery();
  void resolveSeedNames();
  void savedViews.load();
  refetchVisible();
});
</script>

<template>
  <!-- Full-height page: PageHeader + FilterBar + the Tabs strip stay fixed at the
       top while the board fills the remaining viewport height and scrolls its own
       columns internally (the page itself never scrolls). `flex-1 min-h-0` makes
       this fill the height region the AppLayout gutter establishes; the gutter
       padding is the comfortable gap around the board. -->
  <div class="flex min-h-0 flex-1 flex-col gap-next-6">
    <PageHeader :title="t('tasks.title')" :description="t('tasks.subtitle')" icon="list-checks">
      <template #actions>
        <Button leading-icon="plus" @click="onNewTask">
          {{ t('tasks.newTask') }}
        </Button>
      </template>
    </PageHeader>

    <FilterBar
      v-model:search="search"
      :search-placeholder="t('tasks.filters.search')"
      :active-filters="decoratedFilters"
      :clear-all-label="t('tasks.filters.clearAll')"
      @remove-filter="removeFilter"
      @clear-all="clearAll"
      @restore-filter="onRestoreFilter"
    >
      <!-- Saved Views toolbar, embedded inside the bar to save vertical space. -->
      <template #top>
        <FilterTabBar
          :tabs="savedViews.tabs.value"
          :active-tab-id="savedViews.activeTabId.value"
          :dirty="savedViews.dirty.value"
          :loading="savedViews.loading.value"
          :error="savedViews.loadError.value"
          :has-active-filters="hasActiveFilters"
          :busy="savingView"
          @activate="onActivateView"
          @deactivate="savedViews.clearActive()"
          @save="onSaveActiveView"
          @save-as="onSaveAs"
          @edit="onEditView"
          @delete="onDeleteView"
          @move-up="(tab) => moveView(tab, -1)"
          @move-down="(tab) => moveView(tab, 1)"
          @retry="savedViews.load"
        />
      </template>

      <!-- Each control is `flex-1` so the row fills 100% width; `min-w-0` lets it
           shrink/truncate (and wrap on narrow screens) rather than overflow.
           Priority: the leading flag names the field; the selected value shows just
           the label (no per-option icon) to avoid two flags side by side — the
           colored icons still show in the menu. -->
      <div class="min-w-0 flex-1 basis-5">
        <Select
          v-model="priority"
          :options="priorityOptions"
          leading-icon="flag"
          :placeholder="t('tasks.filters.priority')"
          :aria-label="t('tasks.filters.priority')"
        >
          <template #value="{ option }">
            <span class="truncate">{{ option.label }}</span>
          </template>
        </Select>
      </div>

      <!-- Assignee (global UserSelect, multiple → shows selected people as chips) -->
      <div class="min-w-0 flex-1 basis-85">
        <UserSelect
          v-model:values="assignees"
          multiple
          :seed="assigneeSeed"
          :placeholder="t('tasks.filters.assignee')"
          :aria-label="t('tasks.filters.assignee')"
          @update:selected="selectedAssignees = $event"
        />
      </div>

      <!-- Labels (global LabelSelect, multiple) + in-dropdown AND/OR operator -->
      <div class="min-w-0 flex-1 basis-55">
        <LabelSelect
          v-model="labels"
          v-model:operator="labelOperator"
          :seed="labelSeed"
          :placeholder="t('tasks.filters.labels')"
          :aria-label="t('tasks.filters.labels')"
          @update:selected="selectedLabels = $event"
        />
      </div>

      <!-- Deadline: ONE control (presets + independent from/to + hide-without-deadline) -->
      <div class="min-w-0 flex-1 basis-30">
        <DateRangeFilter
          v-model="deadline"
          :presets="deadlinePresets"
          show-empty-toggle
          :empty-toggle-label="t('tasks.filters.hideWithoutDeadline')"
          :placeholder="t('tasks.filters.datePresetAll')"
          :aria-label="t('tasks.filters.dateRange')"
        />
      </div>
    </FilterBar>

    <!-- Board with Active / Archive / Trash tabs. The active tab drives which
         status columns are rendered + fetched; each column keeps its own
         infinite-scroll + skeleton + EmptyState behavior (TaskList per status). -->
    <Tabs
      v-model="tab"
      :items="tabItems"
      variant="pills"
      fill
      :aria-label="t('tasks.tabs.label')"
    >
      <!-- ACTIVE: the four primary status columns. The grid FILLS the panel height
           (`flex-1 min-h-0`); each column is full-height and scrolls internally so
           the page itself never scrolls. -->
      <template #panel-active>
        <div
          class="grid min-h-0 flex-1 grid-cols-1 gap-next-4 next-md:grid-cols-2 next-xl:grid-cols-4"
        >
          <TaskBoardColumn
            v-for="status in TAB_STATUSES.active"
            :key="status"
            :status="status"
            :filters="filters"
            :has-active-filters="hasActiveFilters"
            class="min-h-0"
            @select="onSelect"
          />
        </div>
      </template>

      <!-- ARCHIVE: a single column + the auto-archive informational note. The note
           is fixed; the column fills the rest of the panel height. -->
      <template #panel-archive>
        <div class="flex min-h-0 flex-1 flex-col gap-next-4">
          <Alert
            variant="info"
            size="sm"
            :title="t('tasks.autoArchive.title')"
          >
            {{ t('tasks.autoArchive.body') }}
          </Alert>
          <div class="grid min-h-0 flex-1 grid-cols-1 gap-next-4 next-md:max-w-md">
            <TaskBoardColumn
              v-for="status in TAB_STATUSES.archive"
              :key="status"
              :status="status"
              :filters="filters"
              :has-active-filters="hasActiveFilters"
              class="min-h-0"
              @select="onSelect"
            />
          </div>
        </div>
      </template>

      <!-- TRASH: a single column + an informational note (parallel to Archive).
           No backend auto-purge exists, so the note is a generic restore/permanent
           reminder rather than a retention period. -->
      <template #panel-trash>
        <div class="flex min-h-0 flex-1 flex-col gap-next-4">
          <Alert
            variant="info"
            size="sm"
            :title="t('tasks.trashInfo.title')"
          >
            {{ t('tasks.trashInfo.body') }}
          </Alert>
          <div class="grid min-h-0 flex-1 grid-cols-1 gap-next-4 next-md:max-w-md">
            <TaskBoardColumn
              v-for="status in TAB_STATUSES.trash"
              :key="status"
              :status="status"
              :filters="filters"
              :has-active-filters="hasActiveFilters"
              class="min-h-0"
              @select="onSelect"
            />
          </div>
        </div>
      </template>
    </Tabs>

    <!-- Detail Drawer (opened from `?task=<id>`). -->
    <TaskDetailsDrawer
      v-model:open="drawerOpen"
      :task-id="drawerTaskId"
      @edit="onDrawerEdit"
      @close="closeDrawer"
    />

    <!-- Create / edit Modal (opened from `?new=1` or `?task=&edit=1`). -->
    <TaskFormModal
      v-model:open="formOpen"
      :task="formTask"
      @saved="onFormSaved"
    />

    <!-- Saved view: create / edit modal. -->
    <SaveViewModal
      v-model:open="saveModalOpen"
      :mode="saveModalMode"
      :initial-name="editingView?.name ?? ''"
      :initial-icon="editingView?.icon ?? null"
      :snapshot-from="deadline.from"
      :snapshot-to="deadline.to"
      :submitting="savingView"
      :name-error="saveModalNameError"
      @submit="onSaveModalSubmit"
    />

    <!-- Saved view: delete confirmation (danger). -->
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
