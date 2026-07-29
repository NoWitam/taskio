<script setup lang="ts">
// SessionsView — the workspace generation SESSIONS list (a child of GeneratorModuleLayout, reached from
// its "Sesje" module nav). Mirrors `TemplatesView.vue`: the MANDATORY FilterBar with the #top Saved
// Views FilterTabBar, a debounced `search` + a single `status` select (the two server filters), a
// cursor-paginated list of SessionRow with infinite scroll, row-shaped skeletons, an EmptyState
// (first-run vs no-results vs error), and a load-more retry. "Nowa sesja" opens the TemplatePickerModal
// → create-from-template → route to the chat. Delete runs through useConfirm + useToast.
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import FilterBar, { type ActiveFilter } from '../../ui/patterns/FilterBar.vue';
import FilterTabBar from '../../ui/patterns/FilterTabBar.vue';
import SaveViewModal, { type SaveViewSubmit } from '../../ui/patterns/SaveViewModal.vue';
import ConfirmDialog from '../../ui/overlay/ConfirmDialog.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import SessionRow from './SessionRow.vue';
import TemplatePickerModal from './TemplatePickerModal.vue';
import { SESSION_STATUSES } from './session/sessionStatus';
import { useSessionsStore } from '../../app/stores/sessions';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useDebounce } from '../../app/composables/useDebounce';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useFilterTabs, type FilterSnapshot } from '../../app/composables/useFilterTabs';
import { useFilterTabsStore } from '../../app/stores/filterTabs';
import type { FilterTab } from '../../app/stores/filterTabs';
import { toIconEnumValue } from '../../ui/forms/filterTabIcon';
import { useI18n } from '../../app/i18n';
import type { Session, SessionFilters, SessionStatus } from './sessionTypes';

const { t } = useI18n();
const router = useRouter();
const store = useSessionsStore();
const toast = useToast();
const confirm = useConfirm();
const filterTabsStore = useFilterTabsStore();

/** Saved-views context for the sessions list (any string ≤64; backend-agnostic). */
const SAVED_VIEWS_CONTEXT = 'generator-sessions';

// --- Filter state (owned here) ---------------------------------------------
const search = ref('');
const statusFilter = ref<string>('');

const filters = computed<SessionFilters>(() => ({
  search: search.value || undefined,
  status: (statusFilter.value || undefined) as SessionStatus | undefined,
}));
const hasActiveFilters = computed(() => !!search.value || !!statusFilter.value);

const statusOptions = computed<SelectOption[]>(() => [
  { value: '', label: t('generator.sessions.filters.anyStatus') },
  ...SESSION_STATUSES.map((s) => ({ value: s, label: t(`generator.sessions.status.${s}`) })),
]);
function statusLabel(value: string): string {
  return t(`generator.sessions.status.${value}`, value);
}

// --- Active-filter chips ---------------------------------------------------
const activeFilters = computed<ActiveFilter[]>(() => {
  const chips: ActiveFilter[] = [];
  if (search.value) {
    chips.push({ key: `search=${search.value}`, label: t('workflows.filters.chip.search', '', { value: search.value }) });
  }
  if (statusFilter.value) {
    chips.push({ key: `status=${statusFilter.value}`, label: t('generator.sessions.filters.chip.status', '', { value: statusLabel(statusFilter.value) }) });
  }
  return chips;
});

function splitKey(key: string): { base: string; value: string } {
  const eq = key.indexOf('=');
  return eq >= 0 ? { base: key.slice(0, eq), value: key.slice(eq + 1) } : { base: key, value: '' };
}
function disabledChipLabel(key: string): string | null {
  const { base, value } = splitKey(key);
  if (base === 'search') return t('workflows.filters.chip.search', '', { value });
  if (base === 'status') return t('generator.sessions.filters.chip.status', '', { value: statusLabel(value) });
  return null;
}
const decoratedFilters = computed<ActiveFilter[]>(() => {
  const decorated = savedViews.decorateActiveFilters(activeFilters.value);
  return decorated.map((f) =>
    f.tabState === 'tab-disabled' && f.label === f.key ? { ...f, label: disabledChipLabel(f.key) ?? f.label } : f,
  );
});
function removeFilter(key: string): void {
  const { base } = splitKey(key);
  if (base === 'search') search.value = '';
  else if (base === 'status') statusFilter.value = '';
}
function clearAll(): void {
  search.value = '';
  statusFilter.value = '';
}

// --- Saved views (FilterTabs Stage 2) --------------------------------------
function serializeFiltersSnapshot(): FilterSnapshot {
  const snap: FilterSnapshot = {};
  if (search.value) snap.search = search.value;
  if (statusFilter.value) snap.status = statusFilter.value;
  return snap;
}
function applyFiltersSnapshot(snap: FilterSnapshot): void {
  search.value = typeof snap.search === 'string' ? snap.search : '';
  statusFilter.value = typeof snap.status === 'string' ? snap.status : '';
}
function normalizeSnapshot(snap: FilterSnapshot): string[] {
  const keys: string[] = [];
  if (snap.search) keys.push(`search=${String(snap.search)}`);
  if (snap.status) keys.push(`status=${String(snap.status)}`);
  return keys;
}
function restoreSnapshotValue(key: string, snap: FilterSnapshot): void {
  const { base } = splitKey(key);
  if (base === 'search') search.value = typeof snap.search === 'string' ? snap.search : '';
  else if (base === 'status') statusFilter.value = typeof snap.status === 'string' ? snap.status : '';
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
  void store.fetchSessions(filters.value, { reset: true });
}
const debouncedRefetch = useDebounce(refetch, 400);
watch(search, () => debouncedRefetch());
watch(statusFilter, () => refetch());

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

// --- New session (template picker → create → route to chat) ----------------
const pickerOpen = ref(false);
const creating = ref(false);

function onNew(): void {
  pickerOpen.value = true;
}
async function onPickTemplate(templateId: string): Promise<void> {
  creating.value = true;
  try {
    const session = await store.createSession({ template_id: templateId });
    pickerOpen.value = false;
    toast.success(t('generator.sessions.toasts.created'));
    void router.push({ name: 'next.generator.sessions.detail', params: { id: session.id } });
  } catch {
    toast.danger(t('generator.sessions.toasts.createError'));
  } finally {
    creating.value = false;
  }
}

function onOpen(session: Session): void {
  void router.push({ name: 'next.generator.sessions.detail', params: { id: session.id } });
}

// --- Delete (confirm + toast) ----------------------------------------------
async function onDelete(session: Session): Promise<void> {
  const ok = await confirm({
    title: t('generator.sessions.confirm.deleteTitle'),
    message: t('generator.sessions.confirm.deleteMessage', '', { name: session.name }),
    confirmLabel: t('generator.sessions.list.delete'),
    cancelLabel: t('common.cancel'),
    variant: 'danger',
  });
  if (!ok) return;
  try {
    await store.deleteSession(session.id);
    toast.success(t('generator.sessions.toasts.deleted'));
  } catch {
    toast.danger(t('generator.sessions.toasts.error'));
  }
}

// --- Archive / un-archive (2d) ---------------------------------------------
async function onArchive(session: Session): Promise<void> {
  try {
    if (session.is_archived) {
      await store.unarchiveSession(session.id);
      toast.success(t('generator.sessions.toasts.unarchived'));
    } else {
      await store.archiveSession(session.id);
      toast.success(t('generator.sessions.toasts.archived'));
    }
  } catch {
    toast.danger(t('generator.sessions.toasts.error'));
  }
}

onMounted(() => {
  void savedViews.load();
  refetch();
});
onUnmounted(() => store.resetAll());
</script>

<template>
  <div class="flex flex-col gap-next-6">
    <PageHeader
      icon="sparkles"
      :title="t('generator.sessions.title')"
      :description="t('generator.sessions.subtitle')"
    >
      <template #actions>
        <Button leading-icon="plus" @click="onNew">
          {{ t('generator.sessions.new') }}
        </Button>
      </template>
    </PageHeader>

    <FilterBar
      v-model:search="search"
      :search-placeholder="t('generator.sessions.filters.search')"
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

      <div class="min-w-[10rem] flex-1">
        <Select
          v-model="statusFilter"
          :options="statusOptions"
          leading-icon="loader"
          :placeholder="t('generator.sessions.filters.anyStatus')"
          :aria-label="t('generator.sessions.filters.statusLabel')"
        />
      </div>
    </FilterBar>

    <div class="flex flex-col gap-next-4">
      <!-- Error (initial load failed) with retry. -->
      <EmptyState
        v-if="store.errored && items.length === 0"
        variant="error"
        :title="t('generator.sessions.errors.title')"
        :description="t('generator.sessions.errors.description')"
      >
        <template #action>
          <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="refetch">
            {{ t('generator.sessions.errors.retry') }}
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
              <Skeleton variant="rect" width="6rem" height="1.25rem" radius="full" />
              <Skeleton variant="text" width="20%" />
            </div>
          </div>
        </li>
      </ul>

      <!-- Empty: filtered "no results" vs first-run copy. -->
      <EmptyState
        v-else-if="isEmpty"
        :variant="hasActiveFilters ? 'search' : 'default'"
        :icon="hasActiveFilters ? 'search' : 'sparkles'"
        :title="hasActiveFilters ? t('generator.sessions.empty.searchTitle') : t('generator.sessions.empty.title')"
        :description="hasActiveFilters ? t('generator.sessions.empty.searchDescription') : t('generator.sessions.empty.description')"
      >
        <template v-if="!hasActiveFilters" #action>
          <Button size="sm" leading-icon="plus" @click="onNew">
            {{ t('generator.sessions.new') }}
          </Button>
        </template>
      </EmptyState>

      <!-- Success: the rows + (when appending) trailing skeletons + sentinel. -->
      <template v-else>
        <ul class="flex flex-col gap-next-2" :aria-label="t('generator.sessions.title')">
          <li v-for="session in items" :key="session.id">
            <SessionRow :session="session" @open="onOpen" @delete="onDelete" @archive="onArchive" />
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
            <span>{{ t('generator.sessions.errors.description') }}</span>
            <Button variant="ghost" size="xs" leading-icon="rotate-ccw" @click="store.retryLoadMore(filters)">
              {{ t('generator.sessions.errors.retry') }}
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

    <!-- New session: pick a template → create → route to chat. -->
    <TemplatePickerModal v-model:open="pickerOpen" :submitting="creating" @select="onPickTemplate" />

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
