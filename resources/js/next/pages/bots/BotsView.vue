<script setup lang="ts">
// BotsView — the Bots (AI Character) BROWSE page (next, Batch 1). Mirrors the
// Pipelines list IA exactly: a FilterBar with the MANDATORY Saved Views toolbar in
// its #top slot + a single debounced `search` control (the ONLY server filter),
// then a cursor-paginated card grid with infinite scroll, card-shaped skeletons,
// an EmptyState (first-run vs no-results), and an error state with retry.
//
// A card click PREFETCHES the full bot, then navigates to the DETAIL view (so the
// detail renders already populated). Create / Edit open the editor DRAWER (owned
// by BotsModuleLayout) via the `?bot=new` / `?bot=<id>` query key. Delete runs
// through useConfirm + useToast.
//
// Filter state lives HERE, is pushed to the store (reset on change, debounced) and
// mirrored into the router query (preserving the `?bot` overlay key).
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import FilterBar, { type ActiveFilter } from '../../ui/patterns/FilterBar.vue';
import FilterTabBar from '../../ui/patterns/FilterTabBar.vue';
import SaveViewModal, { type SaveViewSubmit } from '../../ui/patterns/SaveViewModal.vue';
import ConfirmDialog from '../../ui/overlay/ConfirmDialog.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import EntityCard from '../../ui/patterns/EntityCard.vue';
import BotCard from './BotCard.vue';
import { useBotsStore } from '../../app/stores/bots';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useDebounce } from '../../app/composables/useDebounce';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useFilterTabs, type FilterSnapshot } from '../../app/composables/useFilterTabs';
import { useFilterTabsStore } from '../../app/stores/filterTabs';
import type { FilterTab } from '../../app/stores/filterTabs';
import { toIconEnumValue } from '../../ui/forms/filterTabIcon';
import { useI18n } from '../../app/i18n';
import type { BotFilters, BotListItem } from './types';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const store = useBotsStore();
const toast = useToast();
const confirm = useConfirm();
const filterTabsStore = useFilterTabsStore();

// Saved-views context for the Bots list (any string ≤64; backend-agnostic).
const SAVED_VIEWS_CONTEXT = 'bots';

// --- Filter state (owned here) -------------------------------------------
const search = ref('');

const filters = computed<BotFilters>(() => ({
  search: search.value || undefined,
}));

const hasActiveFilters = computed(() => !!search.value);

// --- Active-filter chips for the FilterBar --------------------------------
const activeFilters = computed<ActiveFilter[]>(() => {
  const chips: ActiveFilter[] = [];
  if (search.value) {
    chips.push({ key: `search=${search.value}`, label: t('bots.filters.chip.search', '', { value: search.value }) });
  }
  return chips;
});

function splitKey(key: string): { base: string; value: string } {
  const eq = key.indexOf('=');
  return eq >= 0 ? { base: key.slice(0, eq), value: key.slice(eq + 1) } : { base: key, value: '' };
}

function disabledChipLabel(key: string): string | null {
  const { base, value } = splitKey(key);
  if (base === 'search') return t('bots.filters.chip.search', '', { value });
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
  if (splitKey(key).base === 'search') search.value = '';
}

function clearAll(): void {
  search.value = '';
}

// --- Saved views (FilterTabs Stage 2) -------------------------------------
function serializeFiltersSnapshot(): FilterSnapshot {
  const snap: FilterSnapshot = {};
  if (search.value) snap.search = search.value;
  return snap;
}

function applyFiltersSnapshot(snap: FilterSnapshot): void {
  search.value = typeof snap.search === 'string' ? snap.search : '';
}

function normalizeSnapshot(snap: FilterSnapshot): string[] {
  const keys: string[] = [];
  if (snap.search) keys.push(`search=${String(snap.search)}`);
  return keys;
}

function restoreSnapshotValue(key: string, snap: FilterSnapshot): void {
  if (splitKey(key).base === 'search') {
    search.value = typeof snap.search === 'string' ? snap.search : '';
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
  store.fetchBots(filters.value, { reset: true });
}

// Search typing is debounced.
const debouncedRefetch = useDebounce(refetch, 400);
watch(search, () => debouncedRefetch());

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

// --- URL sync (search; preserve the ?bot overlay key) ---------------------
let hydrating = false;

function hydrateFromQuery(): void {
  hydrating = true;
  const q = route.query;
  const str = (v: unknown): string => (Array.isArray(v) ? String(v[0] ?? '') : String(v ?? ''));
  search.value = str(q.search);
  hydrating = false;
}

function syncQuery(): void {
  if (hydrating) return;
  const query: Record<string, string> = {};
  // Preserve the editor-drawer overlay key so a filter change never closes the
  // create/edit drawer the module layout owns.
  const b = route.query.bot;
  if (b != null && b !== '') query.bot = Array.isArray(b) ? String(b[0]) : String(b);
  if (search.value) query.search = search.value;
  void router.replace({ query });
}

watch(search, () => syncQuery());

// --- Create / edit (the editor DRAWER, owned by the module layout) --------
function onNewBot(): void {
  void router.push({ query: { ...route.query, bot: 'new' } });
}

function onEdit(bot: BotListItem): void {
  void router.push({ query: { ...route.query, bot: bot.id } });
}

// Open a bot's DETAIL view: PREFETCH the full bot first (so the detail renders
// already populated), then navigate. `opening` drives the card spinner.
const opening = ref<string | null>(null);
async function onOpen(bot: BotListItem): Promise<void> {
  if (opening.value) return;
  opening.value = bot.id;
  try {
    await store.fetchBot(bot.id);
    void router.push({ name: 'next.bots.detail', params: { id: bot.id } });
  } catch {
    toast.danger(t('bots.errors.title'));
  } finally {
    opening.value = null;
  }
}

// --- Delete (confirm + toast) ---------------------------------------------
async function onDelete(bot: BotListItem): Promise<void> {
  const ok = await confirm({
    title: t('bots.confirm.deleteTitle'),
    message: t('bots.confirm.deleteMessage', '', { name: bot.name }),
    confirmLabel: t('bots.actions.delete'),
    cancelLabel: t('common.cancel'),
    variant: 'danger',
  });
  if (!ok) return;
  try {
    await store.deleteBot(bot.id);
    toast.success(t('bots.toasts.deleted'));
  } catch {
    toast.danger(t('bots.toasts.actionError'));
  }
}

// --- Activate / Deactivate (status toggle via the endpoint) ---------------
const togglingStatus = ref<string | null>(null);
async function onToggleStatus(bot: BotListItem): Promise<void> {
  if (togglingStatus.value) return;
  const next = bot.status === 'active' ? 'inactive' : 'active';
  togglingStatus.value = bot.id;
  try {
    await store.setStatus(bot.id, next);
    toast.success(
      next === 'active'
        ? t('bots.statusAction.activated')
        : t('bots.statusAction.deactivated'),
    );
  } catch {
    toast.danger(t('bots.statusAction.error'));
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
      :title="t('bots.title')"
      :description="t('bots.subtitle')"
      icon="sparkles"
    >
      <template #actions>
        <Button leading-icon="plus" @click="onNewBot">
          {{ t('bots.newBot') }}
        </Button>
      </template>
    </PageHeader>

    <FilterBar
      v-model:search="search"
      :search-placeholder="t('bots.filters.search')"
      :active-filters="decoratedFilters"
      :clear-all-label="t('bots.filters.clearAll')"
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
    </FilterBar>

    <div class="flex flex-col gap-next-4">
      <!-- Error (initial load failed) with retry. -->
      <EmptyState
        v-if="store.errored && items.length === 0"
        variant="error"
        :title="t('bots.errors.title')"
        :description="t('bots.errors.description')"
      >
        <template #action>
          <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="refetch">
            {{ t('bots.errors.retry') }}
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

      <!-- Empty: filtered "no results" vs first-run copy. -->
      <EmptyState
        v-else-if="isEmpty"
        :variant="hasActiveFilters ? 'search' : 'default'"
        icon="sparkles"
        :title="hasActiveFilters ? t('bots.empty.searchTitle') : t('bots.empty.title')"
        :description="hasActiveFilters ? t('bots.empty.searchDescription') : t('bots.empty.description')"
      >
        <template v-if="!hasActiveFilters" #action>
          <Button size="sm" leading-icon="plus" @click="onNewBot">
            {{ t('bots.empty.action') }}
          </Button>
        </template>
      </EmptyState>

      <!-- Success: the grid + (when appending) trailing skeletons + sentinel. -->
      <template v-else>
        <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2 next-xl:grid-cols-3">
          <BotCard
            v-for="bot in items"
            :key="bot.id"
            :bot="bot"
            :opening="opening === bot.id"
            @open="onOpen"
            @edit="onEdit"
            @delete="onDelete"
            @toggle-status="onToggleStatus"
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
            <span>{{ t('bots.errors.description') }}</span>
            <Button variant="ghost" size="xs" leading-icon="rotate-ccw" @click="store.retryLoadMore(filters)">
              {{ t('bots.errors.retry') }}
            </Button>
          </div>
        </Alert>

        <!-- Infinite-scroll sentinel (only while more pages remain and not paused
             by an append error awaiting retry). -->
        <div
          v-if="store.hasMore && !store.errored && !store.loadMoreErrored"
          ref="sentinelRef"
          aria-hidden="true"
          class="h-px w-full"
        />
      </template>
    </div>

    <!-- Saved view: create / edit modal (no date filters here). -->
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
