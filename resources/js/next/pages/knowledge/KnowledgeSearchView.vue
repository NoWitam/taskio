<script setup lang="ts">
// KnowledgeSearchView — hybrid (keyword + semantic) search over the workspace's knowledge, or over
// one base when opened from inside it.
//
// ─────────────────────────────────────────────────────────────────────────────
// EVERY QUERY COSTS MONEY.
// ─────────────────────────────────────────────────────────────────────────────
// The semantic leg EMBEDS the query string, which is a paid provider call. So this screen never
// searches per keystroke: Enter fires immediately, and typing is debounced by a hard 300 ms. That
// is also why the store deliberately does not debounce on the caller's behalf — the spend stays
// visible at the call site.
//
// THERE IS NO PAGINATION, by contract. A relevance ranking has no stable cursor (the order is
// recomputed from a fresh query embedding every time), so `has_more` means "this list was CUT at
// `limit`" and is rendered as exactly that — a note, not a "load more" button that would re-rank
// and re-charge for a different answer.
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import FilterBar, { type ActiveFilter } from '../../ui/patterns/FilterBar.vue';
import FilterTabBar from '../../ui/patterns/FilterTabBar.vue';
import SaveViewModal, { type SaveViewSubmit } from '../../ui/patterns/SaveViewModal.vue';
import ConfirmDialog from '../../ui/overlay/ConfirmDialog.vue';
import Select from '../../ui/forms/Select.vue';
import Button from '../../ui/primitives/Button.vue';
import Text from '../../ui/primitives/Text.vue';
import Alert from '../../ui/feedback/Alert.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import Surface from '../../ui/layout/Surface.vue';
import KnowledgeSearchResultCard from './search/KnowledgeSearchResultCard.vue';
import { useKnowledgeStore } from '../../app/stores/knowledge';
import { useToast } from '../../app/composables/useToast';
import { useFilterTabs, type FilterSnapshot } from '../../app/composables/useFilterTabs';
import { useFilterTabsStore, type FilterTab } from '../../app/stores/filterTabs';
import { toIconEnumValue } from '../../ui/forms/filterTabIcon';
import { ENTRY_STATUSES } from './statusMaps';

import { useI18n } from '../../app/i18n';
import { KNOWLEDGE_SEARCH_MAX_RESULTS, type KnowledgeEntryStatus, type KnowledgeSearchResult } from './types';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const store = useKnowledgeStore();
const toast = useToast();
const filterTabsStore = useFilterTabsStore();

const SAVED_VIEWS_CONTEXT = 'knowledge-search';

/** Base-scoped when the route carries one; workspace-wide otherwise. */
const baseId = computed(() => (route.params.baseId ? String(route.params.baseId) : undefined));

// --- Query + filters --------------------------------------------------------
const search = ref(typeof route.query.q === 'string' ? route.query.q : '');
const statuses = ref<KnowledgeEntryStatus[]>([]);

const hasActiveFilters = computed(() => !!search.value || statuses.value.length > 0);

const statusOptions = computed(() =>
  ENTRY_STATUSES.map((value) => ({ value, label: t(`knowledge.status.${value}`) })),
);

const activeFilters = computed<ActiveFilter[]>(() => {
  const chips: ActiveFilter[] = [];
  if (search.value) {
    chips.push({ key: `q=${search.value}`, label: t('knowledge.filters.chip.query', '', { value: search.value }) });
  }
  for (const status of statuses.value) {
    chips.push({
      key: `status=${status}`,
      label: t('knowledge.filters.chip.status', '', { value: t(`knowledge.status.${status}`) }),
    });
  }
  return chips;
});

function splitKey(key: string): { base: string; value: string } {
  const eq = key.indexOf('=');
  return eq >= 0 ? { base: key.slice(0, eq), value: key.slice(eq + 1) } : { base: key, value: '' };
}

function disabledChipLabel(key: string): string | null {
  const { base, value } = splitKey(key);
  if (base === 'q') return t('knowledge.filters.chip.query', '', { value });
  if (base === 'status') return t('knowledge.filters.chip.status', '', { value: t(`knowledge.status.${value}`) });
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
  const { base, value } = splitKey(key);
  if (base === 'q') search.value = '';
  else if (base === 'status') statuses.value = statuses.value.filter((s) => s !== value);
}

function clearAll(): void {
  search.value = '';
  statuses.value = [];
}

// --- Saved views ("saved searches" here, which is what they naturally are) ---
function serializeFiltersSnapshot(): FilterSnapshot {
  const snap: FilterSnapshot = {};
  if (search.value) snap.q = search.value;
  if (statuses.value.length) snap.status = [...statuses.value];
  return snap;
}

function applyFiltersSnapshot(snap: FilterSnapshot): void {
  search.value = typeof snap.q === 'string' ? snap.q : '';
  statuses.value = Array.isArray(snap.status) ? (snap.status as KnowledgeEntryStatus[]) : [];
}

function normalizeSnapshot(snap: FilterSnapshot): string[] {
  const keys: string[] = [];
  if (snap.q) keys.push(`q=${String(snap.q)}`);
  for (const status of (snap.status as string[]) ?? []) keys.push(`status=${status}`);
  return keys;
}

function restoreSnapshotValue(key: string, snap: FilterSnapshot): void {
  const { base, value } = splitKey(key);
  if (base === 'q') search.value = typeof snap.q === 'string' ? snap.q : '';
  else if (base === 'status' && !statuses.value.includes(value as KnowledgeEntryStatus)) {
    statuses.value = [...statuses.value, value as KnowledgeEntryStatus];
  }
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
    if (e.fieldErrors?.name) saveModalNameError.value = e.fieldErrors.name;
    else toast.danger(t('tasks.savedViews.toast.saveError'));
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
  } catch {
    toast.danger(t('tasks.savedViews.errors.invalidReorder'));
    void savedViews.load();
  }
}

function onRestoreFilter(key: string): void {
  savedViews.restoreFilter(key);
}

// --- Running the search -----------------------------------------------------
function runSearch(): void {
  const term = search.value.trim();
  // Mirror the query into the URL so a result set is linkable and survives a reload.
  void router.replace({ query: term ? { ...route.query, q: term } : omitQ(route.query) });

  if (term === '') {
    store.resetSearch();
    return;
  }
  void store.searchKnowledge(term, {
    baseId: baseId.value,
    statuses: statuses.value.length ? statuses.value : undefined,
    limit: KNOWLEDGE_SEARCH_MAX_RESULTS,
  });
}

function omitQ(query: Record<string, unknown>): Record<string, unknown> {
  const next = { ...query };
  delete next.q;
  return next;
}

// EXACTLY ONE debounce, and it is FilterBar's: `v-model:search` is the COMMITTED value, published
// only after `searchDebounce` ms. Wrapping this watcher in another `useDebounce` — the obvious
// reflex — would stack two timers and delay every search by up to 600 ms while still firing once.
// The window is pinned explicitly on the component below rather than left to a default, because
// here it is a spend control, not a UI nicety.
watch(search, () => runSearch());
watch(statuses, () => runSearch(), { deep: true });

// --- Result actions ---------------------------------------------------------
function readerLocation(result: KnowledgeSearchResult, offset?: number) {
  return {
    name: 'next.knowledge.base.reader',
    params: { baseId: result.knowledge_base_id, slug: result.slug },
    ...(offset != null ? { query: { offset: String(offset) } } : {}),
  };
}

function onOpen(result: KnowledgeSearchResult): void {
  void router.push(readerLocation(result));
}

/**
 * "Jump to passage": the reader receives the chunk's `char_start` and resolves it to the heading
 * that owns it. The offset is into the entry's own content, so it survives re-rendering — unlike
 * anything derived from the rendered DOM.
 */
function onJump(result: KnowledgeSearchResult): void {
  const start = result.matched_chunk?.char_start;
  void router.push(readerLocation(result, start));
}

/** The reason the semantic leg sat out, in words the user can act on. */
const vectorNotice = computed(() => {
  if (!store.searchVectorSkipped) return null;
  const reason = store.searchVectorReason;
  return t(`knowledge.search.vectorSkipped.${reason ?? 'error'}`, t('knowledge.search.vectorSkipped.error'));
});

const showStart = computed(() => search.value.trim() === '' && !store.searchLoading);
const showEmpty = computed(
  () => !store.searchLoading && !store.searchErrored && search.value.trim() !== '' && store.searchResults.length === 0,
);

const exampleQueries = computed<string[]>(() => [
  t('knowledge.search.examples.one'),
  t('knowledge.search.examples.two'),
  t('knowledge.search.examples.three'),
]);

function useExample(example: string): void {
  search.value = example;
  runSearch();
}

onMounted(() => {
  void savedViews.load();
  if (search.value.trim()) runSearch();
});
onUnmounted(() => store.resetSearch());
</script>

<template>
  <div class="flex flex-col gap-next-6">
    <PageHeader icon="search" :title="t('knowledge.search.title')" :description="t('knowledge.search.subtitle')" />

    <FilterBar
      v-model:search="search"
      :search-debounce="300"
      :search-placeholder="t('knowledge.search.placeholder')"
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

      <div class="min-w-0 flex-1 basis-40">
        <Select
          v-model="statuses"
          multiple
          leading-icon="check-circle"
          :options="statusOptions"
          :placeholder="t('knowledge.filters.status')"
          :aria-label="t('knowledge.filters.status')"
        />
      </div>
    </FilterBar>

    <!-- The semantic leg did not run. Says WHY, because "worse results, no explanation" is how a
         user concludes search is broken. -->
    <Alert v-if="vectorNotice" variant="warning" size="sm">{{ vectorNotice }}</Alert>

    <div class="flex flex-col gap-next-3">
      <div v-if="store.searchResults.length > 0" class="flex flex-wrap items-baseline gap-next-3">
        <Text variant="caption" tone="muted">
          {{ t('knowledge.search.results', '', { count: store.searchCount }) }}
        </Text>
        <!-- `has_more` means TRUNCATED, not "page 2 exists". Worded that way. -->
        <Text v-if="store.searchHasMore" variant="caption" tone="muted">
          {{ t('knowledge.search.truncated', '', { limit: store.searchLimit }) }}
        </Text>
      </div>

      <!-- Loading: card-shaped skeletons (title, path, two snippet lines, score bar). -->
      <div
        v-if="store.searchLoading"
        class="flex flex-col gap-next-3"
        role="status"
        :aria-label="t('knowledge.common.loadingLabel')"
      >
        <Surface
          v-for="n in 4"
          :key="`sk-${n}`"
          bg="card"
          border
          radius="lg"
          class="flex flex-col gap-next-2 p-next-4"
        >
          <Skeleton variant="text" width="45%" />
          <Skeleton variant="text" width="30%" />
          <Skeleton variant="text" width="100%" />
          <Skeleton variant="text" width="80%" />
          <Skeleton variant="rect" width="80px" height="6px" radius="full" />
        </Surface>
      </div>

      <EmptyState
        v-else-if="store.searchErrored"
        variant="error"
        :title="t('knowledge.common.loadError')"
      >
        <template #action>
          <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="runSearch">
            {{ t('knowledge.common.retry') }}
          </Button>
        </template>
      </EmptyState>

      <!-- Nothing typed yet: three real examples beat an empty box. -->
      <EmptyState
        v-else-if="showStart"
        icon="search"
        :title="t('knowledge.search.start.title')"
        :description="t('knowledge.search.start.description')"
      >
        <template #action>
          <div class="flex flex-wrap justify-center gap-next-2">
            <Button
              v-for="example in exampleQueries"
              :key="example"
              variant="outline"
              size="sm"
              @click="useExample(example)"
            >
              {{ example }}
            </Button>
          </div>
        </template>
      </EmptyState>

      <EmptyState
        v-else-if="showEmpty"
        variant="search"
        :title="t('knowledge.search.empty.title')"
        :description="t('knowledge.search.empty.description')"
      >
        <template #action>
          <Button variant="outline" size="sm" leading-icon="x" @click="clearAll">
            {{ t('knowledge.filters.clearAll') }}
          </Button>
        </template>
      </EmptyState>

      <ul v-else class="flex flex-col gap-next-3">
        <KnowledgeSearchResultCard
          v-for="result in store.searchResults"
          :key="result.id"
          :result="result"
          :show-base="!baseId"
          @open="onOpen"
          @jump="onJump"
        />
      </ul>
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
