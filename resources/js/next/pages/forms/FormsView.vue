<script setup lang="ts">
// FormsView — the Forms BROWSE page for the isolated "next" frontend (Batch 1:
// list / filters / lifecycle actions). The builder, fill/preview, submissions
// and reports land in later batches (seams noted below).
//
// Composition (all design-system components, all strings via t()):
//   • PageHeader with a "New form" action (SEAM → Batch 2 builder; for now it
//     surfaces a "coming soon" info toast).
//   • FilterBar with the Saved Views toolbar ALWAYS embedded in its #top slot
//     (FilterTabBar — context "forms"), a debounced search, and three toggle
//     switches mirroring the legacy app 1:1: Enabled / Disabled (mutually
//     exclusive → the `enabled` query param true/false/absent) and Indexed
//     (→ `indexed=1` when on). Active-filter chips list each applied filter.
//   • Active / Deleted as TABS (like the Tasks board) — the tab drives the
//     `trashed` query param; the grid renders inside the active panel with
//     cursor pagination, card-shaped skeletons, and error / empty states.
//
// Filter state lives HERE, is pushed to the store (reset on change, search
// debounced) and mirrored into the router query. Lifecycle actions run through
// useConfirm (destructive ones) + useToast (every result).
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import FilterBar, { type ActiveFilter } from '../../ui/patterns/FilterBar.vue';
import FilterTabBar from '../../ui/patterns/FilterTabBar.vue';
import SaveViewModal, { type SaveViewSubmit } from '../../ui/patterns/SaveViewModal.vue';
import ConfirmDialog from '../../ui/overlay/ConfirmDialog.vue';
import Tabs, { type TabItem } from '../../ui/navigation/Tabs.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Switch from '../../ui/forms/Switch.vue';
import Button from '../../ui/primitives/Button.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import EntityCard from '../../ui/patterns/EntityCard.vue';
import FormCard from './FormCard.vue';
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
import type { FormFilters, FormSummary } from './types';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const store = useFormsStore();
const toast = useToast();
const confirm = useConfirm();
const filterTabsStore = useFilterTabsStore();

// Saved-views context for the Forms list (any string ≤64; backend-agnostic).
const SAVED_VIEWS_CONTEXT = 'forms';

// --- Tab (bucket): Active vs Deleted — NEVER part of a saved-view snapshot ---
type FormsTab = 'active' | 'trash';
const tab = ref<FormsTab>('active');
const isTrashed = computed(() => tab.value === 'trash');
const tabItems = computed<TabItem<FormsTab>[]>(() => [
  { value: 'active', label: t('forms.tabs.active'), icon: 'file-text' },
  { value: 'trash', label: t('forms.tabs.trash'), icon: 'trash' },
]);

// --- Filter state (owned here) -------------------------------------------
const search = ref('');
// Enabled / Disabled are mutually exclusive switches (legacy parity): turning
// one on turns the other off. Both off / both off → no `enabled` filter.
const showEnabled = ref(false);
const showDisabled = ref(false);
// Indexed is an independent switch → `indexed=1` only when on.
const showIndexed = ref(false);

// --- Aggregated filter object (mirrors the query params 1:1) -------------
const filters = computed<FormFilters>(() => {
  let enabled: boolean | undefined;
  if (showEnabled.value && !showDisabled.value) enabled = true;
  else if (!showEnabled.value && showDisabled.value) enabled = false;

  return {
    search: search.value || undefined,
    trashed: isTrashed.value ? true : undefined,
    enabled,
    indexed: showIndexed.value ? true : undefined,
  };
});

const hasActiveFilters = computed(
  () => !!search.value || showEnabled.value || showDisabled.value || showIndexed.value,
);

// Mutual exclusion between the Enabled / Disabled switches (legacy parity).
watch(showEnabled, (v) => {
  if (v && showDisabled.value) showDisabled.value = false;
});
watch(showDisabled, (v) => {
  if (v && showEnabled.value) showEnabled.value = false;
});

// --- Active-filter chips for the FilterBar --------------------------------
// Scalar chip keys ENCODE their value (e.g. `enabled=true`) so the saved-view
// trit-state comparison lines up with `normalizeSnapshot` below.
const activeFilters = computed<ActiveFilter[]>(() => {
  const chips: ActiveFilter[] = [];
  if (search.value) {
    chips.push({ key: `search=${search.value}`, label: t('forms.filters.chip.search', '', { value: search.value }) });
  }
  if (showEnabled.value) {
    chips.push({ key: 'enabled=true', label: t('forms.filters.chip.enabled') });
  } else if (showDisabled.value) {
    chips.push({ key: 'enabled=false', label: t('forms.filters.chip.disabled') });
  }
  if (showIndexed.value) {
    chips.push({ key: 'indexed', label: t('forms.filters.chip.indexed') });
  }
  return chips;
});

// Split a value-encoded scalar key (`enabled=true`) into base + value.
function splitKey(key: string): { base: string; value: string } {
  const eq = key.indexOf('=');
  return eq >= 0 ? { base: key.slice(0, eq), value: key.slice(eq + 1) } : { base: key, value: '' };
}

function disabledChipLabel(key: string): string | null {
  const { base, value } = splitKey(key);
  switch (base) {
    case 'search':
      return t('forms.filters.chip.search', '', { value });
    case 'enabled':
      return value === 'true' ? t('forms.filters.chip.enabled') : t('forms.filters.chip.disabled');
    case 'indexed':
      return t('forms.filters.chip.indexed');
    default:
      return null;
  }
}

// Decorate the chips with their saved-view trit-state (no-op when no view is
// active). Also relabels injected `tab-disabled` snapshot chips for readability.
const decoratedFilters = computed<ActiveFilter[]>(() => {
  const decorated = savedViews.decorateActiveFilters(activeFilters.value);
  return decorated.map((f) =>
    f.tabState === 'tab-disabled' && f.label === f.key
      ? { ...f, label: disabledChipLabel(f.key) ?? f.label }
      : f,
  );
});

function removeFilter(key: string): void {
  switch (splitKey(key).base) {
    case 'search':
      search.value = '';
      break;
    case 'enabled':
      showEnabled.value = false;
      showDisabled.value = false;
      break;
    case 'indexed':
      showIndexed.value = false;
      break;
  }
}

function clearAll(): void {
  search.value = '';
  showEnabled.value = false;
  showDisabled.value = false;
  showIndexed.value = false;
}

// --- Saved views (FilterTabs Stage 2) -------------------------------------
// The bucket tab (Active/Trash) is NEVER part of the snapshot. Search is a
// scalar; the enabled state persists as `enabled: 'true' | 'false'`; indexed as
// a presence flag.
function serializeFiltersSnapshot(): FilterSnapshot {
  const snap: FilterSnapshot = {};
  if (search.value) snap.search = search.value;
  if (showEnabled.value) snap.enabled = 'true';
  else if (showDisabled.value) snap.enabled = 'false';
  if (showIndexed.value) snap.indexed = true;
  return snap;
}

function applyFiltersSnapshot(snap: FilterSnapshot): void {
  search.value = typeof snap.search === 'string' ? snap.search : '';
  if (snap.enabled === 'true') {
    showEnabled.value = true;
    showDisabled.value = false;
  } else if (snap.enabled === 'false') {
    showDisabled.value = true;
    showEnabled.value = false;
  } else {
    showEnabled.value = false;
    showDisabled.value = false;
  }
  showIndexed.value = snap.indexed === true;
}

// Canonical key SET for dirty-state + chip trit-state — MUST match the chip
// keys produced by `activeFilters`.
function normalizeSnapshot(snap: FilterSnapshot): string[] {
  const keys: string[] = [];
  if (snap.search) keys.push(`search=${String(snap.search)}`);
  if (snap.enabled === 'true') keys.push('enabled=true');
  else if (snap.enabled === 'false') keys.push('enabled=false');
  if (snap.indexed === true) keys.push('indexed');
  return keys;
}

function restoreSnapshotValue(key: string, snap: FilterSnapshot): void {
  switch (splitKey(key).base) {
    case 'search':
      search.value = typeof snap.search === 'string' ? snap.search : '';
      break;
    case 'enabled':
      if (snap.enabled === 'true') {
        showEnabled.value = true;
        showDisabled.value = false;
      } else if (snap.enabled === 'false') {
        showDisabled.value = true;
        showEnabled.value = false;
      }
      break;
    case 'indexed':
      showIndexed.value = true;
      break;
  }
}

const savedViews = useFilterTabs(SAVED_VIEWS_CONTEXT, {
  serialize: serializeFiltersSnapshot,
  apply: applyFiltersSnapshot,
  normalize: normalizeSnapshot,
  restoreValue: restoreSnapshotValue,
});

const savingView = ref(false);

function onActivateView(viewTab: FilterTab): void {
  savedViews.applyTab(viewTab);
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
function onEditView(viewTab: FilterTab): void {
  saveModalMode.value = 'edit';
  editingView.value = viewTab;
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
        filters: serializeFiltersSnapshot(),
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
    if (fieldErrors.name) {
      saveModalNameError.value = fieldErrors.name;
    } else {
      const otherKey = fieldErrors.context ?? fieldErrors.filters ?? fieldErrors.icon ?? null;
      toast.danger(otherKey ? t(otherKey) : t('tasks.savedViews.toast.saveError'));
    }
  } finally {
    savingView.value = false;
  }
}

// --- Delete view (ConfirmDialog) ------------------------------------------
const deleteConfirmOpen = ref(false);
const viewToDelete = ref<FilterTab | null>(null);

function onDeleteView(viewTab: FilterTab): void {
  viewToDelete.value = viewTab;
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

async function moveView(viewTab: FilterTab, dir: -1 | 1): Promise<void> {
  const list = savedViews.tabs.value;
  const idx = list.findIndex((v) => String(v.id) === String(viewTab.id));
  const target = idx + dir;
  if (idx < 0 || target < 0 || target >= list.length) return;
  const ids = list.map((v) => v.id);
  [ids[idx], ids[target]] = [ids[target], ids[idx]];
  try {
    await filterTabsStore.reorder(SAVED_VIEWS_CONTEXT, ids);
    toast.success(t('tasks.savedViews.toast.reordered'));
  } catch (err: unknown) {
    const e = err as { fieldErrors?: Record<string, string> };
    const key = e.fieldErrors?.ids ?? null;
    toast.danger(key ? t(key) : t('tasks.savedViews.errors.invalidReorder'));
    void savedViews.load();
  }
}

function onRestoreFilter(key: string): void {
  savedViews.restoreFilter(key);
}

// --- Fetch orchestration --------------------------------------------------
function refetch(): void {
  store.fetchForms(filters.value, { reset: true });
}

// Search typing is debounced; switches + tab refetch immediately.
const debouncedRefetch = useDebounce(refetch, 400);
watch(search, () => debouncedRefetch());
watch([showEnabled, showDisabled, showIndexed, tab], () => refetch());

// --- Infinite scroll (page-scroll → observe the viewport) -----------------
const { sentinelRef } = useInfiniteScroll({
  onLoadMore: () => store.loadMore(filters.value),
  canLoadMore: () => store.hasMore && !store.loading && !store.errored,
});

// --- List view-state ------------------------------------------------------
const items = computed(() => store.items);
const initialLoading = computed(() => store.loading && items.value.length === 0);
const loadingMore = computed(() => store.loading && items.value.length > 0);
const isEmpty = computed(() => !store.loading && !store.errored && items.value.length === 0);
const skeletonKeys = Array.from({ length: 6 }, (_, i) => i);

// --- URL sync (filters + tab) ---------------------------------------------
let hydrating = false;

function hydrateFromQuery(): void {
  hydrating = true;
  const q = route.query;
  const str = (v: unknown): string => (Array.isArray(v) ? String(v[0] ?? '') : String(v ?? ''));
  search.value = str(q.search);
  tab.value = str(q.tab) === 'trash' ? 'trash' : 'active';
  const en = str(q.enabled);
  showEnabled.value = en === 'true';
  showDisabled.value = en === 'false';
  showIndexed.value = str(q.indexed) === '1';
  hydrating = false;
}

function syncQuery(): void {
  if (hydrating) return;
  const query: Record<string, string> = {};
  // Preserve the drawer-overlay keys so a filter change never closes the
  // create/edit/fill drawer the module layout owns.
  for (const key of ['create', 'edit', 'fill'] as const) {
    const v = route.query[key];
    if (v != null && v !== '') query[key] = Array.isArray(v) ? String(v[0]) : String(v);
  }
  if (search.value) query.search = search.value;
  if (isTrashed.value) query.tab = 'trash';
  if (showEnabled.value) query.enabled = 'true';
  else if (showDisabled.value) query.enabled = 'false';
  if (showIndexed.value) query.indexed = '1';
  void router.replace({ query });
}

watch([search, showEnabled, showDisabled, showIndexed, tab], () => syncQuery());

// --- Lifecycle actions (toast every result; confirm destructive ones) -----
// Create / edit open the builder DRAWER (owned by the module layout) via query.
function onNewForm(): void {
  void router.push({ query: { ...route.query, create: '1' } });
}

function onEdit(form: FormSummary): void {
  void router.push({ query: { ...route.query, edit: form.id } });
}

// Open a form's sub-module: PREFETCH the full form first (so the destination
// renders already populated), then navigate. `opening` drives the card spinner.
const opening = ref<string | null>(null);
async function onOpen(form: FormSummary): Promise<void> {
  if (opening.value) return;
  opening.value = form.id;
  try {
    await store.fetchForm(form.id);
    void router.push(`/forms/${form.id}`);
  } catch {
    toast.danger(t('forms.error.title'));
    opening.value = null;
  }
}

async function runAction(fn: () => Promise<unknown>, successKey: string): Promise<void> {
  try {
    await fn();
    toast.success(t(successKey));
  } catch {
    toast.danger(t('forms.toasts.actionError'));
  }
}

function onEnable(form: FormSummary): void {
  void runAction(() => store.enableForm(form.id), 'forms.toasts.enabled');
}
function onIndex(form: FormSummary): void {
  void runAction(() => store.indexForm(form.id), 'forms.toasts.indexing');
}
function onRestoreIndex(form: FormSummary): void {
  void runAction(() => store.restoreIndex(form.id), 'forms.toasts.indexRestored');
}
function onRestore(form: FormSummary): void {
  void runAction(() => store.restoreForm(form.id), 'forms.toasts.restored');
}

async function onDisable(form: FormSummary): Promise<void> {
  if (
    await confirm({
      title: t('forms.confirm.disableTitle'),
      message: t('forms.confirm.disableMessage'),
      confirmLabel: t('forms.actions.disable'),
      cancelLabel: t('common.cancel'),
    })
  ) {
    void runAction(() => store.disableForm(form.id), 'forms.toasts.disabled');
  }
}

async function onUnindex(form: FormSummary): Promise<void> {
  if (
    await confirm({
      title: t('forms.confirm.unindexTitle'),
      message: t('forms.confirm.unindexMessage'),
      confirmLabel: t('forms.actions.unindex'),
      cancelLabel: t('common.cancel'),
      variant: 'danger',
    })
  ) {
    void runAction(() => store.unindexForm(form.id, false), 'forms.toasts.unindexed');
  }
}

async function onDelete(form: FormSummary): Promise<void> {
  if (
    await confirm({
      title: t('forms.confirm.deleteTitle'),
      message: t('forms.confirm.deleteMessage'),
      confirmLabel: t('forms.actions.delete'),
      cancelLabel: t('common.cancel'),
      variant: 'danger',
    })
  ) {
    void runAction(() => store.deleteForm(form.id), 'forms.toasts.deleted');
  }
}

async function onForceDelete(form: FormSummary): Promise<void> {
  if (
    await confirm({
      title: t('forms.confirm.forceDeleteTitle'),
      message: t('forms.confirm.forceDeleteMessage'),
      confirmLabel: t('forms.actions.forceDelete'),
      cancelLabel: t('common.cancel'),
      variant: 'danger',
    })
  ) {
    void runAction(() => store.forceDeleteForm(form.id), 'forms.toasts.forceDeleted');
  }
}

onMounted(() => {
  hydrateFromQuery();
  void savedViews.load();
  refetch();
});
</script>

<template>
  <div class="flex flex-col gap-next-6">
    <PageHeader :title="t('forms.title')" :description="t('forms.subtitle')" icon="file-text">
      <template #actions>
        <Button leading-icon="plus" @click="onNewForm">
          {{ t('forms.newForm') }}
        </Button>
      </template>
    </PageHeader>

    <FilterBar
      v-model:search="search"
      :search-placeholder="t('forms.filters.search')"
      :active-filters="decoratedFilters"
      :clear-all-label="t('forms.filters.clearAll')"
      @remove-filter="removeFilter"
      @clear-all="clearAll"
      @restore-filter="onRestoreFilter"
    >
      <!-- Saved Views toolbar — ALWAYS present, embedded inside the bar. -->
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
          @move-up="(viewTab) => moveView(viewTab, -1)"
          @move-down="(viewTab) => moveView(viewTab, 1)"
          @retry="savedViews.load"
        />
      </template>

      <!-- Status switches (legacy parity): Enabled / Disabled (exclusive) + Indexed. -->
      <div class="ml-auto flex flex-wrap items-center gap-next-2">
        <span class="flex items-center rounded-next-md border border-next-border bg-next-card px-next-3 py-next-1_5">
          <Switch v-model="showEnabled" :label="t('forms.filters.enabled')" label-position="leading" size="sm" />
        </span>
        <span class="flex items-center rounded-next-md border border-next-border bg-next-card px-next-3 py-next-1_5">
          <Switch v-model="showDisabled" :label="t('forms.filters.disabled')" label-position="leading" size="sm" />
        </span>
        <span class="flex items-center rounded-next-md border border-next-border bg-next-card px-next-3 py-next-1_5">
          <Switch v-model="showIndexed" :label="t('forms.filters.indexed')" label-position="leading" size="sm" />
        </span>
      </div>
    </FilterBar>

    <!-- Active / Deleted tabs (like the Tasks board). The grid renders in the
         active panel; only the active panel's content is mounted (v-if). -->
    <Tabs v-model="tab" :items="tabItems" variant="pills" :aria-label="t('forms.tabs.label')">
      <template #panel="{ value }">
        <div v-if="value === tab" class="flex flex-col gap-next-4">
          <Alert v-if="isTrashed" variant="info" size="sm" :title="t('forms.trashInfo.title')">
            {{ t('forms.trashInfo.body') }}
          </Alert>

          <!-- Error (initial load failed) with retry. -->
          <EmptyState
            v-if="store.errored && items.length === 0"
            variant="error"
            :title="t('forms.error.title')"
            :description="t('forms.error.description')"
          >
            <template #action>
              <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="refetch">
                {{ t('forms.error.retry') }}
              </Button>
            </template>
          </EmptyState>

          <!-- Initial loading: several card-shaped skeletons (never a spinner). -->
          <div
            v-else-if="initialLoading"
            class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2 next-xl:grid-cols-3"
          >
            <EntityCard v-for="n in skeletonKeys" :key="`sk-${n}`" loading />
          </div>

          <!-- Empty: filtered "no results" vs first-run / empty-trash copy. -->
          <EmptyState
            v-else-if="isEmpty"
            :variant="hasActiveFilters ? 'search' : 'default'"
            :icon="isTrashed ? 'trash' : undefined"
            :title="
              hasActiveFilters
                ? t('forms.empty.searchTitle')
                : isTrashed
                  ? t('forms.empty.trashTitle')
                  : t('forms.empty.title')
            "
            :description="
              hasActiveFilters
                ? t('forms.empty.searchDescription')
                : isTrashed
                  ? t('forms.empty.trashDescription')
                  : t('forms.empty.description')
            "
          />

          <!-- Success: the grid + (when appending) trailing skeletons + sentinel. -->
          <template v-else>
            <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2 next-xl:grid-cols-3">
              <FormCard
                v-for="form in items"
                :key="form.id"
                :form="form"
                :trashed="isTrashed"
                :opening="opening === form.id"
                @open="onOpen"
                @edit="onEdit"
                @enable="onEnable"
                @disable="onDisable"
                @index="onIndex"
                @unindex="onUnindex"
                @restore-index="onRestoreIndex"
                @delete="onDelete"
                @restore="onRestore"
                @force-delete="onForceDelete"
              />

              <template v-if="loadingMore">
                <EntityCard v-for="n in 3" :key="`more-${n}`" loading />
              </template>
            </div>

            <!-- Inline "load more" error with retry (keeps the loaded grid visible). -->
            <div
              v-if="store.errored && items.length > 0"
              class="flex items-center justify-between gap-next-2 rounded-next-md border border-next-border bg-next-card px-next-3 py-next-2 text-next-xs text-next-danger"
              role="alert"
            >
              <span>{{ t('forms.error.description') }}</span>
              <Button variant="ghost" size="xs" @click="store.loadMore(filters)">
                {{ t('forms.error.retry') }}
              </Button>
            </div>

            <!-- Infinite-scroll sentinel (only while more pages remain). -->
            <div
              v-if="store.hasMore && !store.errored"
              ref="sentinelRef"
              aria-hidden="true"
              class="h-px w-full"
            />
          </template>
        </div>
      </template>
    </Tabs>

    <!-- Saved view: create / edit modal (no date filters in Forms). -->
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
