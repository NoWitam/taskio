<script setup lang="ts">
// SubmissionsBrowser — the SHARED submissions filter + list section (next).
//
// The single source of truth for "browse a form's submissions": the Saved Views
// toolbar (#top FilterTabBar), the full FilterBar (search + source + indexed +
// sort + date), and the cursor-paginated SubmissionCard list (skeletons / empty /
// error / infinite scroll). Extracted from FormSubmissionsView so the run-now
// picker drawer (SubmissionPickerDrawer) renders the IDENTICAL filters + saved
// views without the two drifting.
//
// Two modes, one component:
//   • default (page)  → the Active / Deleted bucket tabs show, a card click emits
//                        `preview(submission)` (the page owns the preview drawer),
//                        the kebab actions (delete / restore / permanent delete)
//                        are active and handled here, and the empty state offers a
//                        "New" affordance via `@fill` when `canFill`.
//   • `selectable`    → no bucket tabs, no kebab; the WHOLE card is a pick
//                        affordance (SubmissionCard `selectable`) and a click emits
//                        `select(submissionId, submission)`. Only active (approved)
//                        submissions are ever listed (no `trashed`).
//
// Router / URL concerns stay in the PARENT: the bucket lives in `v-model:bucket`
// so the page can sync it to `?tab=` while this component stays router-free (so it
// mounts cleanly inside the picker Drawer). All strings via i18n.
import { computed, onMounted, ref, watch } from 'vue';
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
import SubmissionCard from './SubmissionCard.vue';
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
import type { FormSubmission, SubmissionFilters } from './types';

const props = withDefaults(
  defineProps<{
    /** The form whose submissions are browsed (a concrete uuid). */
    formId: string;
    /** Selection mode: whole-card pick, no bucket tabs, no kebab, active-only. */
    selectable?: boolean;
    /** Page mode only: show the empty-state "New" affordance (form.can_be_filled). */
    canFill?: boolean;
  }>(),
  { selectable: false, canFill: false },
);

const emit = defineEmits<{
  /** default (page) mode: open the read-only preview for a submission. */
  (e: 'preview', submission: FormSubmission): void;
  /** `selectable` mode: a card was picked. */
  (e: 'select', submissionId: string, submission: FormSubmission): void;
  /** default (page) mode: the empty-state "New" affordance was pressed. */
  (e: 'fill'): void;
}>();

const store = useFormsStore();
const toast = useToast();
const confirm = useConfirm();
const filterTabsStore = useFilterTabsStore();
const { t } = useI18n();

// Both the list page and the picker share ONE saved-views context so the views
// (and their server-backed set) are identical across the two surfaces.
const SAVED_VIEWS_CONTEXT = 'form-submissions';

// --- Bucket (Active/Deleted) — parent-owned so it can sync the URL; NEVER part of
// a saved-view snapshot. Suppressed entirely in `selectable` mode. -----------
type SubTab = 'active' | 'trash';
const bucket = defineModel<SubTab>('bucket', { default: 'active' });
const isTrashed = computed(() => !props.selectable && bucket.value === 'trash');
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
  store.fetchSubmissions(props.formId, filters.value, { reset: true });
}
const debouncedRefetch = useDebounce(refetch, 400);
watch(search, () => debouncedRefetch());
watch([sources, indexed, sort, dateRange, bucket], () => refetch(), { deep: true });
// A scope change (e.g. the picker's "any form" re-pick) reloads from scratch.
watch(() => props.formId, () => refetch());

// --- List view-state ------------------------------------------------------
const items = computed(() => store.submissionsFor(props.formId));
const loading = computed(() => !!store.subLoading[props.formId]);
const errored = computed(() => !!store.subError[props.formId]);
const hasMore = computed(() => store.subHasMore[props.formId] !== false);
const initialLoading = computed(() => loading.value && items.value.length === 0);
const isEmpty = computed(() => !loading.value && !errored.value && items.value.length === 0);
const skeletonKeys = Array.from({ length: 4 }, (_, i) => i);

const { sentinelRef } = useInfiniteScroll({
  onLoadMore: () => store.loadMoreSubmissions(props.formId, filters.value),
  canLoadMore: () => hasMore.value && !loading.value && !errored.value,
});

// --- Row interactions -----------------------------------------------------
function onCardSelect(submission: FormSubmission): void {
  if (props.selectable) emit('select', submission.id, submission);
  else emit('preview', submission);
}

// Delete / restore / permanent delete (page mode only — the kebab is suppressed
// in `selectable` mode, so these never fire there).
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
    void runAction(() => store.deleteSubmission(submission.id, props.formId), 'forms.submissions.toast.deleted');
  }
}

function onRestore(submission: FormSubmission): void {
  void runAction(() => store.restoreSubmission(submission.id, props.formId), 'forms.submissions.toast.restored');
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
    void runAction(() => store.forceDeleteSubmission(submission.id, props.formId), 'forms.submissions.toast.forceDeleted');
  }
}

onMounted(() => {
  void savedViews.load();
  refetch();
});
</script>

<template>
  <div class="flex flex-col gap-next-4">
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

    <!-- Bucket switch (Active / Deleted) — page mode only; a picker never browses
         trashed submissions. Nav-only Tabs (the list below is the single panel). -->
    <Tabs
      v-if="!selectable"
      v-model="bucket"
      :items="tabItems"
      variant="pills"
      :aria-label="t('forms.submissions.tabs.label')"
    />

    <div class="flex flex-col gap-next-4">
      <!-- Error. -->
      <EmptyState
        v-if="errored && items.length === 0"
        variant="error"
        :title="t('forms.submissions.error.title')"
        :description="t('forms.submissions.error.description')"
      >
        <template #action>
          <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="refetch">{{ t('forms.error.retry') }}</Button>
        </template>
      </EmptyState>

      <!-- Loading. -->
      <div v-else-if="initialLoading" class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2 next-xl:grid-cols-3">
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
        <template v-if="!selectable && !hasActiveFilters && !isTrashed && canFill" #action>
          <Button size="sm" leading-icon="plus" @click="emit('fill')">{{ t('forms.submissions.new') }}</Button>
        </template>
      </EmptyState>

      <!-- List. -->
      <template v-else>
        <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2 next-xl:grid-cols-3">
          <SubmissionCard
            v-for="submission in items"
            :key="submission.id"
            :submission="submission"
            :selectable="selectable"
            :trashed="isTrashed"
            @select="onCardSelect"
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
