<script setup lang="ts">
// FunctionsView — the workspace-level custom-FUNCTIONS management surface. A module list (a
// child of VariablesModuleLayout, reached from its "Functions" module nav item), so it carries
// the MANDATORY FilterBar with the #top Saved Views FilterTabBar + a debounced `search` control
// (the only server filter), then a cursor-paginated list of FunctionRow with infinite scroll,
// row-shaped skeletons, an EmptyState (first-run vs no-results), and an error state with retry.
//
// Create / Edit open the FunctionEditorDrawer (a right drawer owned here); the drawer EMITS a
// `{ name, description?, input_type, args, return_type, body }` payload and this view performs the
// store call, surfaces 422 field errors back to the drawer, toasts, and lets the store refresh the
// workflow catalogs so every pipeline add-menu sees the new/edited/removed `fn:<uuid>` op. Delete
// runs through useConfirm + useToast, surfacing the backend's 422 in-use guard.
//
// The body pipeline editor in the drawer offers OTHER functions as ops (a function may call
// another). That op catalog is the WORKFLOW catalog resolved here (built-ins + `fn:<uuid>` ops)
// and passed down; it is refetched whenever the drawer opens so a just-created function is offered.
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
import FunctionRow from './FunctionRow.vue';
import FunctionEditorDrawer from './FunctionEditorDrawer.vue';
import { useFunctionsStore } from '../../app/stores/functions';
import { useWorkflowsStore } from '../../app/stores/workflows';
import { resolveOperationCatalog } from '../workflows/workflowConditions';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useDebounce } from '../../app/composables/useDebounce';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useFilterTabs, type FilterSnapshot } from '../../app/composables/useFilterTabs';
import { useFilterTabsStore } from '../../app/stores/filterTabs';
import type { FilterTab } from '../../app/stores/filterTabs';
import { toIconEnumValue } from '../../ui/forms/filterTabIcon';
import { useI18n } from '../../app/i18n';
import type { VariableOperationDefinition } from '../../ui/editor/extensions/types';
import type { CustomFunction, CustomFunctionFilters, CustomFunctionWritePayload } from '../workflows/types';

const { t } = useI18n();
const store = useFunctionsStore();
const workflows = useWorkflowsStore();
const toast = useToast();
const confirm = useConfirm();
const filterTabsStore = useFilterTabsStore();

// Saved-views context for the functions list (any string ≤64; backend-agnostic).
const SAVED_VIEWS_CONTEXT = 'functions';

// --- Filter state (owned here) ---------------------------------------------
const search = ref('');

const filters = computed<CustomFunctionFilters>(() => ({ search: search.value || undefined }));
const hasActiveFilters = computed(() => !!search.value);

// --- Active-filter chips ---------------------------------------------------
const activeFilters = computed<ActiveFilter[]>(() => {
  const chips: ActiveFilter[] = [];
  if (search.value) {
    chips.push({ key: `search=${search.value}`, label: t('workflows.filters.chip.search', '', { value: search.value }) });
  }
  return chips;
});

function splitKey(key: string): { base: string; value: string } {
  const eq = key.indexOf('=');
  return eq >= 0 ? { base: key.slice(0, eq), value: key.slice(eq + 1) } : { base: key, value: '' };
}
function disabledChipLabel(key: string): string | null {
  const { base, value } = splitKey(key);
  return base === 'search' ? t('workflows.filters.chip.search', '', { value }) : null;
}
const decoratedFilters = computed<ActiveFilter[]>(() => {
  const decorated = savedViews.decorateActiveFilters(activeFilters.value);
  return decorated.map((f) =>
    f.tabState === 'tab-disabled' && f.label === f.key ? { ...f, label: disabledChipLabel(f.key) ?? f.label } : f,
  );
});
function removeFilter(key: string): void {
  if (splitKey(key).base === 'search') search.value = '';
}
function clearAll(): void {
  search.value = '';
}

// --- Saved views (FilterTabs Stage 2) --------------------------------------
function serializeFiltersSnapshot(): FilterSnapshot {
  const snap: FilterSnapshot = {};
  if (search.value) snap.search = search.value;
  return snap;
}
function applyFiltersSnapshot(snap: FilterSnapshot): void {
  search.value = typeof snap.search === 'string' ? snap.search : '';
}
function normalizeSnapshot(snap: FilterSnapshot): string[] {
  return snap.search ? [`search=${String(snap.search)}`] : [];
}
function restoreSnapshotValue(key: string, snap: FilterSnapshot): void {
  if (splitKey(key).base === 'search') search.value = typeof snap.search === 'string' ? snap.search : '';
}

const savedViews = useFilterTabs(SAVED_VIEWS_CONTEXT, {
  serialize: serializeFiltersSnapshot,
  apply: applyFiltersSnapshot,
  normalize: normalizeSnapshot,
  restoreValue: restoreSnapshotValue,
});
const savingView = ref(false);

function onActivateView(view: FilterTab): void {
  savedViews.applyTab(view);
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
function onEditView(view: FilterTab): void {
  saveModalMode.value = 'edit';
  editingView.value = view;
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
    if (fieldErrors.name) saveModalNameError.value = fieldErrors.name;
    else {
      const otherKey = fieldErrors.context ?? fieldErrors.filters ?? fieldErrors.icon ?? null;
      toast.danger(otherKey ? t(otherKey) : t('tasks.savedViews.toast.saveError'));
    }
  } finally {
    savingView.value = false;
  }
}

const deleteViewConfirmOpen = ref(false);
const viewToDelete = ref<FilterTab | null>(null);
function onDeleteView(view: FilterTab): void {
  viewToDelete.value = view;
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
async function moveView(view: FilterTab, dir: -1 | 1): Promise<void> {
  const list = savedViews.tabs.value;
  const idx = list.findIndex((x) => String(x.id) === String(view.id));
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

// --- Fetch orchestration ---------------------------------------------------
function refetch(): void {
  void store.fetchFunctions(filters.value, { reset: true });
}
const debouncedRefetch = useDebounce(refetch, 400);
watch(search, () => debouncedRefetch());

const { sentinelRef } = useInfiniteScroll({
  onLoadMore: () => void store.loadMore(filters.value),
  canLoadMore: () =>
    store.hasMore && !store.loading && !store.loadingMore && !store.errored && !store.loadMoreErrored,
});

// --- List view-state -------------------------------------------------------
const items = computed(() => store.items);
const initialLoading = computed(() => store.loading && items.value.length === 0);
const isEmpty = computed(
  () => !store.loading && !store.loadingMore && !store.errored && items.value.length === 0,
);
const skeletonKeys = Array.from({ length: 5 }, (_, i) => i);

// --- Operations catalog (for the body pipeline editor) ---------------------
// The body editor offers built-in ops PLUS every `fn:<uuid>` function op. That is the workflow
// catalog (operations are trigger/form-independent — a schedule catalog carries them all) resolved
// to labelled definitions. Loaded on mount + whenever the drawer opens (a just-created function's
// mutation invalidated the cache, so re-opening refetches and offers it).
const operationsCatalog = ref<VariableOperationDefinition[]>([]);
async function loadOperationsCatalog(): Promise<void> {
  try {
    const catalog = await workflows.fetchWorkflowCatalog('schedule');
    operationsCatalog.value = resolveOperationCatalog(catalog);
  } catch {
    // A catalog load failure leaves the body editor with no fn: ops (built-ins still work); the
    // list itself is unaffected. Non-blocking — no toast.
  }
}

// --- Editor drawer (create / edit) -----------------------------------------
const editorOpen = ref(false);
const editing = ref<CustomFunction | null>(null);
const submitting = ref(false);
const serverErrors = ref<Record<string, string> | null>(null);

function onNew(): void {
  editing.value = null;
  serverErrors.value = null;
  void loadOperationsCatalog();
  editorOpen.value = true;
}
function onEdit(fn: CustomFunction): void {
  editing.value = fn;
  serverErrors.value = null;
  void loadOperationsCatalog();
  editorOpen.value = true;
}

/** Pull Laravel 422 field errors → `{ path: firstMessage }`. */
function fieldErrorsOf(err: unknown): Record<string, string> | null {
  const errors = (err as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors;
  if (!errors) return null;
  const flat: Record<string, string> = {};
  for (const [key, messages] of Object.entries(errors)) {
    if (Array.isArray(messages) && messages.length) flat[key] = messages[0];
  }
  return flat;
}

/** The HTTP status of an axios-style error, or null. */
function statusOf(err: unknown): number | null {
  return (err as { response?: { status?: number } })?.response?.status ?? null;
}

async function onSubmit(payload: CustomFunctionWritePayload): Promise<void> {
  submitting.value = true;
  serverErrors.value = null;
  try {
    if (editing.value) {
      await store.updateFunction(editing.value.id, payload);
      toast.success(t('variables.functions.toasts.updated'));
    } else {
      await store.createFunction(payload);
      toast.success(t('variables.functions.toasts.created'));
    }
    editorOpen.value = false;
  } catch (err: unknown) {
    const fieldErrors = fieldErrorsOf(err);
    if (fieldErrors) serverErrors.value = fieldErrors;
    else toast.danger(t('variables.functions.toasts.error'));
  } finally {
    submitting.value = false;
  }
}

// --- Delete (confirm + toast, surfacing the 422 in-use guard) ---------------
async function onDelete(fn: CustomFunction): Promise<void> {
  const ok = await confirm({
    title: t('variables.functions.confirm.deleteTitle'),
    message: t('variables.functions.confirm.deleteMessage', '', { name: fn.name }),
    confirmLabel: t('variables.functions.list.delete'),
    cancelLabel: t('common.cancel'),
    variant: 'danger',
  });
  if (!ok) return;
  try {
    await store.deleteFunction(fn.id);
    toast.success(t('variables.functions.toasts.deleted'));
  } catch (err: unknown) {
    // A 422 is the backend's in-use guard (referenced by a workflow OR another function): surface a
    // clear, localized reason rather than the generic error.
    if (statusOf(err) === 422) toast.danger(t('variables.functions.toasts.deleteBlocked'));
    else toast.danger(t('variables.functions.toasts.error'));
  }
}

onMounted(() => {
  void savedViews.load();
  void loadOperationsCatalog();
  refetch();
});
onUnmounted(() => store.resetAll());
</script>

<template>
  <div class="flex flex-col gap-next-6">
    <PageHeader
      icon="code"
      :title="t('variables.functions.title')"
      :description="t('variables.functions.subtitle')"
    >
      <template #actions>
        <Button leading-icon="plus" @click="onNew">
          {{ t('variables.functions.newFunction') }}
        </Button>
      </template>
    </PageHeader>

    <FilterBar
      v-model:search="search"
      :search-placeholder="t('variables.functions.filters.search')"
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
          @move-up="(view) => moveView(view, -1)"
          @move-down="(view) => moveView(view, 1)"
          @retry="savedViews.load"
        />
      </template>
    </FilterBar>

    <div class="flex flex-col gap-next-4">
      <!-- Error (initial load failed) with retry. -->
      <EmptyState
        v-if="store.errored && items.length === 0"
        variant="error"
        :title="t('variables.functions.errors.title')"
        :description="t('variables.functions.errors.description')"
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
          class="flex items-start gap-next-4 rounded-next-lg border border-next-border p-next-4"
        >
          <Skeleton variant="rect" width="2.25rem" height="2.25rem" radius="md" />
          <div class="flex flex-1 flex-col gap-next-2">
            <Skeleton variant="text" width="35%" />
            <div class="flex items-center gap-next-2">
              <Skeleton variant="rect" width="7rem" height="1.25rem" radius="full" />
              <Skeleton variant="text" width="30%" />
            </div>
            <Skeleton variant="text" width="45%" />
          </div>
        </li>
      </ul>

      <!-- Empty: filtered "no results" vs first-run copy. -->
      <EmptyState
        v-else-if="isEmpty"
        :variant="hasActiveFilters ? 'search' : 'default'"
        :icon="hasActiveFilters ? 'search' : 'code'"
        :title="hasActiveFilters ? t('variables.functions.empty.searchTitle') : t('variables.functions.empty.title')"
        :description="hasActiveFilters ? t('variables.functions.empty.searchDescription') : t('variables.functions.empty.description')"
      >
        <template v-if="!hasActiveFilters" #action>
          <Button size="sm" leading-icon="plus" @click="onNew">
            {{ t('variables.functions.empty.action') }}
          </Button>
        </template>
      </EmptyState>

      <!-- Success: the rows + (when appending) trailing skeletons + sentinel. -->
      <template v-else>
        <ul class="flex flex-col gap-next-2" :aria-label="t('variables.functions.title')">
          <li v-for="fn in items" :key="fn.id">
            <FunctionRow :func="fn" @edit="onEdit" @delete="onDelete" />
          </li>
        </ul>

        <ul v-if="store.loadingMore" class="flex flex-col gap-next-2" aria-hidden="true">
          <li
            v-for="n in 3"
            :key="`more-sk-${n}`"
            class="flex items-start gap-next-4 rounded-next-lg border border-next-border p-next-4"
          >
            <Skeleton variant="rect" width="2.25rem" height="2.25rem" radius="md" />
            <div class="flex flex-1 flex-col gap-next-2">
              <Skeleton variant="text" width="35%" />
              <Skeleton variant="text" width="25%" />
            </div>
          </li>
        </ul>

        <Alert v-if="store.loadMoreErrored && items.length > 0" variant="danger" size="sm">
          <div class="flex items-center justify-between gap-next-2">
            <span>{{ t('variables.functions.errors.description') }}</span>
            <Button variant="ghost" size="xs" leading-icon="rotate-ccw" @click="store.retryLoadMore(filters)">
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

    <!-- Create / edit drawer. -->
    <FunctionEditorDrawer
      v-model:open="editorOpen"
      :func="editing"
      :operations-catalog="operationsCatalog"
      :submitting="submitting"
      :server-errors="serverErrors"
      @submit="onSubmit"
    />

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
