<script setup lang="ts">
// KnowledgeBasesView — the KNOWLEDGE module's list of BASES (spec §3), and the module's default
// page. A module list, so it carries the MANDATORY FilterBar with the #top Saved Views
// FilterTabBar, then a cursor-paginated CARD GRID with infinite scroll, card-shaped skeletons, a
// two-path first-run EmptyState, a filtered "no results" state, and an error state with retry.
//
// The ONLY server filter is `search` (name + description — KnowledgeBaseService::index). The
// spec's index-state / language / red-link filters are NOT offered: the backend has no such
// query params, and filtering a CURSOR-paginated list on the client would silently filter one
// loaded page and call it the answer. See the batch report.
//
// Create / Settings open the KnowledgeBaseSettingsDrawer (owned here); the drawer EMITS a write
// payload, this view performs the store call, surfaces 422 field errors back to it, and toasts.
// Trash runs through useConfirm + useToast.
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import FilterBar, { type ActiveFilter } from '../../ui/patterns/FilterBar.vue';
import FilterTabBar from '../../ui/patterns/FilterTabBar.vue';
import Switch from '../../ui/forms/Switch.vue';
import SaveViewModal, { type SaveViewSubmit } from '../../ui/patterns/SaveViewModal.vue';
import ConfirmDialog from '../../ui/overlay/ConfirmDialog.vue';
import BotKnowledgeMigrationModal from './BotKnowledgeMigrationModal.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import EntityCard from '../../ui/patterns/EntityCard.vue';
import KnowledgeBaseCard from './KnowledgeBaseCard.vue';
import KnowledgeBaseSettingsDrawer from './KnowledgeBaseSettingsDrawer.vue';
import { useKnowledgeStore } from '../../app/stores/knowledge';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useDebounce } from '../../app/composables/useDebounce';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useFilterTabs, type FilterSnapshot } from '../../app/composables/useFilterTabs';
import { useFilterTabsStore, type FilterTab } from '../../app/stores/filterTabs';
import { toIconEnumValue } from '../../ui/forms/filterTabIcon';
import { useI18n } from '../../app/i18n';
import type { KnowledgeBase, KnowledgeBaseFilters, KnowledgeBaseWritePayload } from './types';

const { t } = useI18n();
const router = useRouter();
const store = useKnowledgeStore();
const toast = useToast();
const confirm = useConfirm();
const filterTabsStore = useFilterTabsStore();

/** Saved-views context for the bases list (any string ≤64; backend-agnostic). */
const SAVED_VIEWS_CONTEXT = 'knowledge-bases';

// --- Filter state (owned here) ---------------------------------------------
const search = ref('');

/**
 * THE TRASH, as a filter on this list rather than a screen of its own.
 *
 * The entry trash was removed on purpose — cleaning up entries is the agent's job or a GDPR
 * command's. BASES are a different question and were never covered by that: the owner withdrew
 * manual authorship of entries and relations, while a base keeps its whole life cycle, and its
 * charter is required by the GDPR procedure. Deleting the trash screen took the only way back with
 * it, so a base could be thrown away and not recovered.
 *
 * A filter rather than a route, for three reasons. A whole screen for ONE object type and TWO
 * actions is more navigation than there is content — and a screen is what was just removed, so
 * re-adding one would put back the cost the removal was paying down. The store's list already
 * supports `trashed`, so this is a state of this list, not a different list. And because it lives
 * in the FilterBar, a saved view can capture it: "Deleted bases" becomes a tab somebody keeps,
 * which is the module's own convention for list state.
 */
const trashed = ref(false);

const filters = computed<KnowledgeBaseFilters>(() => ({
  search: search.value || undefined,
  trashed: trashed.value || undefined,
}));
const hasActiveFilters = computed(() => !!search.value || trashed.value);

// --- Active-filter chips ---------------------------------------------------
const activeFilters = computed<ActiveFilter[]>(() => {
  const chips: ActiveFilter[] = [];
  if (trashed.value) {
    chips.push({ key: 'trashed', label: t('knowledge.bases.filters.trashedChip') });
  }
  if (search.value) {
    chips.push({ key: `search=${search.value}`, label: t('knowledge.filters.chip.search', '', { value: search.value }) });
  }
  return chips;
});

function splitKey(key: string): { base: string; value: string } {
  const eq = key.indexOf('=');
  return eq >= 0 ? { base: key.slice(0, eq), value: key.slice(eq + 1) } : { base: key, value: '' };
}
function disabledChipLabel(key: string): string | null {
  const { base, value } = splitKey(key);
  return base === 'search' ? t('knowledge.filters.chip.search', '', { value }) : null;
}
const decoratedFilters = computed<ActiveFilter[]>(() => {
  const decorated = savedViews.decorateActiveFilters(activeFilters.value);
  return decorated.map((f) =>
    f.tabState === 'tab-disabled' && f.label === f.key ? { ...f, label: disabledChipLabel(f.key) ?? f.label } : f,
  );
});
function removeFilter(key: string): void {
  if (splitKey(key).base === 'search') search.value = '';
  if (splitKey(key).base === 'trashed') trashed.value = false;
}
function clearAll(): void {
  search.value = '';
  trashed.value = false;
}

// --- Saved views (FilterTabs Stage 2) --------------------------------------
function serializeFiltersSnapshot(): FilterSnapshot {
  const snap: FilterSnapshot = {};
  if (search.value) snap.search = search.value;
  if (trashed.value) snap.trashed = true;
  return snap;
}
function applyFiltersSnapshot(snap: FilterSnapshot): void {
  search.value = typeof snap.search === 'string' ? snap.search : '';
  trashed.value = snap.trashed === true;
}
function normalizeSnapshot(snap: FilterSnapshot): string[] {
  const keys: string[] = [];
  if (snap.trashed) keys.push('trashed');
  if (snap.search) keys.push(`search=${String(snap.search)}`);
  return keys;
}
function restoreSnapshotValue(key: string, snap: FilterSnapshot): void {
  if (splitKey(key).base === 'search') search.value = typeof snap.search === 'string' ? snap.search : '';
  if (splitKey(key).base === 'trashed') trashed.value = snap.trashed === true;
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
  void store.fetchBases(filters.value, { reset: true });
}
const debouncedRefetch = useDebounce(refetch, 400);
watch(search, () => debouncedRefetch());
// Switching the trash on or off swaps the whole list, so it refetches at once rather than waiting
// out the search debounce.
watch(trashed, () => refetch());

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
const skeletonKeys = Array.from({ length: 6 }, (_, i) => i);

// --- Settings drawer (create / edit) ---------------------------------------
const drawerOpen = ref(false);
const editing = ref<KnowledgeBase | null>(null);
const submitting = ref(false);
const serverErrors = ref<Record<string, string> | null>(null);

function onNew(): void {
  editing.value = null;
  serverErrors.value = null;
  drawerOpen.value = true;
}

// --- Importing a bot's built-in knowledge (B6) -------------------------------
const migrationOpen = ref(false);

/**
 * A migration created a base. Land the user IN it (the reader), because the point of the import
 * was to get somewhere they can read and edit — a toast on the list would leave them to find it.
 */
function onMigrated(result: { knowledge_base_id: string }): void {
  void router.push({
    name: 'next.knowledge.base.reader',
    params: { baseId: result.knowledge_base_id },
  });
}

/**
 * Opening a base goes to the READER (B5). B4 sent it to Settings only because the reader did not
 * exist yet — clicking a base's name should land on its contents, not its configuration.
 */
function onOpen(base: KnowledgeBase): void {
  void router.push({ name: 'next.knowledge.base.reader', params: { baseId: base.id } });
}

/**
 * Settings now has a real page under the base. The drawer stays for CREATE only, so the two paths
 * still share one form without the list owning an edit surface that duplicates the section.
 */
function onSettings(base: KnowledgeBase): void {
  void router.push({ name: 'next.knowledge.base.settings', params: { baseId: base.id } });
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

async function onSubmit(payload: KnowledgeBaseWritePayload): Promise<void> {
  submitting.value = true;
  serverErrors.value = null;
  try {
    if (editing.value) {
      await store.updateBase(editing.value.id, payload);
      toast.success(t('knowledge.bases.toasts.updated'));
    } else {
      await store.createBase(payload);
      toast.success(t('knowledge.bases.toasts.created'));
    }
    drawerOpen.value = false;
  } catch (err: unknown) {
    // The drawer stays OPEN on failure — closing it would discard everything typed.
    const fieldErrors = fieldErrorsOf(err);
    if (fieldErrors) serverErrors.value = fieldErrors;
    else toast.danger(t('knowledge.bases.toasts.error'));
  } finally {
    submitting.value = false;
  }
}

// --- Trash (confirm + toast) -----------------------------------------------
/** Bring a base back. Reversing a mistake, so no confirmation stands between it and the user. */
async function onRestore(base: KnowledgeBase): Promise<void> {
  try {
    await store.restoreBase(base.id);
    toast.success(t('knowledge.bases.toasts.restored'));
  } catch {
    toast.danger(t('knowledge.common.saveError'));
  }
}

/**
 * Destroy a trashed base and everything under it. The only irreversible act on this screen, and
 * the confirmation says what goes with it — a base is a container, so "delete the base" is never
 * only about the base.
 */
async function onPurge(base: KnowledgeBase): Promise<void> {
  const ok = await confirm({
    title: t('knowledge.bases.purgeConfirm.title'),
    message: t('knowledge.bases.purgeConfirm.message', '', { count: base.entries_count ?? 0 }),
    confirmLabel: t('knowledge.bases.menu.purge'),
    cancelLabel: t('common.cancel'),
    variant: 'danger',
  });
  if (!ok) return;
  try {
    await store.forceDeleteBase(base.id);
    toast.success(t('knowledge.bases.toasts.purged'));
  } catch {
    toast.danger(t('knowledge.common.saveError'));
  }
}

async function onTrash(base: KnowledgeBase): Promise<void> {
  const ok = await confirm({
    title: t('knowledge.bases.trashConfirm.title'),
    message: t('knowledge.bases.trashConfirm.message', '', { count: base.entries_count ?? 0 }),
    confirmLabel: t('knowledge.bases.menu.trash'),
    cancelLabel: t('common.cancel'),
    variant: 'danger',
  });
  if (!ok) return;
  try {
    await store.deleteBase(base.id);
    toast.success(t('knowledge.bases.toasts.trashed'));
  } catch {
    toast.danger(t('knowledge.bases.toasts.error'));
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
      icon="book-open"
      :title="t('knowledge.title')"
      :description="t('knowledge.subtitle')"
    >
      <template #actions>
        <Button leading-icon="plus" @click="onNew">{{ t('knowledge.bases.new') }}</Button>
      </template>
    </PageHeader>

    <FilterBar
      v-model:search="search"
      :search-placeholder="t('knowledge.bases.filters.search')"
      :active-filters="decoratedFilters"
      :clear-all-label="t('knowledge.filters.clearAll')"
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
          @move-up="(view: FilterTab) => moveView(view, -1)"
          @move-down="(view: FilterTab) => moveView(view, 1)"
          @retry="savedViews.load"
        />
      </template>

      <!--
        The way into the trash, and back out of it. A Switch rather than a link, because this is a
        STATE of the list a saved view can keep — not a place to go.
      -->
      <Switch
        v-model="trashed"
        size="sm"
        :label="t('knowledge.bases.filters.trashed')"
        data-trashed-toggle
      />
    </FilterBar>

    <div class="flex flex-col gap-next-4">
      <!-- Error (initial load failed) with retry. -->
      <EmptyState
        v-if="store.errored && items.length === 0"
        variant="error"
        :title="t('knowledge.bases.errors.title')"
        :description="t('knowledge.bases.errors.description')"
      >
        <template #action>
          <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="refetch">
            {{ t('knowledge.common.retry') }}
          </Button>
        </template>
      </EmptyState>

      <!-- Initial loading: card-shaped skeletons in the real grid (never a spinner). -->
      <div
        v-else-if="initialLoading"
        role="status"
        :aria-label="t('knowledge.common.loadingLabel')"
        class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2 next-xl:grid-cols-3"
      >
        <EntityCard v-for="n in skeletonKeys" :key="`sk-${n}`" loading />
      </div>

      <!-- Empty: filtered "no results" vs the two-path first run. -->
      <EmptyState
        v-else-if="isEmpty"
        :variant="hasActiveFilters ? 'search' : 'default'"
        :icon="hasActiveFilters ? 'search' : 'book-open'"
        :title="hasActiveFilters ? t('knowledge.bases.emptySearch.title') : t('knowledge.bases.empty.title')"
        :description="hasActiveFilters ? t('knowledge.bases.emptySearch.description') : t('knowledge.bases.empty.description')"
      >
        <template #action>
          <Button v-if="hasActiveFilters" variant="outline" size="sm" leading-icon="x" @click="clearAll">
            {{ t('knowledge.bases.emptySearch.action') }}
          </Button>
          <Button v-else size="sm" leading-icon="plus" @click="onNew">
            {{ t('knowledge.bases.empty.create') }}
          </Button>
        </template>

        <!-- Second path: importing a bot's built-in knowledge (B6). Live since the migration
             endpoint landed — the modal picks the bot and previews what will be created. -->
        <template v-if="!hasActiveFilters" #secondary>
          <Button variant="outline" size="sm" leading-icon="sparkles" @click="migrationOpen = true">
            {{ t('knowledge.bases.empty.migrate') }}
          </Button>
        </template>
      </EmptyState>

      <!-- Success: the card grid + (when appending) trailing skeletons + sentinel. -->
      <template v-else>
        <ul
          class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2 next-xl:grid-cols-3"
          :aria-label="t('knowledge.bases.listLabel')"
        >
          <li v-for="base in items" :key="base.id">
            <KnowledgeBaseCard
              :base="base"
              @open="onOpen"
              @settings="onSettings"
              @trash="onTrash"
              @restore="onRestore"
              @purge="onPurge"
            />
          </li>
        </ul>

        <div
          v-if="store.loadingMore"
          role="status"
          :aria-label="t('knowledge.common.loadingLabel')"
          class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2 next-xl:grid-cols-3"
        >
          <EntityCard v-for="n in 3" :key="`more-sk-${n}`" loading />
        </div>

        <Alert v-if="store.loadMoreErrored && items.length > 0" variant="danger" size="sm">
          <div class="flex items-center justify-between gap-next-2">
            <span>{{ t('knowledge.bases.errors.description') }}</span>
            <Button variant="ghost" size="xs" leading-icon="rotate-ccw" @click="store.retryLoadMore(filters)">
              {{ t('knowledge.common.retry') }}
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

    <!-- Create / edit settings drawer. -->
    <KnowledgeBaseSettingsDrawer
      v-model:open="drawerOpen"
      :base="editing"
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

    <!-- Import a bot's built-in knowledge into a new base (B6). -->
    <BotKnowledgeMigrationModal v-model:open="migrationOpen" @migrated="onMigrated" />
  </div>
</template>
