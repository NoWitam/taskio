<script setup lang="ts">
// WorkflowsView — the Workflows (automation) BROWSE page (next, §2). Mirrors the
// Bots list IA exactly: a FilterBar with the MANDATORY Saved Views toolbar in its
// #top slot + a debounced `search` control and a `status` Select (the ONLY two
// server filters), then a cursor-paginated card grid with infinite scroll,
// card-shaped skeletons, an EmptyState (first-run vs no-results), and an error
// state with retry.
//
// A card click PREFETCHES the full workflow, then navigates to the DETAIL view.
// Create / Edit open the editor DRAWER (owned by WorkflowsModuleLayout) via the
// `?workflow=new` / `?workflow=<id>` query key. Run opens the run-now modal via
// `?run=<id>`. Delete runs through useConfirm + useToast.
//
// Filter state lives HERE, is pushed to the store (reset on change, debounced) and
// mirrored into the router query (preserving the `?workflow` / `?run` overlay keys).
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import FilterBar, { type ActiveFilter } from '../../ui/patterns/FilterBar.vue';
import FilterTabBar from '../../ui/patterns/FilterTabBar.vue';
import SaveViewModal, { type SaveViewSubmit } from '../../ui/patterns/SaveViewModal.vue';
import ConfirmDialog from '../../ui/overlay/ConfirmDialog.vue';
import Tabs, { type TabItem } from '../../ui/navigation/Tabs.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import EntityCard from '../../ui/patterns/EntityCard.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import WorkflowCard from './WorkflowCard.vue';
import { useWorkflowsStore } from '../../app/stores/workflows';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useDebounce } from '../../app/composables/useDebounce';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useFilterTabs, type FilterSnapshot } from '../../app/composables/useFilterTabs';
import { useFilterTabsStore } from '../../app/stores/filterTabs';
import type { FilterTab } from '../../app/stores/filterTabs';
import { toIconEnumValue } from '../../ui/forms/filterTabIcon';
import { useI18n } from '../../app/i18n';
import type { WorkflowFilters, WorkflowListItem, WorkflowStatus } from './types';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const store = useWorkflowsStore();
const toast = useToast();
const confirm = useConfirm();
const filterTabsStore = useFilterTabsStore();

// Saved-views context for the Workflows list (any string ≤64; backend-agnostic).
const SAVED_VIEWS_CONTEXT = 'workflows';

// --- Tab (bucket): Active vs Deleted — NEVER part of a saved-view snapshot ---
// The bucket is a TAB (like the Forms list), not a FilterBar filter; it drives the
// `trashed` query param.
type WorkflowsTab = 'active' | 'trash';
const tab = ref<WorkflowsTab>('active');
const isTrashed = computed(() => tab.value === 'trash');
const tabItems = computed<TabItem<WorkflowsTab>[]>(() => [
  { value: 'active', label: t('workflows.tabs.active'), icon: 'workflow' },
  { value: 'trash', label: t('workflows.tabs.trash'), icon: 'trash' },
]);

// --- Filter state (owned here) -------------------------------------------
const search = ref('');
// '' is the "All" sentinel (no status filter).
const status = ref<'' | WorkflowStatus>('');

const statusOptions = computed<SelectOption[]>(() => [
  { value: '', label: t('workflows.filters.status.all') },
  { value: 'active', label: t('workflows.filters.status.active'), icon: 'check-circle' },
  { value: 'inactive', label: t('workflows.filters.status.inactive'), icon: 'circle' },
]);

const filters = computed<WorkflowFilters>(() => ({
  search: search.value || undefined,
  status: status.value || undefined,
  trashed: isTrashed.value ? true : undefined,
}));

const hasActiveFilters = computed(() => !!search.value || !!status.value);

// --- Active-filter chips for the FilterBar --------------------------------
const activeFilters = computed<ActiveFilter[]>(() => {
  const chips: ActiveFilter[] = [];
  if (search.value) {
    chips.push({ key: `search=${search.value}`, label: t('workflows.filters.chip.search', '', { value: search.value }) });
  }
  if (status.value) {
    chips.push({ key: `status=${status.value}`, label: statusChipLabel(status.value) });
  }
  return chips;
});

function statusChipLabel(value: string): string {
  return t('workflows.filters.chip.status', '', { value: t(`workflows.filters.status.${value}`) });
}

function splitKey(key: string): { base: string; value: string } {
  const eq = key.indexOf('=');
  return eq >= 0 ? { base: key.slice(0, eq), value: key.slice(eq + 1) } : { base: key, value: '' };
}

function disabledChipLabel(key: string): string | null {
  const { base, value } = splitKey(key);
  if (base === 'search') return t('workflows.filters.chip.search', '', { value });
  if (base === 'status') return statusChipLabel(value);
  return null;
}

const decoratedFilters = computed<ActiveFilter[]>(() => {
  const decorated = savedViews.decorateActiveFilters(activeFilters.value);
  return decorated.map((f) =>
    f.tabState === 'tab-disabled' && f.label === f.key
      ? { ...f, label: disabledChipLabel(f.key) ?? f.label }
      : f,
  );
});

function removeFilter(key: string): void {
  const base = splitKey(key).base;
  if (base === 'search') search.value = '';
  else if (base === 'status') status.value = '';
}

function clearAll(): void {
  search.value = '';
  status.value = '';
}

// --- Saved views (FilterTabs Stage 2) -------------------------------------
function serializeFiltersSnapshot(): FilterSnapshot {
  const snap: FilterSnapshot = {};
  if (search.value) snap.search = search.value;
  if (status.value) snap.status = status.value;
  return snap;
}

function applyFiltersSnapshot(snap: FilterSnapshot): void {
  search.value = typeof snap.search === 'string' ? snap.search : '';
  status.value = snap.status === 'active' || snap.status === 'inactive' ? snap.status : '';
}

function normalizeSnapshot(snap: FilterSnapshot): string[] {
  const keys: string[] = [];
  if (snap.search) keys.push(`search=${String(snap.search)}`);
  if (snap.status) keys.push(`status=${String(snap.status)}`);
  return keys;
}

function restoreSnapshotValue(key: string, snap: FilterSnapshot): void {
  const base = splitKey(key).base;
  if (base === 'search') {
    search.value = typeof snap.search === 'string' ? snap.search : '';
  } else if (base === 'status') {
    status.value = snap.status === 'active' || snap.status === 'inactive' ? snap.status : '';
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

// --- Save / edit view modal -----------------------------------------------
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
const deleteViewConfirmOpen = ref(false);
const viewToDelete = ref<FilterTab | null>(null);

function onDeleteView(viewTab: FilterTab): void {
  viewToDelete.value = viewTab;
  deleteViewConfirmOpen.value = true;
}
const deleteViewMessage = computed(() =>
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
    deleteViewConfirmOpen.value = false;
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
  store.fetchWorkflows(filters.value, { reset: true });
}

// Search typing is debounced; a status change or a tab (bucket) switch refetches
// immediately.
const debouncedRefetch = useDebounce(refetch, 400);
watch(search, () => debouncedRefetch());
watch([status, tab], () => refetch());

// --- Infinite scroll (page-scroll → observe the viewport) -----------------
const { sentinelRef } = useInfiniteScroll({
  onLoadMore: () => store.loadMore(filters.value),
  canLoadMore: () =>
    store.hasMore && !store.loading && !store.loadingMore && !store.errored && !store.loadMoreErrored,
});

// --- List view-state ------------------------------------------------------
const items = computed(() => store.items);
const initialLoading = computed(() => store.loading && items.value.length === 0);
const isEmpty = computed(
  () => !store.loading && !store.loadingMore && !store.errored && items.value.length === 0,
);
const skeletonKeys = Array.from({ length: 6 }, (_, i) => i);

// --- URL sync (search/status; preserve the ?workflow / ?run overlay keys) --
let hydrating = false;

function hydrateFromQuery(): void {
  hydrating = true;
  const q = route.query;
  const str = (v: unknown): string => (Array.isArray(v) ? String(v[0] ?? '') : String(v ?? ''));
  search.value = str(q.search);
  tab.value = str(q.tab) === 'trash' ? 'trash' : 'active';
  const s = str(q.status);
  status.value = s === 'active' || s === 'inactive' ? s : '';
  hydrating = false;
}

function syncQuery(): void {
  if (hydrating) return;
  const query: Record<string, string> = {};
  // Preserve the editor + run-now overlay keys so a filter change never closes the
  // overlays the module layout owns.
  const w = route.query.workflow;
  if (w != null && w !== '') query.workflow = Array.isArray(w) ? String(w[0]) : String(w);
  const r = route.query.run;
  if (r != null && r !== '') query.run = Array.isArray(r) ? String(r[0]) : String(r);
  if (search.value) query.search = search.value;
  if (isTrashed.value) query.tab = 'trash';
  if (status.value) query.status = status.value;
  void router.replace({ query });
}

watch([search, status, tab], () => syncQuery());

// --- Create / edit (the editor DRAWER, owned by the module layout) --------
function onNewWorkflow(): void {
  void router.push({ query: { ...route.query, workflow: 'new' } });
}

function onEdit(workflow: WorkflowListItem): void {
  void router.push({ query: { ...route.query, workflow: workflow.id } });
}

// Run now → open the run-now modal (`?run=<id>`); the modal body lands in 6c.
function onRun(workflow: WorkflowListItem): void {
  void router.push({ query: { ...route.query, run: workflow.id } });
}

// Open a workflow's DETAIL view: PREFETCH the full workflow first, then navigate.
const opening = ref<string | null>(null);
async function onOpen(workflow: WorkflowListItem): Promise<void> {
  if (opening.value) return;
  opening.value = workflow.id;
  try {
    await store.fetchWorkflow(workflow.id);
    void router.push({ name: 'next.workflows.detail', params: { id: workflow.id } });
  } catch {
    toast.danger(t('workflows.errors.title'));
  } finally {
    opening.value = null;
  }
}

// --- Delete (confirm + toast) ---------------------------------------------
async function onDelete(workflow: WorkflowListItem): Promise<void> {
  const ok = await confirm({
    title: t('workflows.confirm.deleteTitle'),
    message: t('workflows.confirm.deleteMessage', '', { name: workflow.name }),
    confirmLabel: t('workflows.actions.delete'),
    cancelLabel: t('common.cancel'),
    variant: 'danger',
  });
  if (!ok) return;
  try {
    await store.deleteWorkflow(workflow.id);
    toast.success(t('workflows.toasts.deleted'));
  } catch {
    toast.danger(t('workflows.toasts.actionError'));
  }
}

// --- Restore (Deleted tab; reuses the tested store action) ----------------
async function onRestore(workflow: WorkflowListItem): Promise<void> {
  try {
    await store.restoreWorkflow(workflow.id);
    // restoreWorkflow reconciles the ACTIVE list; on the Deleted tab the restored
    // workflow has left the bucket, so drop its row here.
    if (isTrashed.value) store.removeFromList(workflow.id);
    toast.success(t('workflows.toasts.restored'));
  } catch {
    toast.danger(t('workflows.toasts.actionError'));
  }
}

// --- Activate / Deactivate (status toggle via the endpoint) ---------------
const togglingStatus = ref<string | null>(null);
async function onToggleStatus(workflow: WorkflowListItem): Promise<void> {
  if (togglingStatus.value) return;
  const next = workflow.status === 'active' ? 'inactive' : 'active';
  togglingStatus.value = workflow.id;
  try {
    await store.setStatus(workflow.id, next);
    toast.success(
      next === 'active'
        ? t('workflows.statusAction.activated')
        : t('workflows.statusAction.deactivated'),
    );
  } catch {
    toast.danger(t('workflows.statusAction.error'));
  } finally {
    togglingStatus.value = null;
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
    <PageHeader
      :title="t('workflows.title')"
      :description="t('workflows.subtitle')"
      icon="workflow"
    >
      <template #actions>
        <Button leading-icon="plus" @click="onNewWorkflow">
          {{ t('workflows.newWorkflow') }}
        </Button>
      </template>
    </PageHeader>

    <FilterBar
      v-model:search="search"
      :search-placeholder="t('workflows.filters.search')"
      :active-filters="decoratedFilters"
      :clear-all-label="t('workflows.filters.clearAll')"
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

      <!-- Status filter (single, clearable via the "All" option). -->
      <div class="min-w-0 flex-1 basis-40">
        <Select
          v-model="status"
          :options="statusOptions"
          leading-icon="circle"
          :aria-label="t('workflows.filters.statusLabel')"
        />
      </div>
    </FilterBar>

    <!-- Active / Deleted tabs (like the Forms list). The grid renders in the active
         panel; only the active panel's content is mounted (v-if). The FilterBar +
         Saved Views toolbar above stay shared across both buckets. -->
    <Tabs v-model="tab" :items="tabItems" variant="pills" :aria-label="t('workflows.tabs.label')">
      <template #panel="{ value }">
        <div v-if="value === tab" class="flex flex-col gap-next-4">
          <Alert v-if="isTrashed" variant="info" size="sm" :title="t('workflows.trashInfo.title')">
            {{ t('workflows.trashInfo.body') }}
          </Alert>

          <!-- Error (initial load failed) with retry. -->
          <EmptyState
            v-if="store.errored && items.length === 0"
            variant="error"
            :title="t('workflows.errors.title')"
            :description="t('workflows.errors.description')"
          >
            <template #action>
              <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="refetch">
                {{ t('workflows.errors.retry') }}
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
            :icon="isTrashed ? 'trash' : 'workflow'"
            :title="
              hasActiveFilters
                ? t('workflows.empty.searchTitle')
                : isTrashed
                  ? t('workflows.empty.trashTitle')
                  : t('workflows.empty.title')
            "
            :description="
              hasActiveFilters
                ? t('workflows.empty.searchDescription')
                : isTrashed
                  ? t('workflows.empty.trashDescription')
                  : t('workflows.empty.description')
            "
          >
            <template v-if="!hasActiveFilters && !isTrashed" #action>
              <Button size="sm" leading-icon="plus" @click="onNewWorkflow">
                {{ t('workflows.empty.action') }}
              </Button>
            </template>
          </EmptyState>

          <!-- Success: the grid + (when appending) trailing skeletons + sentinel. -->
          <template v-else>
            <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2 next-xl:grid-cols-3">
              <WorkflowCard
                v-for="workflow in items"
                :key="workflow.id"
                :workflow="workflow"
                :trashed="isTrashed"
                :opening="opening === workflow.id"
                @open="onOpen"
                @run="onRun"
                @edit="onEdit"
                @delete="onDelete"
                @toggle-status="onToggleStatus"
                @restore="onRestore"
              />

              <template v-if="store.loadingMore">
                <EntityCard v-for="n in 3" :key="`more-${n}`" loading />
              </template>
            </div>

            <!-- Inline "load more" error with retry (keeps the loaded grid visible). -->
            <Alert
              v-if="store.loadMoreErrored && items.length > 0"
              variant="danger"
              size="sm"
            >
              <div class="flex items-center justify-between gap-next-2">
                <span>{{ t('workflows.errors.description') }}</span>
                <Button variant="ghost" size="xs" leading-icon="rotate-ccw" @click="store.retryLoadMore(filters)">
                  {{ t('workflows.errors.retry') }}
                </Button>
              </div>
            </Alert>

            <!-- Infinite-scroll sentinel. -->
            <div
              v-if="store.hasMore && !store.errored && !store.loadMoreErrored"
              ref="sentinelRef"
              aria-hidden="true"
              class="h-px w-full"
            />
          </template>
        </div>
      </template>
    </Tabs>

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
      v-model:open="deleteViewConfirmOpen"
      variant="danger"
      :title="t('tasks.savedViews.confirm.deleteTitle')"
      :message="deleteViewMessage"
      :confirm-label="t('common.delete')"
      :cancel-label="t('common.cancel')"
      :loading="savingView"
      @confirm="onConfirmDeleteView"
    />
  </div>
</template>
