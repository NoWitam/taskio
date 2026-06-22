<script setup lang="ts">
// FormSubmissionsView — a form's submissions list (`/forms/:id/submissions`).
//
// A sub-view of FormsModuleLayout (the open form comes from FORM_MODULE_CTX). It
// mirrors the legacy submissions screen's filtering:
//   • Active / Deleted TABS (the `trashed` query param),
//   • FilterBar with the Saved Views toolbar (#top), debounced search, a Source
//     multi-select (manual / task), an Indexed tri-state, a Sort select, and a
//     date range over the approval date,
//   • cursor-paginated list (skeletons / empty / error),
//   • a right-side Drawer that renders the submission read-only via FormViewer
//     (edit + save when `can_be_edited`), with the submitter / date / status in
//     the drawer FOOTER. All strings via i18n.
//
// NOTE: the backend exposes no submission delete/restore route, so the Deleted
// tab is view-only (it shows soft-deleted rows the API returns) and there is no
// delete action — that needs a backend endpoint.
import { computed, inject, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import FilterBar, { type ActiveFilter } from '../../ui/patterns/FilterBar.vue';
import FilterTabBar from '../../ui/patterns/FilterTabBar.vue';
import SaveViewModal, { type SaveViewSubmit } from '../../ui/patterns/SaveViewModal.vue';
import ConfirmDialog from '../../ui/overlay/ConfirmDialog.vue';
import Tabs, { type TabItem } from '../../ui/navigation/Tabs.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import DateRangeFilter, {
  type DateRangeFilterPreset,
  type DateRangeFilterValue,
} from '../../ui/forms/DateRangeFilter.vue';
import Button from '../../ui/primitives/Button.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import EntityCard from '../../ui/patterns/EntityCard.vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';
import Icon from '../../ui/primitives/Icon.vue';
import SubmissionCard from './SubmissionCard.vue';
import FormViewer from './FormViewer.vue';
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
import type { FormSubmission, SubmissionFilters } from './types';

const route = useRoute();
const router = useRouter();
const store = useFormsStore();
const toast = useToast();
const confirm = useConfirm();
const filterTabsStore = useFilterTabsStore();
const { t } = useI18n();

const SAVED_VIEWS_CONTEXT = 'form-submissions';

const ctx = inject(FORM_MODULE_CTX);
const form = computed(() => ctx?.form.value ?? null);
const formId = computed(() => String(route.params.id));

// --- Tab (bucket): Active vs Deleted — NEVER part of a saved-view snapshot ---
type SubTab = 'active' | 'trash';
const tab = ref<SubTab>('active');
const isTrashed = computed(() => tab.value === 'trash');
const tabItems = computed<TabItem<SubTab>[]>(() => [
  { value: 'active', label: t('forms.submissions.tabs.active'), icon: 'inbox' },
  { value: 'trash', label: t('forms.submissions.tabs.trash'), icon: 'trash' },
]);

// --- Filter state ---------------------------------------------------------
const search = ref('');
const sources = ref<string[]>([]);
const indexed = ref<'' | 'true' | 'false'>('');
const sort = ref<'newest' | 'oldest'>('newest');
const dateRange = ref<DateRangeFilterValue>({ preset: '', from: null, to: null, hide_without_deadline: false });

const sourceOptions = computed<SelectOption[]>(() => [
  { value: 'form', label: t('forms.submissions.sourceForm') },
  { value: 'task', label: t('forms.submissions.sourceTask') },
]);
const indexedOptions = computed<SelectOption[]>(() => [
  { value: '', label: t('forms.submissions.indexAll') },
  { value: 'true', label: t('forms.submissions.indexedOnly') },
  { value: 'false', label: t('forms.submissions.notIndexed') },
]);
const sortOptions = computed<SelectOption[]>(() => [
  { value: 'newest', label: t('forms.submissions.sortNewest') },
  { value: 'oldest', label: t('forms.submissions.sortOldest') },
]);
const datePresets = computed<DateRangeFilterPreset[]>(() =>
  (['today', 'this_week', 'last_week', 'this_month'] as const).map((p) => ({ id: p, label: t(`tasks.datePresets.${p}`) })),
);

// --- Aggregated filter object (mirrors the query params 1:1) -------------
const filters = computed<SubmissionFilters>(() => ({
  search: search.value || undefined,
  sources: sources.value.length ? sources.value : undefined,
  indexed: indexed.value === '' ? undefined : indexed.value === 'true',
  sort: sort.value,
  trashed: isTrashed.value ? true : undefined,
  date_preset: (dateRange.value.preset || undefined) as SubmissionFilters['date_preset'],
  date_from: dateRange.value.from ?? undefined,
  date_to: dateRange.value.to ?? undefined,
}));

const hasActiveFilters = computed(
  () =>
    !!search.value ||
    sources.value.length > 0 ||
    indexed.value !== '' ||
    !!dateRange.value.preset ||
    !!dateRange.value.from ||
    !!dateRange.value.to,
);

// --- Active-filter chips --------------------------------------------------
function splitKey(key: string): { base: string; value: string } {
  const eq = key.indexOf('=');
  return eq >= 0 ? { base: key.slice(0, eq), value: key.slice(eq + 1) } : { base: key, value: '' };
}
function sourceLabel(value: string): string {
  return value === 'task' ? t('forms.submissions.sourceTask') : t('forms.submissions.sourceForm');
}
function displayDate(ymd: string | null): string {
  if (!ymd) return '';
  const m = String(ymd).match(/^(\d{4})-(\d{2})-(\d{2})$/);
  return m ? `${m[3]}.${m[2]}.${m[1]}` : String(ymd);
}

const activeFilters = computed<ActiveFilter[]>(() => {
  const chips: ActiveFilter[] = [];
  if (search.value) chips.push({ key: `search=${search.value}`, label: t('forms.filters.chip.search', '', { value: search.value }) });
  if (sources.value.length) {
    chips.push({
      key: 'sources',
      values: sources.value.map((s) => ({ key: `sources:${s}`, label: `${t('forms.submissions.source')}: ${sourceLabel(s)}` })),
    });
  }
  if (indexed.value !== '') {
    chips.push({
      key: `indexed=${indexed.value}`,
      label: indexed.value === 'true' ? t('forms.submissions.indexedOnly') : t('forms.submissions.notIndexed'),
    });
  }
  if (dateRange.value.preset) chips.push({ key: `datePreset=${dateRange.value.preset}`, label: t(`tasks.datePresets.${dateRange.value.preset}`) });
  if (dateRange.value.from) chips.push({ key: `date_from=${dateRange.value.from}`, label: t('forms.submissions.dateFrom', '', { value: displayDate(dateRange.value.from) }) });
  if (dateRange.value.to) chips.push({ key: `date_to=${dateRange.value.to}`, label: t('forms.submissions.dateTo', '', { value: displayDate(dateRange.value.to) }) });
  return chips;
});

function disabledChipLabel(key: string): string | null {
  if (key.startsWith('sources:')) return `${t('forms.submissions.source')}: ${sourceLabel(key.slice('sources:'.length))}`;
  const { base, value } = splitKey(key);
  switch (base) {
    case 'search': return t('forms.filters.chip.search', '', { value });
    case 'indexed': return value === 'true' ? t('forms.submissions.indexedOnly') : t('forms.submissions.notIndexed');
    case 'datePreset': return t(`tasks.datePresets.${value}`);
    case 'date_from': return t('forms.submissions.dateFrom', '', { value: displayDate(value) });
    case 'date_to': return t('forms.submissions.dateTo', '', { value: displayDate(value) });
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
  if (key.startsWith('sources:')) {
    sources.value = sources.value.filter((s) => s !== key.slice('sources:'.length));
    return;
  }
  switch (splitKey(key).base) {
    case 'search': search.value = ''; break;
    case 'sources': sources.value = []; break;
    case 'indexed': indexed.value = ''; break;
    case 'datePreset': dateRange.value = { ...dateRange.value, preset: '', from: null, to: null }; break;
    case 'date_from': dateRange.value = { ...dateRange.value, preset: '', from: null }; break;
    case 'date_to': dateRange.value = { ...dateRange.value, preset: '', to: null }; break;
  }
}
function clearAll(): void {
  search.value = '';
  sources.value = [];
  indexed.value = '';
  dateRange.value = { preset: '', from: null, to: null, hide_without_deadline: false };
}

// --- Saved views ----------------------------------------------------------
function serializeFiltersSnapshot(): FilterSnapshot {
  const snap: FilterSnapshot = {};
  if (search.value) snap.search = search.value;
  if (sources.value.length) snap.sources = [...sources.value];
  if (indexed.value !== '') snap.indexed = indexed.value;
  if (dateRange.value.preset) snap.date_preset = dateRange.value.preset;
  if (dateRange.value.from) snap.date_from = dateRange.value.from;
  if (dateRange.value.to) snap.date_to = dateRange.value.to;
  return snap;
}
function applyFiltersSnapshot(snap: FilterSnapshot): void {
  search.value = typeof snap.search === 'string' ? snap.search : '';
  sources.value = Array.isArray(snap.sources) ? snap.sources.map(String) : [];
  indexed.value = snap.indexed === 'true' ? 'true' : snap.indexed === 'false' ? 'false' : '';
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
  if (Array.isArray(snap.sources)) snap.sources.map(String).sort().forEach((s) => keys.push(`sources:${s}`));
  if (snap.indexed) keys.push(`indexed=${String(snap.indexed)}`);
  if (snap.date_preset) keys.push(`datePreset=${String(snap.date_preset)}`);
  if (snap.date_from) keys.push(`date_from=${String(snap.date_from)}`);
  if (snap.date_to) keys.push(`date_to=${String(snap.date_to)}`);
  return keys;
}
function restoreSnapshotValue(key: string, snap: FilterSnapshot): void {
  if (key.startsWith('sources:')) {
    const v = key.slice('sources:'.length);
    if (!sources.value.includes(v)) sources.value = [...sources.value, v];
    return;
  }
  switch (splitKey(key).base) {
    case 'search': search.value = typeof snap.search === 'string' ? snap.search : ''; break;
    case 'indexed': indexed.value = snap.indexed === 'true' ? 'true' : snap.indexed === 'false' ? 'false' : ''; break;
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
  store.fetchSubmissions(formId.value, filters.value, { reset: true });
}
const debouncedRefetch = useDebounce(refetch, 400);
watch(search, () => debouncedRefetch());
watch([sources, indexed, sort, dateRange, tab], () => refetch(), { deep: true });

// --- List view-state ------------------------------------------------------
const items = computed(() => store.submissionsFor(formId.value));
const loading = computed(() => !!store.subLoading[formId.value]);
const errored = computed(() => !!store.subError[formId.value]);
const hasMore = computed(() => store.subHasMore[formId.value] !== false);
const initialLoading = computed(() => loading.value && items.value.length === 0);
const isEmpty = computed(() => !loading.value && !errored.value && items.value.length === 0);
const skeletonKeys = Array.from({ length: 4 }, (_, i) => i);

const { sentinelRef } = useInfiniteScroll({
  onLoadMore: () => store.loadMoreSubmissions(formId.value, filters.value),
  canLoadMore: () => hasMore.value && !loading.value && !errored.value,
});

// --- Detail drawer --------------------------------------------------------
const selected = ref<FormSubmission | null>(null);
const editing = ref(false);
const saving = ref(false);
const drawerOpen = computed<boolean>({
  get: () => selected.value !== null,
  set: (open) => {
    if (!open) {
      selected.value = null;
      editing.value = false;
    }
  },
});
function openDetail(submission: FormSubmission): void {
  selected.value = submission;
  editing.value = false;
}
function formatDateTime(iso: string | null): string {
  if (!iso) return '—';
  const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? `${m[3]}.${m[2]}.${m[1]}` : iso;
}
async function onSaveEdit(data: Record<string, unknown>): Promise<void> {
  if (!selected.value) return;
  saving.value = true;
  try {
    selected.value = await store.updateSubmission(selected.value.id, data);
    editing.value = false;
    toast.success(t('forms.submissions.updated'));
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } };
    toast.danger(e.response?.data?.message ?? t('forms.submissions.updateError'));
  } finally {
    saving.value = false;
  }
}

function retry(): void {
  refetch();
}
function fill(): void {
  void router.push({ query: { ...route.query, fill: formId.value } });
}

// --- Delete / restore / permanent delete ----------------------------------
async function runAction(fn: () => Promise<unknown>, successKey: string): Promise<void> {
  try {
    await fn();
    toast.success(t(successKey));
  } catch {
    toast.danger(t('forms.submissions.actionError'));
  }
}

async function onDelete(submission: FormSubmission): Promise<void> {
  if (
    await confirm({
      title: t('forms.submissions.confirm.deleteTitle'),
      message: t('forms.submissions.confirm.deleteMessage'),
      confirmLabel: t('forms.submissions.actions.delete'),
      cancelLabel: t('common.cancel'),
      variant: 'danger',
    })
  ) {
    void runAction(() => store.deleteSubmission(submission.id, formId.value), 'forms.submissions.toast.deleted');
  }
}

function onRestore(submission: FormSubmission): void {
  void runAction(() => store.restoreSubmission(submission.id, formId.value), 'forms.submissions.toast.restored');
}

async function onForceDelete(submission: FormSubmission): Promise<void> {
  if (
    await confirm({
      title: t('forms.submissions.confirm.forceDeleteTitle'),
      message: t('forms.submissions.confirm.forceDeleteMessage'),
      confirmLabel: t('forms.submissions.actions.forceDelete'),
      cancelLabel: t('common.cancel'),
      variant: 'danger',
    })
  ) {
    void runAction(() => store.forceDeleteSubmission(submission.id, formId.value), 'forms.submissions.toast.forceDeleted');
  }
}

onMounted(() => {
  void savedViews.load();
  refetch();
});
</script>

<template>
  <div class="flex flex-col gap-next-5">
    <div class="flex flex-wrap items-center justify-between gap-next-3">
      <div class="min-w-0">
        <h1 class="truncate text-next-xl font-next-semibold text-next-fg">{{ t('forms.submissions.title') }}</h1>
        <p class="text-next-sm text-next-muted-foreground">{{ t('forms.submissions.subtitle') }}</p>
      </div>
      <Button v-if="form?.can_be_filled" leading-icon="plus" @click="fill">{{ t('forms.submissions.new') }}</Button>
    </div>

    <FilterBar
      v-model:search="search"
      :search-placeholder="t('forms.submissions.searchPlaceholder')"
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
        <Select v-model:values="sources" multiple :options="sourceOptions" leading-icon="inbox" :placeholder="t('forms.submissions.source')" :aria-label="t('forms.submissions.source')" />
      </div>
      <div class="min-w-0 flex-1 basis-40">
        <Select v-model="indexed" :options="indexedOptions" leading-icon="sparkles" :aria-label="t('forms.submissions.indexLabel')" />
      </div>
      <div class="min-w-0 flex-1 basis-40">
        <Select v-model="sort" :options="sortOptions" leading-icon="arrow-down" :aria-label="t('forms.submissions.sortLabel')" />
      </div>
      <div class="min-w-0 flex-1 basis-40">
        <DateRangeFilter v-model="dateRange" :presets="datePresets" :placeholder="t('forms.submissions.dateAny')" :aria-label="t('forms.submissions.dateRange')" />
      </div>
    </FilterBar>

    <Tabs v-model="tab" :items="tabItems" variant="pills" :aria-label="t('forms.submissions.tabs.label')">
      <template #panel="{ value }">
        <div v-if="value === tab" class="flex flex-col gap-next-4">
          <!-- Error. -->
          <EmptyState
            v-if="errored && items.length === 0"
            variant="error"
            :title="t('forms.submissions.error.title')"
            :description="t('forms.submissions.error.description')"
          >
            <template #action>
              <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="retry">{{ t('forms.error.retry') }}</Button>
            </template>
          </EmptyState>

          <!-- Loading. -->
          <div v-else-if="initialLoading" class="flex flex-col gap-next-3">
            <EntityCard v-for="n in skeletonKeys" :key="`sk-${n}`" loading />
          </div>

          <!-- Empty. -->
          <EmptyState
            v-else-if="isEmpty"
            :variant="hasActiveFilters ? 'search' : 'default'"
            :icon="isTrashed ? 'trash' : 'inbox'"
            :title="hasActiveFilters ? t('forms.submissions.empty.searchTitle') : isTrashed ? t('forms.submissions.empty.trashTitle') : t('forms.submissions.empty.title')"
            :description="hasActiveFilters ? t('forms.submissions.empty.searchDescription') : isTrashed ? t('forms.submissions.empty.trashDescription') : t('forms.submissions.empty.description')"
          >
            <template v-if="!hasActiveFilters && !isTrashed && form?.can_be_filled" #action>
              <Button size="sm" leading-icon="plus" @click="fill">{{ t('forms.submissions.new') }}</Button>
            </template>
          </EmptyState>

          <!-- List. -->
          <template v-else>
            <div class="flex flex-col gap-next-3">
              <SubmissionCard
                v-for="submission in items"
                :key="submission.id"
                :submission="submission"
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

    <!-- Detail / edit drawer (larger; metadata in the footer). -->
    <Drawer v-model:open="drawerOpen" side="right" size="2xl" :aria-label="t('forms.submissions.detailTitle')">
      <template #title>{{ t('forms.submissions.detailTitle') }}</template>

      <div v-if="selected" class="flex flex-col gap-next-4">
        <div v-if="selected.can_be_edited && !editing" class="flex justify-end">
          <Button variant="outline" size="sm" leading-icon="pencil" @click="editing = true">{{ t('forms.submissions.edit') }}</Button>
        </div>
        <FormViewer
          :key="`${selected.id}-${editing ? 'edit' : 'view'}`"
          :content="form?.content ?? []"
          :mode="editing ? 'fill' : 'preview'"
          :initial-data="selected.data"
          :submitting="saving"
          @submit="onSaveEdit"
        />
      </div>

      <!-- Footer: compact submitter / date / status. -->
      <template v-if="selected" #footer>
        <div class="flex w-full flex-wrap items-center gap-x-next-4 gap-y-next-1 text-next-xs text-next-muted-foreground">
          <span class="inline-flex items-center gap-next-1">
            <Icon name="user" class="text-next-sm" />
            {{ selected.creator?.name ?? t('forms.submissions.anonymous') }}
          </span>
          <span class="inline-flex items-center gap-next-1">
            <Icon name="calendar" class="text-next-sm" />
            {{ formatDateTime(selected.created_at) }}
          </span>
          <StatusBadge
            class="ml-auto"
            :status="selected.is_approved ? 'approved' : 'pending'"
            :label="selected.is_approved ? t('forms.submissions.approved') : t('forms.submissions.pending')"
            :status-map="{
              approved: { label: t('forms.submissions.approved'), variant: 'success', tone: 'subtle', icon: 'check-circle' },
              pending: { label: t('forms.submissions.pending'), variant: 'warning', tone: 'subtle', icon: 'clock' },
            }"
            size="sm"
          />
        </div>
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
