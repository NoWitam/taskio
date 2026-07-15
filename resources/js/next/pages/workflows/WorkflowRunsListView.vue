<script setup lang="ts">
// WorkflowRunsListView — the GLOBAL cross-workflow Runs list (next, B5). A
// TOP-LEVEL module list (a child of WorkflowsModuleLayout), so — unlike the §5.1
// detail-nested Runs section — it carries the MANDATORY FilterBar + the #top Saved
// Views FilterTabBar (the project rule: every next top-level list carries Saved
// Views). Mirrors the WorkflowsView / FormSubmissionsView skeleton.
//
// Filters (FilterBar default slot, no text search — runs have no search param):
//   • STATE / SOURCE / TRIGGER `SegmentedControl multiple` (small enums, chosen inline),
//   • a WORKFLOW multi searchable `Select` (seeded from the workflows store) driving
//     the `workflow_id[]` filter (optional scope to one-or-more workflows — global only),
//   • the shared DateRangeFilter (date_from / date_to / date_preset).
// All drive GET /workflows/runs through the workflowRuns store (GLOBAL scope), which
// this list keys separately from the per-workflow feed. Rows are WorkflowRunRow with
// `showWorkflow` (the workflow column); a click opens the SAME run-detail drawer
// (WorkflowRunTimeline) the per-workflow view uses, resolved via the row's own
// `workflow.id`. States: skeleton rows / error+retry / empty / success + infinite scroll.
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import FilterBar, { type ActiveFilter } from '../../ui/patterns/FilterBar.vue';
import FilterTabBar from '../../ui/patterns/FilterTabBar.vue';
import SaveViewModal, { type SaveViewSubmit } from '../../ui/patterns/SaveViewModal.vue';
import ConfirmDialog from '../../ui/overlay/ConfirmDialog.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import DateRangeFilter, {
  type DateRangeFilterPreset,
  type DateRangeFilterValue,
} from '../../ui/forms/DateRangeFilter.vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import WorkflowRunRow from './WorkflowRunRow.vue';
import WorkflowRunTimeline from './WorkflowRunTimeline.vue';
import { runStateIcon, originIcon, triggerIcon, triggerLabel, TRIGGER_TYPES } from './workflowMeta';
import { useWorkflowRunsStore } from '../../app/stores/workflowRuns';
import { useWorkflowsStore } from '../../app/stores/workflows';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useToast } from '../../app/composables/useToast';
import { useFilterTabs, type FilterSnapshot } from '../../app/composables/useFilterTabs';
import { useFilterTabsStore } from '../../app/stores/filterTabs';
import type { FilterTab } from '../../app/stores/filterTabs';
import { toIconEnumValue } from '../../ui/forms/filterTabIcon';
import { useI18n } from '../../app/i18n';
import type { IconName } from '../../ui/primitives/icons';
import { RUN_STATES, RUN_ORIGINS, type WorkflowRun, type WorkflowRunFilters } from './types';

const { t } = useI18n();
const store = useWorkflowRunsStore();
const workflowsStore = useWorkflowsStore();
const toast = useToast();
const filterTabsStore = useFilterTabsStore();

// Saved-views context for the global runs list (any string ≤64; backend-agnostic).
const SAVED_VIEWS_CONTEXT = 'workflow-runs';

// --- Filter state (owned here) --------------------------------------------
const stateFilter = ref<string[]>([]);
const originFilter = ref<string[]>([]);
const triggerFilter = ref<string[]>([]);
const workflowFilter = ref<string[]>([]);
const dateRange = ref<DateRangeFilterValue>({ preset: '', from: null, to: null, hide_without_deadline: false });

// --- Filter options --------------------------------------------------------
// State / source / trigger are small enums → SegmentedControl (inline cards).
const stateOptions = computed<SegmentOption[]>(() =>
  RUN_STATES.map((s) => ({ value: s, label: t(`workflows.runs.state.${s}`), icon: runStateIcon(s) })),
);
const originOptions = computed<SegmentOption[]>(() =>
  RUN_ORIGINS.map((o) => ({ value: o, label: t(`workflows.runs.origin.${o}`), icon: originIcon(o) })),
);
const triggerOptions = computed<SegmentOption[]>(() =>
  TRIGGER_TYPES.map((tt) => ({ value: tt, label: triggerLabel(tt, t), icon: triggerIcon(tt) })),
);
// Workflow has many options → a searchable multi Select (dropdown).
const workflowOptions = computed<SelectOption[]>(() =>
  workflowsStore.items.map((w) => ({ value: w.id, label: w.name, icon: (w.icon as IconName) || 'workflow' })),
);
const datePresets = computed<DateRangeFilterPreset[]>(() =>
  (['today', 'this_week', 'last_week', 'this_month'] as const).map((p) => ({ id: p, label: t(`tasks.datePresets.${p}`) })),
);

// --- Aggregated filter object (mirrors the query params) -------------------
const filters = computed<WorkflowRunFilters>(() => {
  const f: WorkflowRunFilters = {};
  if (stateFilter.value.length) f.state = [...stateFilter.value];
  if (originFilter.value.length) f.origin = [...originFilter.value];
  if (triggerFilter.value.length) f.trigger_type = [...triggerFilter.value];
  if (workflowFilter.value.length) f.workflow_id = [...workflowFilter.value];
  if (dateRange.value.from) f.date_from = dateRange.value.from;
  if (dateRange.value.to) f.date_to = dateRange.value.to;
  if (dateRange.value.preset) f.date_preset = dateRange.value.preset;
  return f;
});

const hasActiveFilters = computed(
  () =>
    stateFilter.value.length > 0 ||
    originFilter.value.length > 0 ||
    triggerFilter.value.length > 0 ||
    workflowFilter.value.length > 0 ||
    !!dateRange.value.preset ||
    !!dateRange.value.from ||
    !!dateRange.value.to,
);

// --- Chip labels -----------------------------------------------------------
function splitKey(key: string): { base: string; value: string } {
  const eq = key.indexOf('=');
  return eq >= 0 ? { base: key.slice(0, eq), value: key.slice(eq + 1) } : { base: key, value: '' };
}
function stateChip(v: string): string {
  return t('workflows.runs.filters.chip.state', '', { value: t(`workflows.runs.state.${v}`) });
}
function originChip(v: string): string {
  return t('workflows.runs.filters.chip.origin', '', { value: t(`workflows.runs.origin.${v}`) });
}
function triggerChip(v: string): string {
  const label = triggerOptions.value.find((o) => o.value === v)?.label ?? v;
  return t('workflows.runs.filters.chip.trigger', '', { value: label });
}
function workflowName(id: string): string {
  return workflowsStore.items.find((w) => w.id === id)?.name ?? id;
}
function workflowChip(v: string): string {
  return t('workflows.runs.filters.chip.workflow', '', { value: workflowName(v) });
}
function displayDate(ymd: string | null): string {
  if (!ymd) return '';
  const m = String(ymd).match(/^(\d{4})-(\d{2})-(\d{2})$/);
  return m ? `${m[3]}.${m[2]}.${m[1]}` : String(ymd);
}

const activeFilters = computed<ActiveFilter[]>(() => {
  const chips: ActiveFilter[] = [];
  if (stateFilter.value.length) {
    chips.push({ key: 'state', values: stateFilter.value.map((v) => ({ key: `state:${v}`, label: stateChip(v) })) });
  }
  if (originFilter.value.length) {
    chips.push({ key: 'origin', values: originFilter.value.map((v) => ({ key: `origin:${v}`, label: originChip(v) })) });
  }
  if (triggerFilter.value.length) {
    chips.push({ key: 'trigger', values: triggerFilter.value.map((v) => ({ key: `trigger:${v}`, label: triggerChip(v) })) });
  }
  if (workflowFilter.value.length) {
    chips.push({ key: 'workflow', values: workflowFilter.value.map((v) => ({ key: `workflow:${v}`, label: workflowChip(v) })) });
  }
  if (dateRange.value.preset) chips.push({ key: `datePreset=${dateRange.value.preset}`, label: t(`tasks.datePresets.${dateRange.value.preset}`) });
  if (dateRange.value.from) chips.push({ key: `date_from=${dateRange.value.from}`, label: t('workflows.runs.filters.chip.dateFrom', '', { value: displayDate(dateRange.value.from) }) });
  if (dateRange.value.to) chips.push({ key: `date_to=${dateRange.value.to}`, label: t('workflows.runs.filters.chip.dateTo', '', { value: displayDate(dateRange.value.to) }) });
  return chips;
});

function disabledChipLabel(key: string): string | null {
  if (key.startsWith('state:')) return stateChip(key.slice('state:'.length));
  if (key.startsWith('origin:')) return originChip(key.slice('origin:'.length));
  if (key.startsWith('trigger:')) return triggerChip(key.slice('trigger:'.length));
  if (key.startsWith('workflow:')) return workflowChip(key.slice('workflow:'.length));
  const { base, value } = splitKey(key);
  switch (base) {
    case 'datePreset': return t(`tasks.datePresets.${value}`);
    case 'date_from': return t('workflows.runs.filters.chip.dateFrom', '', { value: displayDate(value) });
    case 'date_to': return t('workflows.runs.filters.chip.dateTo', '', { value: displayDate(value) });
    default: return null;
  }
}

const decoratedFilters = computed<ActiveFilter[]>(() => {
  const decorated = savedViews.decorateActiveFilters(activeFilters.value);
  return decorated.map((f) =>
    f.tabState === 'tab-disabled' && f.label === f.key ? { ...f, label: disabledChipLabel(f.key) ?? f.label } : f,
  );
});

function removeFilter(key: string): void {
  if (key.startsWith('state:')) { stateFilter.value = stateFilter.value.filter((v) => v !== key.slice('state:'.length)); return; }
  if (key.startsWith('origin:')) { originFilter.value = originFilter.value.filter((v) => v !== key.slice('origin:'.length)); return; }
  if (key.startsWith('trigger:')) { triggerFilter.value = triggerFilter.value.filter((v) => v !== key.slice('trigger:'.length)); return; }
  if (key.startsWith('workflow:')) { workflowFilter.value = workflowFilter.value.filter((v) => v !== key.slice('workflow:'.length)); return; }
  switch (splitKey(key).base) {
    case 'datePreset': dateRange.value = { ...dateRange.value, preset: '', from: null, to: null }; break;
    case 'date_from': dateRange.value = { ...dateRange.value, preset: '', from: null }; break;
    case 'date_to': dateRange.value = { ...dateRange.value, preset: '', to: null }; break;
  }
}
function clearAll(): void {
  stateFilter.value = [];
  originFilter.value = [];
  triggerFilter.value = [];
  workflowFilter.value = [];
  dateRange.value = { preset: '', from: null, to: null, hide_without_deadline: false };
}

// --- Saved views -----------------------------------------------------------
function serializeFiltersSnapshot(): FilterSnapshot {
  const snap: FilterSnapshot = {};
  if (stateFilter.value.length) snap.state = [...stateFilter.value];
  if (originFilter.value.length) snap.origin = [...originFilter.value];
  if (triggerFilter.value.length) snap.trigger_type = [...triggerFilter.value];
  if (workflowFilter.value.length) snap.workflow_id = [...workflowFilter.value];
  if (dateRange.value.preset) snap.date_preset = dateRange.value.preset;
  if (dateRange.value.from) snap.date_from = dateRange.value.from;
  if (dateRange.value.to) snap.date_to = dateRange.value.to;
  return snap;
}
function snapArr(v: unknown): string[] {
  return Array.isArray(v) ? v.map(String) : v != null && v !== '' ? [String(v)] : [];
}
function applyFiltersSnapshot(snap: FilterSnapshot): void {
  stateFilter.value = snapArr(snap.state);
  originFilter.value = snapArr(snap.origin);
  triggerFilter.value = snapArr(snap.trigger_type);
  workflowFilter.value = snapArr(snap.workflow_id);
  dateRange.value = {
    preset: (typeof snap.date_preset === 'string' ? snap.date_preset : '') as DateRangeFilterValue['preset'],
    from: typeof snap.date_from === 'string' ? snap.date_from : null,
    to: typeof snap.date_to === 'string' ? snap.date_to : null,
    hide_without_deadline: false,
  };
}
function normalizeSnapshot(snap: FilterSnapshot): string[] {
  const keys: string[] = [];
  snapArr(snap.state).sort().forEach((v) => keys.push(`state:${v}`));
  snapArr(snap.origin).sort().forEach((v) => keys.push(`origin:${v}`));
  snapArr(snap.trigger_type).sort().forEach((v) => keys.push(`trigger:${v}`));
  snapArr(snap.workflow_id).sort().forEach((v) => keys.push(`workflow:${v}`));
  if (snap.date_preset) keys.push(`datePreset=${String(snap.date_preset)}`);
  if (snap.date_from) keys.push(`date_from=${String(snap.date_from)}`);
  if (snap.date_to) keys.push(`date_to=${String(snap.date_to)}`);
  return keys;
}
function restoreSnapshotValue(key: string, snap: FilterSnapshot): void {
  if (key.startsWith('state:')) { const v = key.slice('state:'.length); if (!stateFilter.value.includes(v)) stateFilter.value = [...stateFilter.value, v]; return; }
  if (key.startsWith('origin:')) { const v = key.slice('origin:'.length); if (!originFilter.value.includes(v)) originFilter.value = [...originFilter.value, v]; return; }
  if (key.startsWith('trigger:')) { const v = key.slice('trigger:'.length); if (!triggerFilter.value.includes(v)) triggerFilter.value = [...triggerFilter.value, v]; return; }
  if (key.startsWith('workflow:')) { const v = key.slice('workflow:'.length); if (!workflowFilter.value.includes(v)) workflowFilter.value = [...workflowFilter.value, v]; return; }
  switch (splitKey(key).base) {
    case 'datePreset': dateRange.value = { ...dateRange.value, preset: (snap.date_preset as DateRangeFilterValue['preset']) ?? '', from: null, to: null }; break;
    case 'date_from': dateRange.value = { ...dateRange.value, preset: '', from: typeof snap.date_from === 'string' ? snap.date_from : null }; break;
    case 'date_to': dateRange.value = { ...dateRange.value, preset: '', to: typeof snap.date_to === 'string' ? snap.date_to : null }; break;
  }
}

const savedViews = useFilterTabs(SAVED_VIEWS_CONTEXT, {
  serialize: serializeFiltersSnapshot,
  apply: applyFiltersSnapshot,
  normalize: normalizeSnapshot,
  restoreValue: restoreSnapshotValue,
});
const savingView = ref(false);

function onActivateView(v: FilterTab): void {
  savedViews.applyTab(v);
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
      await filterTabsStore.update(SAVED_VIEWS_CONTEXT, editingView.value.id, { name: payload.name, icon: iconEnum, filters: serializeFiltersSnapshot() });
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
    else {
      const otherKey = fieldErrors.context ?? fieldErrors.filters ?? fieldErrors.icon ?? null;
      toast.danger(otherKey ? t(otherKey) : t('tasks.savedViews.toast.saveError'));
    }
  } finally {
    savingView.value = false;
  }
}

const deleteConfirmOpen = ref(false);
const viewToDelete = ref<FilterTab | null>(null);
function onDeleteView(v: FilterTab): void {
  viewToDelete.value = v;
  deleteConfirmOpen.value = true;
}
const deleteMessage = computed(() => t('tasks.savedViews.confirm.deleteMessage', '', { name: viewToDelete.value?.name ?? '' }));
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
  const list = savedViews.tabs.value;
  const idx = list.findIndex((x) => String(x.id) === String(v.id));
  const target = idx + dir;
  if (idx < 0 || target < 0 || target >= list.length) return;
  const ids = list.map((x) => x.id);
  [ids[idx], ids[target]] = [ids[target], ids[idx]];
  try {
    await filterTabsStore.reorder(SAVED_VIEWS_CONTEXT, ids);
    toast.success(t('tasks.savedViews.toast.reordered'));
  } catch (err: unknown) {
    const e = err as { fieldErrors?: Record<string, string> };
    toast.danger(e.fieldErrors?.ids ? t(e.fieldErrors.ids) : t('tasks.savedViews.errors.invalidReorder'));
    void savedViews.load();
  }
}
function onRestoreFilter(key: string): void {
  savedViews.restoreFilter(key);
}

// --- Fetch orchestration (GLOBAL scope) -----------------------------------
function refetch(): void {
  void store.fetchRuns(null, filters.value, { reset: true, scope: 'global' });
}
watch(filters, () => refetch(), { deep: true });

// --- List view-state ------------------------------------------------------
const items = computed(() => store.items);
const initialLoading = computed(() => store.loading && items.value.length === 0);
const isEmpty = computed(() => !store.loading && !store.loadingMore && !store.errored && items.value.length === 0);
const skeletonKeys = Array.from({ length: 6 }, (_, i) => i);

const { sentinelRef } = useInfiniteScroll({
  onLoadMore: () => void store.loadMore(null, filters.value, { scope: 'global' }),
  canLoadMore: () =>
    store.hasMore && !store.loading && !store.loadingMore && !store.errored && !store.loadMoreErrored,
});

// --- Run detail drawer (local; resolved via the row's own workflow.id) -----
const selectedRun = ref<WorkflowRun | null>(null);
const drawerOpen = computed<boolean>({
  get: () => selectedRun.value !== null,
  set: (open) => {
    if (!open) selectedRun.value = null;
  },
});
// '' (falsy) when a row somehow carries no workflow → the drawer body v-if hides it.
const selectedWorkflowId = computed(() => selectedRun.value?.workflow?.id ?? '');
function openRun(run: WorkflowRun): void {
  selectedRun.value = run;
}

/**
 * A FAILED run was retried (WorkflowRunTimeline `@retried`) → a NEW run on the SAME
 * workflow. The 202/show body omits the nested `workflow` (index-only), so reuse the
 * current row's workflow identity to keep `selectedWorkflowId` valid, swap the drawer to
 * the new run (the timeline re-keys on the id and fetches its pending detail), and refresh
 * the list so the new run surfaces at the top.
 */
function onRetried(newRun: WorkflowRun): void {
  selectedRun.value = { ...newRun, workflow: selectedRun.value?.workflow ?? null };
  refetch();
}

onMounted(() => {
  void savedViews.load();
  // Seed the workflow filter's options (only if the list isn't already populated so
  // we don't clobber a list the browse view left loaded).
  if (workflowsStore.items.length === 0) void workflowsStore.fetchWorkflows({}, { reset: true });
  refetch();
});
// Reset the shared singleton so a global page never bleeds into a per-workflow view.
onUnmounted(() => store.resetAll());
</script>

<template>
  <div class="flex flex-col gap-next-6">
    <PageHeader
      icon="clock"
      :title="t('workflows.runs.global.title')"
      :description="t('workflows.runs.global.subtitle')"
    />

    <FilterBar
      :searchable="false"
      :aria-label="t('workflows.runs.global.title')"
      :active-filters="decoratedFilters"
      :clear-all-label="t('workflows.filters.clearAll')"
      @remove-filter="removeFilter"
      @clear-all="clearAll"
      @restore-filter="onRestoreFilter"
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
          @activate="onActivateView"
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

      <!-- State / source / trigger: small enums as SegmentedControl (multiple). -->
      <div class="flex min-w-0 flex-1 basis-full flex-col gap-next-1_5">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('workflows.runs.filters.stateLabel') }}</span>
        <SegmentedControl v-model="stateFilter" multiple size="sm" :options="stateOptions" :aria-label="t('workflows.runs.filters.stateLabel')" />
      </div>
      <div class="flex min-w-0 flex-1 basis-72 flex-col gap-next-1_5">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('workflows.runs.filters.originLabel') }}</span>
        <SegmentedControl v-model="originFilter" multiple size="sm" :options="originOptions" :aria-label="t('workflows.runs.filters.originLabel')" />
      </div>
      <div class="flex min-w-0 flex-1 basis-72 flex-col gap-next-1_5">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('workflows.runs.filters.triggerLabel') }}</span>
        <SegmentedControl v-model="triggerFilter" multiple size="sm" :options="triggerOptions" :aria-label="t('workflows.runs.filters.triggerLabel')" />
      </div>
      <!-- Workflow: many options → a searchable MULTI Select (dropdown). -->
      <div class="flex min-w-0 flex-1 basis-60 flex-col gap-next-1_5">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('workflows.runs.filters.workflowLabel') }}</span>
        <Select v-model:values="workflowFilter" multiple searchable :options="workflowOptions" leading-icon="workflow" :placeholder="t('workflows.runs.filters.anyWorkflow')" :search-placeholder="t('workflows.runs.filters.workflowSearchPlaceholder')" :aria-label="t('workflows.runs.filters.workflowLabel')" />
      </div>
      <div class="flex min-w-0 flex-1 basis-60 flex-col gap-next-1_5">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('workflows.runs.filters.dateLabel') }}</span>
        <DateRangeFilter v-model="dateRange" :presets="datePresets" :placeholder="t('workflows.runs.filters.dateAny')" :aria-label="t('workflows.runs.filters.dateLabel')" />
      </div>
    </FilterBar>

    <div class="flex flex-col gap-next-4">
      <!-- Error (initial load failed) with retry. -->
      <EmptyState
        v-if="store.errored && items.length === 0"
        variant="error"
        :title="t('workflows.runs.global.error.title')"
        :description="t('workflows.runs.global.error.description')"
      >
        <template #action>
          <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="refetch">
            {{ t('workflows.errors.retry') }}
          </Button>
        </template>
      </EmptyState>

      <!-- Initial loading: several row-shaped skeletons (never a spinner). -->
      <ul v-else-if="initialLoading" class="flex flex-col gap-next-2" aria-hidden="true">
        <li
          v-for="n in skeletonKeys"
          :key="`sk-${n}`"
          class="flex flex-col gap-next-2 rounded-next-md border border-next-border p-next-3"
        >
          <Skeleton variant="text" width="40%" />
          <div class="flex items-center gap-next-2">
            <Skeleton variant="rect" width="5rem" height="1.25rem" radius="full" />
            <Skeleton variant="rect" width="6rem" height="1.25rem" radius="full" />
          </div>
          <Skeleton variant="text" width="55%" />
        </li>
      </ul>

      <!-- Empty: filtered "no results" vs first-run copy. -->
      <EmptyState
        v-else-if="isEmpty"
        :variant="hasActiveFilters ? 'search' : 'default'"
        :icon="hasActiveFilters ? 'search' : 'clock'"
        :title="hasActiveFilters ? t('workflows.runs.global.empty.searchTitle') : t('workflows.runs.global.empty.title')"
        :description="hasActiveFilters ? t('workflows.runs.global.empty.searchDescription') : t('workflows.runs.global.empty.description')"
      />

      <!-- Success: the run rows + load-more + retryable append + sentinel. -->
      <template v-else>
        <ul class="flex flex-col gap-next-2" :aria-label="t('workflows.runs.global.title')">
          <li v-for="run in items" :key="run.id">
            <WorkflowRunRow :run="run" show-workflow @open="openRun" />
          </li>
        </ul>

        <!-- Appending: a few row-shaped skeletons (never a spinner). -->
        <ul v-if="store.loadingMore" class="flex flex-col gap-next-2" aria-hidden="true">
          <li
            v-for="n in 3"
            :key="`more-sk-${n}`"
            class="flex flex-col gap-next-2 rounded-next-md border border-next-border p-next-3"
          >
            <Skeleton variant="text" width="40%" />
            <div class="flex items-center gap-next-2">
              <Skeleton variant="rect" width="5rem" height="1.25rem" radius="full" />
              <Skeleton variant="rect" width="6rem" height="1.25rem" radius="full" />
            </div>
            <Skeleton variant="text" width="55%" />
          </li>
        </ul>

        <!-- Inline "load more" error with retry (keeps the loaded list visible). -->
        <Alert v-if="store.loadMoreErrored && items.length > 0" variant="danger" size="sm">
          <div class="flex items-center justify-between gap-next-2">
            <span>{{ t('workflows.runs.global.error.description') }}</span>
            <Button variant="ghost" size="xs" leading-icon="rotate-ccw" @click="store.retryLoadMore(null, filters, { scope: 'global' })">
              {{ t('workflows.errors.retry') }}
            </Button>
          </div>
        </Alert>

        <div
          v-if="store.hasMore && !store.errored && !store.loadMoreErrored"
          ref="sentinelRef"
          aria-hidden="true"
          class="h-px w-full"
        />
      </template>
    </div>

    <!-- Run detail drawer (reuses WorkflowRunTimeline; workflow id from the row). -->
    <Drawer
      v-model:open="drawerOpen"
      side="right"
      size="lg"
      :show-close="false"
      :aria-label="t('workflows.runs.detail.title')"
    >
      <WorkflowRunTimeline
        v-if="selectedRun && selectedWorkflowId"
        :key="selectedRun.id"
        :workflow-id="selectedWorkflowId"
        :run-id="selectedRun.id"
        @close="drawerOpen = false"
        @retried="onRetried"
      />
    </Drawer>

    <!-- Saved view: create / edit modal. -->
    <SaveViewModal
      v-model:open="saveModalOpen"
      :mode="saveModalMode"
      :initial-name="editingView?.name ?? ''"
      :initial-icon="editingView?.icon ?? null"
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
