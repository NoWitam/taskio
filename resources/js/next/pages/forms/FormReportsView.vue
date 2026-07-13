<script setup lang="ts">
// FormReportsView — a form's AI reports (`/forms/:id/reports`).
//
// A sub-view of FormsModuleLayout (the open form comes from FORM_MODULE_CTX).
// Mirrors the other list screens: Active / Deleted TABS, a FilterBar with the
// Saved Views toolbar (search, status, sort, date), a cursor-paginated list of
// ReportCard, a create-report Drawer, and a detail Drawer (preview + download +
// delete/restore/force). Reports are generated asynchronously, so pending ones
// are POLLED until completed. All strings via i18n.
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import FilterBar, { type ActiveFilter } from '../../ui/patterns/FilterBar.vue';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import FilterTabBar from '../../ui/patterns/FilterTabBar.vue';
import SaveViewModal, { type SaveViewSubmit } from '../../ui/patterns/SaveViewModal.vue';
import ConfirmDialog from '../../ui/overlay/ConfirmDialog.vue';
import Tabs, { type TabItem } from '../../ui/navigation/Tabs.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import DateRangeFilter, { type DateRangeFilterPreset, type DateRangeFilterValue } from '../../ui/forms/DateRangeFilter.vue';
import Button from '../../ui/primitives/Button.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import EntityCard from '../../ui/patterns/EntityCard.vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import MarkdownViewer from '../../ui/editor/MarkdownViewer.vue';
import ReportCard from './ReportCard.vue';
import ReportFormView from './ReportFormView.vue';
import { useFormsStore } from '../../app/stores/forms';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useDebounce } from '../../app/composables/useDebounce';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useFilterTabs, type FilterSnapshot } from '../../app/composables/useFilterTabs';
import { useFilterTabsStore } from '../../app/stores/filterTabs';
import type { FilterTab } from '../../app/stores/filterTabs';
import { toIconEnumValue } from '../../ui/forms/filterTabIcon';
import { useI18n } from '../../app/i18n';
import { FORM_MODULE_CTX } from './formContext';
import { hydrateTab, serializeTabQuery } from './tabQuery';
import type { FormReport, ReportFilters } from './types';

const route = useRoute();
const router = useRouter();
const store = useFormsStore();
const toast = useToast();
const confirm = useConfirm();
const filterTabsStore = useFilterTabsStore();
const { t } = useI18n();

const SAVED_VIEWS_CONTEXT = 'form-reports';

const ctx = inject(FORM_MODULE_CTX);
const form = computed(() => ctx?.form.value ?? null);
const formId = computed(() => String(route.params.id));

// --- Tab (bucket) ---------------------------------------------------------
type RepTab = 'active' | 'trash';
const tab = ref<RepTab>('active');
const isTrashed = computed(() => tab.value === 'trash');
const tabItems = computed<TabItem<RepTab>[]>(() => [
  { value: 'active', label: t('forms.reports.tabs.active'), icon: 'file-text' },
  { value: 'trash', label: t('forms.reports.tabs.trash'), icon: 'trash' },
]);

// --- Filter state ---------------------------------------------------------
const search = ref('');
const status = ref<'' | 'completed' | 'pending'>('');
const sort = ref<'newest' | 'oldest'>('newest');
const dateRange = ref<DateRangeFilterValue>({ preset: '', from: null, to: null, hide_without_deadline: false });

const statusOptions = computed<SelectOption[]>(() => [
  { value: '', label: t('forms.reports.statusAll') },
  { value: 'completed', label: t('forms.reports.completed') },
  { value: 'pending', label: t('forms.reports.pending') },
]);
const sortOptions = computed<SelectOption[]>(() => [
  { value: 'newest', label: t('forms.submissions.sortNewest') },
  { value: 'oldest', label: t('forms.submissions.sortOldest') },
]);
const datePresets = computed<DateRangeFilterPreset[]>(() =>
  (['today', 'this_week', 'last_week', 'this_month'] as const).map((p) => ({ id: p, label: t(`tasks.datePresets.${p}`) })),
);

const filters = computed<ReportFilters>(() => ({
  search: search.value || undefined,
  trashed: isTrashed.value ? true : undefined,
  only_completed: status.value === 'completed' ? true : undefined,
  only_pending: status.value === 'pending' ? true : undefined,
  sort: sort.value,
  date_preset: (dateRange.value.preset || undefined) as ReportFilters['date_preset'],
  date_from: dateRange.value.from ?? undefined,
  date_to: dateRange.value.to ?? undefined,
}));

const hasActiveFilters = computed(
  () => !!search.value || status.value !== '' || !!dateRange.value.preset || !!dateRange.value.from || !!dateRange.value.to,
);

// --- Active-filter chips --------------------------------------------------
function splitKey(key: string): { base: string; value: string } {
  const eq = key.indexOf('=');
  return eq >= 0 ? { base: key.slice(0, eq), value: key.slice(eq + 1) } : { base: key, value: '' };
}
function displayDate(ymd: string | null): string {
  if (!ymd) return '';
  const m = String(ymd).match(/^(\d{4})-(\d{2})-(\d{2})$/);
  return m ? `${m[3]}.${m[2]}.${m[1]}` : String(ymd);
}
function statusLabel(value: string): string {
  return value === 'completed' ? t('forms.reports.completed') : t('forms.reports.pending');
}

const activeFilters = computed<ActiveFilter[]>(() => {
  const chips: ActiveFilter[] = [];
  if (search.value) chips.push({ key: `search=${search.value}`, label: t('forms.filters.chip.search', '', { value: search.value }) });
  if (status.value !== '') chips.push({ key: `status=${status.value}`, label: statusLabel(status.value) });
  if (dateRange.value.preset) chips.push({ key: `datePreset=${dateRange.value.preset}`, label: t(`tasks.datePresets.${dateRange.value.preset}`) });
  if (dateRange.value.from) chips.push({ key: `date_from=${dateRange.value.from}`, label: t('forms.submissions.dateFrom', '', { value: displayDate(dateRange.value.from) }) });
  if (dateRange.value.to) chips.push({ key: `date_to=${dateRange.value.to}`, label: t('forms.submissions.dateTo', '', { value: displayDate(dateRange.value.to) }) });
  return chips;
});

function disabledChipLabel(key: string): string | null {
  const { base, value } = splitKey(key);
  switch (base) {
    case 'search': return t('forms.filters.chip.search', '', { value });
    case 'status': return statusLabel(value);
    case 'datePreset': return t(`tasks.datePresets.${value}`);
    case 'date_from': return t('forms.submissions.dateFrom', '', { value: displayDate(value) });
    case 'date_to': return t('forms.submissions.dateTo', '', { value: displayDate(value) });
    default: return null;
  }
}
const decoratedFilters = computed<ActiveFilter[]>(() => {
  const decorated = savedViews.decorateActiveFilters(activeFilters.value);
  return decorated.map((f) => (f.tabState === 'tab-disabled' && f.label === f.key ? { ...f, label: disabledChipLabel(f.key) ?? f.label } : f));
});

function removeFilter(key: string): void {
  switch (splitKey(key).base) {
    case 'search': search.value = ''; break;
    case 'status': status.value = ''; break;
    case 'datePreset': dateRange.value = { ...dateRange.value, preset: '', from: null, to: null }; break;
    case 'date_from': dateRange.value = { ...dateRange.value, preset: '', from: null }; break;
    case 'date_to': dateRange.value = { ...dateRange.value, preset: '', to: null }; break;
  }
}
function clearAll(): void {
  search.value = '';
  status.value = '';
  dateRange.value = { preset: '', from: null, to: null, hide_without_deadline: false };
}

// --- Saved views ----------------------------------------------------------
function serializeFiltersSnapshot(): FilterSnapshot {
  const snap: FilterSnapshot = {};
  if (search.value) snap.search = search.value;
  if (status.value !== '') snap.status = status.value;
  if (dateRange.value.preset) snap.date_preset = dateRange.value.preset;
  if (dateRange.value.from) snap.date_from = dateRange.value.from;
  if (dateRange.value.to) snap.date_to = dateRange.value.to;
  return snap;
}
function applyFiltersSnapshot(snap: FilterSnapshot): void {
  search.value = typeof snap.search === 'string' ? snap.search : '';
  status.value = snap.status === 'completed' ? 'completed' : snap.status === 'pending' ? 'pending' : '';
  dateRange.value = {
    preset: (typeof snap.date_preset === 'string' ? snap.date_preset : '') as DateRangeFilterValue['preset'],
    from: typeof snap.date_from === 'string' ? snap.date_from : null,
    to: typeof snap.date_to === 'string' ? snap.date_to : null,
    hide_without_deadline: false,
  };
}
function normalizeSnapshot(snap: FilterSnapshot): string[] {
  const keys: string[] = [];
  if (snap.search) keys.push(`search=${String(snap.search)}`);
  if (snap.status) keys.push(`status=${String(snap.status)}`);
  if (snap.date_preset) keys.push(`datePreset=${String(snap.date_preset)}`);
  if (snap.date_from) keys.push(`date_from=${String(snap.date_from)}`);
  if (snap.date_to) keys.push(`date_to=${String(snap.date_to)}`);
  return keys;
}
function restoreSnapshotValue(key: string, snap: FilterSnapshot): void {
  switch (splitKey(key).base) {
    case 'search': search.value = typeof snap.search === 'string' ? snap.search : ''; break;
    case 'status': status.value = snap.status === 'completed' ? 'completed' : snap.status === 'pending' ? 'pending' : ''; break;
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

// --- Fetch orchestration --------------------------------------------------
function refetch(): void {
  store.fetchReports(formId.value, filters.value, { reset: true });
}
const debouncedRefetch = useDebounce(refetch, 400);
watch(search, () => debouncedRefetch());
watch([status, sort, dateRange, tab], () => refetch(), { deep: true });

// --- URL sync (bucket tab only; every other query key is preserved) --------
let hydrating = false;

function hydrateFromQuery(): void {
  hydrating = true;
  tab.value = hydrateTab(route.query);
  hydrating = false;
}

function syncQuery(): void {
  if (hydrating) return;
  void router.replace({ query: serializeTabQuery(route.query, tab.value) });
}

watch(tab, () => syncQuery());

const items = computed(() => store.reportsFor(formId.value));
const loading = computed(() => !!store.repLoading[formId.value]);
const errored = computed(() => !!store.repError[formId.value]);
const hasMore = computed(() => store.repHasMore[formId.value] !== false);
const initialLoading = computed(() => loading.value && items.value.length === 0);
const isEmpty = computed(() => !loading.value && !errored.value && items.value.length === 0);
const skeletonKeys = Array.from({ length: 4 }, (_, i) => i);

const { sentinelRef } = useInfiniteScroll({
  onLoadMore: () => store.loadMoreReports(formId.value, filters.value),
  canLoadMore: () => hasMore.value && !loading.value && !errored.value,
});

// --- Completion polling (pending reports → fetch each until done) ---------
let pollTimer: ReturnType<typeof setInterval> | null = null;
const hasPending = computed(() => !isTrashed.value && items.value.some((r) => !r.is_completed));
async function poll(): Promise<void> {
  const pending = items.value.filter((r) => !r.is_completed);
  for (const r of pending) {
    try {
      store.patchReport(formId.value, await store.fetchReport(r.id));
    } catch {
      /* ignore transient poll errors */
    }
  }
}
function stopPoll(): void {
  if (pollTimer) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
}
watch(hasPending, (pending) => {
  if (pending && !pollTimer) pollTimer = setInterval(poll, 5000);
  else if (!pending) stopPoll();
});
onBeforeUnmount(stopPoll);

// --- Create + detail drawers ----------------------------------------------
const createOpen = ref(false);
function onReportCreated(): void {
  createOpen.value = false;
}

// Clicking a report opens its rendered .md PREVIEW (fetched from the file), not
// its metadata.
const selected = ref<FormReport | null>(null);
const previewSource = ref('');
const previewLoading = ref(false);
const previewError = ref(false);

const detailOpen = computed<boolean>({
  get: () => selected.value !== null,
  set: (open) => {
    if (!open) selected.value = null;
  },
});

async function loadPreview(): Promise<void> {
  previewSource.value = '';
  previewError.value = false;
  const report = selected.value;
  if (!report?.is_completed || !report.file?.path) return;
  previewLoading.value = true;
  try {
    previewSource.value = await store.fetchReportFile(report.file.path);
  } catch {
    previewError.value = true;
  } finally {
    previewLoading.value = false;
  }
}

watch(selected, () => loadPreview());

function openDetail(report: FormReport): void {
  selected.value = report;
}
function downloadSelected(): void {
  if (selected.value?.file?.path) window.open(selected.value.file.path, '_blank', 'noopener');
}

// --- Delete / restore / force ---------------------------------------------
async function runAction(fn: () => Promise<unknown>, successKey: string): Promise<void> {
  try {
    await fn();
    toast.success(t(successKey));
  } catch {
    toast.danger(t('forms.reports.actionError'));
  }
}
async function onDelete(report: FormReport): Promise<void> {
  if (
    await confirm({
      title: t('forms.reports.confirm.deleteTitle'),
      message: t('forms.reports.confirm.deleteMessage'),
      confirmLabel: t('forms.reports.actions.delete'),
      cancelLabel: t('common.cancel'),
      variant: 'danger',
    })
  ) {
    void runAction(() => store.deleteReport(report.id, formId.value), 'forms.reports.toast.deleted');
    if (selected.value?.id === report.id) selected.value = null;
  }
}
function onRestore(report: FormReport): void {
  void runAction(() => store.restoreReport(report.id, formId.value), 'forms.reports.toast.restored');
}
async function onForceDelete(report: FormReport): Promise<void> {
  if (
    await confirm({
      title: t('forms.reports.confirm.forceDeleteTitle'),
      message: t('forms.reports.confirm.forceDeleteMessage'),
      confirmLabel: t('forms.reports.actions.forceDelete'),
      cancelLabel: t('common.cancel'),
      variant: 'danger',
    })
  ) {
    void runAction(() => store.forceDeleteReport(report.id, formId.value), 'forms.reports.toast.forceDeleted');
  }
}

function retry(): void {
  refetch();
}

onMounted(() => {
  hydrateFromQuery();
  void savedViews.load();
  refetch();
});
</script>

<template>
  <div class="flex flex-col gap-next-6">
    <!-- Sub-view header (uniform scale app-wide): the h1 is the SECTION label —
         the form's identity lives in the module aside's selected block. -->
    <PageHeader
      icon="file-text"
      :title="t('forms.reports.title')"
      :description="t('forms.reports.subtitle')"
    >
      <template #actions>
        <Button v-if="form?.is_enabled" leading-icon="plus" @click="createOpen = true">{{ t('forms.reports.new') }}</Button>
      </template>
    </PageHeader>

    <FilterBar
      v-model:search="search"
      :search-placeholder="t('forms.reports.searchPlaceholder')"
      :active-filters="decoratedFilters"
      :clear-all-label="t('forms.filters.clearAll')"
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

      <div class="min-w-0 flex-1 basis-44">
        <Select v-model="status" :options="statusOptions" leading-icon="check-circle" :aria-label="t('forms.reports.status')" />
      </div>
      <div class="min-w-0 flex-1 basis-40">
        <Select v-model="sort" :options="sortOptions" leading-icon="arrow-down" :aria-label="t('forms.submissions.sortLabel')" />
      </div>
      <div class="min-w-0 flex-1 basis-40">
        <DateRangeFilter v-model="dateRange" :presets="datePresets" :placeholder="t('forms.submissions.dateAny')" :aria-label="t('forms.submissions.dateRange')" />
      </div>
    </FilterBar>

    <Tabs v-model="tab" :items="tabItems" variant="pills" :aria-label="t('forms.reports.tabs.label')">
      <template #panel="{ value }">
        <div v-if="value === tab" class="flex flex-col gap-next-4">
          <EmptyState
            v-if="errored && items.length === 0"
            variant="error"
            :title="t('forms.reports.error.title')"
            :description="t('forms.reports.error.description')"
          >
            <template #action>
              <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="retry">{{ t('forms.error.retry') }}</Button>
            </template>
          </EmptyState>

          <div v-else-if="initialLoading" class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2 next-xl:grid-cols-3">
            <EntityCard v-for="n in skeletonKeys" :key="`sk-${n}`" loading />
          </div>

          <EmptyState
            v-else-if="isEmpty"
            :variant="hasActiveFilters ? 'search' : 'default'"
            :icon="isTrashed ? 'trash' : 'file-text'"
            :title="hasActiveFilters ? t('forms.reports.empty.searchTitle') : isTrashed ? t('forms.reports.empty.trashTitle') : t('forms.reports.empty.title')"
            :description="hasActiveFilters ? t('forms.reports.empty.searchDescription') : isTrashed ? t('forms.reports.empty.trashDescription') : t('forms.reports.empty.description')"
          >
            <template v-if="!hasActiveFilters && !isTrashed && form?.is_enabled" #action>
              <Button size="sm" leading-icon="plus" @click="createOpen = true">{{ t('forms.reports.new') }}</Button>
            </template>
          </EmptyState>

          <template v-else>
            <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2 next-xl:grid-cols-3">
              <ReportCard
                v-for="report in items"
                :key="report.id"
                :report="report"
                :trashed="isTrashed"
                @select="openDetail"
                @delete="onDelete"
                @restore="onRestore"
                @force-delete="onForceDelete"
              />
              <template v-if="loading">
                <EntityCard v-for="n in 2" :key="`more-${n}`" loading />
              </template>
            </div>
            <div v-if="hasMore && !errored" ref="sentinelRef" aria-hidden="true" class="h-px w-full" />
          </template>
        </div>
      </template>
    </Tabs>

    <!-- Create report drawer. -->
    <Drawer v-model:open="createOpen" side="right" size="lg" :aria-label="t('forms.reports.new')">
      <template #title>{{ t('forms.reports.new') }}</template>
      <ReportFormView v-if="createOpen" :form-id="formId" :created-at="form?.created_at ?? null" @submitted="onReportCreated" />
    </Drawer>

    <!-- Detail drawer: the report's rendered .md preview. -->
    <Drawer v-model:open="detailOpen" side="right" size="xl" :aria-label="t('forms.reports.detailTitle')">
      <template #title>{{ selected?.name ?? t('forms.reports.detailTitle') }}</template>
      <div v-if="selected">
        <!-- Still generating → no file to preview yet. -->
        <EmptyState
          v-if="!selected.is_completed"
          :title="t('forms.reports.pending')"
          :description="t('forms.reports.generating')"
          icon="clock"
        />
        <!-- Loading the file. -->
        <div v-else-if="previewLoading" class="flex flex-col gap-next-3">
          <Skeleton variant="text" width="60%" />
          <Skeleton variant="text" width="90%" />
          <Skeleton variant="text" width="80%" />
          <Skeleton variant="text" width="85%" />
        </div>
        <!-- File load error. -->
        <EmptyState
          v-else-if="previewError"
          variant="error"
          :title="t('forms.reports.previewError')"
          :description="t('forms.error.description')"
        >
          <template #action>
            <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="loadPreview">{{ t('forms.error.retry') }}</Button>
          </template>
        </EmptyState>
        <!-- Rendered markdown. -->
        <MarkdownViewer v-else :source="previewSource" class="next-md-prose" :aria-label="selected.name" />
      </div>
      <template v-if="selected && selected.is_completed && selected.file" #footer>
        <Button leading-icon="download" @click="downloadSelected">{{ t('forms.reports.actions.download') }}</Button>
      </template>
    </Drawer>

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
