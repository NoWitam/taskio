<script setup lang="ts">
// KnowledgeEntriesTableView — the base's entries as a table: the bulk-review surface next to the
// reader's one-entry-at-a-time view.
//
// ONLY THE FILTERS THE SERVER HAS. `status[]`, `stale` and `search` are the three query params
// KnowledgeEntryService::filtered reads — so they are the three controls here. The spec also asked
// for index-state, author, date-range and per-enum-metadata filters; none exist server-side, and
// filtering a CURSOR-paginated list in the browser would filter the one page that happens to be
// loaded and present that as the answer. A filter that lies is worse than a filter that is missing.
//
// Sorting is not offered for the same reason: the endpoint orders by `position, id`, full stop.
// That is also what keeps this table and the reader's contents panel in the same order.
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import FilterBar, { type ActiveFilter } from '../../ui/patterns/FilterBar.vue';
import FilterTabBar from '../../ui/patterns/FilterTabBar.vue';
import SaveViewModal, { type SaveViewSubmit } from '../../ui/patterns/SaveViewModal.vue';
import ConfirmDialog from '../../ui/overlay/ConfirmDialog.vue';
import Table, { type TableColumn } from '../../ui/data/Table.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Button from '../../ui/primitives/Button.vue';
import Link from '../../ui/primitives/Link.vue';
import Text from '../../ui/primitives/Text.vue';
import Select from '../../ui/forms/Select.vue';
import Switch from '../../ui/forms/Switch.vue';
import SegmentedControl from '../../ui/forms/SegmentedControl.vue';
import Alert from '../../ui/feedback/Alert.vue';
import DropdownMenu from '../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../ui/overlay/DropdownMenuItem.vue';
import CreatorBadge from '../../ui/patterns/CreatorBadge.vue';
import KnowledgeVersionsDrawer from './KnowledgeVersionsDrawer.vue';
import { useKnowledgeStore } from '../../app/stores/knowledge';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useFilterTabs, type FilterSnapshot } from '../../app/composables/useFilterTabs';
import { useFilterTabsStore, type FilterTab } from '../../app/stores/filterTabs';
import { toIconEnumValue } from '../../ui/forms/filterTabIcon';
import { entryStatusMap, indexStatusMap, ENTRY_STATUSES } from './statusMaps';
import { entryIndexLabel } from './entryMeta';
import { amendLocation, composeLocation } from './composeSeed';
import { formatDate } from './baseMeta';
import { useI18n } from '../../app/i18n';
import type { KnowledgeEntryFilters, KnowledgeEntryListItem, KnowledgeEntryStatus } from './types';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const store = useKnowledgeStore();
const toast = useToast();
const confirm = useConfirm();
const filterTabsStore = useFilterTabsStore();

const SAVED_VIEWS_CONTEXT = 'knowledge-entries';

const baseId = computed(() => String(route.params.baseId ?? ''));
const statusMap = computed(() => entryStatusMap(t));
const indexMap = computed(() => indexStatusMap(t));

// --- Filter state -----------------------------------------------------------
const search = ref('');
const statuses = ref<KnowledgeEntryStatus[]>([]);
const staleOnly = ref(false);

const filters = computed<KnowledgeEntryFilters>(() => ({
  search: search.value || undefined,
  status: statuses.value.length ? statuses.value : undefined,
  stale: staleOnly.value || undefined,
}));

const hasActiveFilters = computed(
  () => !!search.value || statuses.value.length > 0 || staleOnly.value,
);

const statusOptions = computed(() =>
  ENTRY_STATUSES.map((value) => ({ value, label: t(`knowledge.status.${value}`) })),
);

// --- Active-filter chips ----------------------------------------------------
// Multi-value filters render ONE CHIP PER VALUE, never "2 selected" — the point of a chip row is
// that the user can see and remove each choice.
const activeFilters = computed<ActiveFilter[]>(() => {
  const chips: ActiveFilter[] = [];
  if (search.value) {
    chips.push({
      key: `search=${search.value}`,
      label: t('knowledge.filters.chip.search', '', { value: search.value }),
    });
  }
  for (const status of statuses.value) {
    chips.push({
      key: `status=${status}`,
      label: t('knowledge.filters.chip.status', '', { value: t(`knowledge.status.${status}`) }),
    });
  }
  if (staleOnly.value) {
    chips.push({ key: 'stale=1', label: t('knowledge.filters.stale') });
  }
  return chips;
});

function splitKey(key: string): { base: string; value: string } {
  const eq = key.indexOf('=');
  return eq >= 0 ? { base: key.slice(0, eq), value: key.slice(eq + 1) } : { base: key, value: '' };
}

function disabledChipLabel(key: string): string | null {
  const { base, value } = splitKey(key);
  if (base === 'search') return t('knowledge.filters.chip.search', '', { value });
  if (base === 'status') return t('knowledge.filters.chip.status', '', { value: t(`knowledge.status.${value}`) });
  if (base === 'stale') return t('knowledge.filters.stale');
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
  if (base === 'search') search.value = '';
  else if (base === 'status') statuses.value = statuses.value.filter((s) => s !== value);
  else if (base === 'stale') staleOnly.value = false;
}

function clearAll(): void {
  search.value = '';
  statuses.value = [];
  staleOnly.value = false;
}

// --- Saved views ------------------------------------------------------------
function serializeFiltersSnapshot(): FilterSnapshot {
  const snap: FilterSnapshot = {};
  if (search.value) snap.search = search.value;
  if (statuses.value.length) snap.status = [...statuses.value];
  if (staleOnly.value) snap.stale = true;
  return snap;
}

function applyFiltersSnapshot(snap: FilterSnapshot): void {
  search.value = typeof snap.search === 'string' ? snap.search : '';
  statuses.value = Array.isArray(snap.status) ? (snap.status as KnowledgeEntryStatus[]) : [];
  staleOnly.value = snap.stale === true;
}

function normalizeSnapshot(snap: FilterSnapshot): string[] {
  const keys: string[] = [];
  if (snap.search) keys.push(`search=${String(snap.search)}`);
  for (const status of (snap.status as string[]) ?? []) keys.push(`status=${status}`);
  if (snap.stale === true) keys.push('stale=1');
  return keys;
}

function restoreSnapshotValue(key: string, snap: FilterSnapshot): void {
  const { base, value } = splitKey(key);
  if (base === 'search') search.value = typeof snap.search === 'string' ? snap.search : '';
  else if (base === 'status') {
    if (!statuses.value.includes(value as KnowledgeEntryStatus)) {
      statuses.value = [...statuses.value, value as KnowledgeEntryStatus];
    }
  } else if (base === 'stale') staleOnly.value = snap.stale === true;
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

// --- Fetch orchestration ----------------------------------------------------
function refetch(): void {
  if (baseId.value) void store.fetchEntries(baseId.value, filters.value, { reset: true });
}

// `v-model:search` is FilterBar's COMMITTED value — already debounced by the bar itself — so this
// watcher fires once per settled query, not per keystroke. Adding a second `useDebounce` here would
// stack two timers for no benefit.
watch(search, () => refetch());
watch([statuses, staleOnly, baseId], () => refetch(), { deep: true });

const { sentinelRef } = useInfiniteScroll({
  onLoadMore: () => void store.loadMoreEntries(baseId.value, filters.value),
  canLoadMore: () =>
    store.entriesHasMore &&
    !store.entriesLoading &&
    !store.entriesLoadingMore &&
    !store.entriesErrored &&
    !store.entriesLoadMoreErrored,
});

// --- Columns ----------------------------------------------------------------
const columns = computed<TableColumn<KnowledgeEntryListItem>[]>(() => [
  { key: 'title', label: t('knowledge.entries.col.title') },
  { key: 'status', label: t('knowledge.entries.col.status'), width: '10rem' },
  { key: 'stale', label: t('knowledge.entries.col.stale'), width: '9rem' },
  { key: 'index', label: t('knowledge.entries.col.index'), width: '11rem' },
  { key: 'creator', label: t('knowledge.entries.col.creator'), width: '10rem' },
  { key: 'updated_at', label: t('knowledge.entries.col.updated'), width: '9rem' },
  { key: 'actions', label: '', width: '7rem', align: 'end' },
]);

// --- Row actions ------------------------------------------------------------
const historyOpen = ref(false);
const historyEntry = ref<KnowledgeEntryListItem | null>(null);

function openReader(entry: KnowledgeEntryListItem): void {
  void router.push({
    name: 'next.knowledge.base.reader',
    params: { baseId: baseId.value, slug: entry.slug },
  });
}

/** "New entry" is the COMPOSER now — entries are not written by hand (spec §25.4). */
function newEntry(): void {
  void router.push(composeLocation(baseId.value));
}

/** "Propose a change with AI" — the composer, opened against an existing entry. */
function proposeChange(entry: KnowledgeEntryListItem): void {
  void router.push(amendLocation(baseId.value, entry.id));
}

/**
 * The history drawer wants a full entry (it reads `can_be_edited` + `current_revision_id`, which
 * the list row also carries). The row is passed through as-is; the drawer only ever reads those.
 */
function openHistory(entry: KnowledgeEntryListItem): void {
  historyEntry.value = entry;
  historyOpen.value = true;
}


// --- Mode switch ------------------------------------------------------------
const mode = ref<'reader' | 'table'>('table');
const modeOptions = computed(() => [
  { value: 'reader' as const, label: t('knowledge.reader.mode.reader'), icon: 'book-open' as const },
  { value: 'table' as const, label: t('knowledge.reader.mode.table'), icon: 'table' as const },
]);
watch(mode, (value) => {
  if (value === 'reader') {
    void router.push({ name: 'next.knowledge.base.reader', params: { baseId: baseId.value } });
  }
});

onMounted(() => {
  void savedViews.load();
  refetch();
});
onUnmounted(() => store.resetEntries());
</script>

<template>
  <div class="flex flex-col gap-next-6">
    <PageHeader icon="table" :title="t('knowledge.entries.title')" :description="t('knowledge.entries.subtitle')">
      <template #actions>
        <SegmentedControl
          v-model="mode"
          :options="modeOptions"
          size="sm"
          :aria-label="t('knowledge.reader.mode.label')"
        />
        <Button leading-icon="plus" @click="newEntry">{{ t('knowledge.entries.new') }}</Button>
      </template>
    </PageHeader>

    <FilterBar
      v-model:search="search"
      :search-placeholder="t('knowledge.entries.filters.search')"
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
      <div class="min-w-0 flex-1 basis-40">
        <Switch
          v-model="staleOnly"
          size="sm"
          label-position="leading"
          :label="t('knowledge.filters.stale')"
        />
      </div>
    </FilterBar>

    <Table
      :columns="columns"
      :rows="store.entries"
      row-key="id"
      :loading="store.entriesLoading && store.entries.length === 0"
      :loading-rows="8"
      :error="store.entriesErrored && store.entries.length === 0"
      responsive="stack"
      sticky-header
      :caption="t('knowledge.entries.caption')"
    >
      <template #cell-title="{ row }">
        <div class="flex min-w-0 flex-col">
          <!-- `plain`: a table cell's title inherits the row's colour (the row carries its own hover
               surface and its stale/status markers); a primary-coloured link would fight them. -->
          <Link
            :href="`#${row.slug}`"
            variant="plain"
            class="truncate font-next-medium"
            @click.prevent="openReader(row)"
          >
            {{ row.title }}
          </Link>
          <Text variant="caption" tone="muted" class="truncate font-next-mono">{{ row.slug }}</Text>
        </div>
      </template>

      <template #cell-status="{ row }">
        <StatusBadge v-if="row.status" :status="row.status" :status-map="statusMap" size="sm" />
      </template>

      <!-- Never an empty cell: "fresh" is information too. -->
      <template #cell-stale="{ row }">
        <Badge v-if="row.is_stale" variant="warning" tone="subtle" icon="alert-triangle" size="sm">
          {{ t('knowledge.reader.stale') }}
        </Badge>
        <Text v-else variant="caption" tone="muted">{{ t('knowledge.entries.fresh') }}</Text>
      </template>

      <template #cell-index="{ row }">
        <StatusBadge
          v-if="row.index?.status"
          :status="row.index.status"
          :status-map="indexMap"
          :label="entryIndexLabel(row.index.status, t, {
            indexed: row.index.indexed_chunks_count,
            total: row.index.chunks_count,
          })"
          size="sm"
        />
      </template>

      <template #cell-creator="{ row }">
        <CreatorBadge :creator="row.creator ?? null" size="xs" />
      </template>

      <template #cell-updated_at="{ row }">
        <time v-if="row.updated_at" :datetime="row.updated_at" :title="row.updated_at">
          {{ formatDate(row.updated_at) }}
        </time>
        <Text v-else variant="caption" tone="muted">—</Text>
      </template>

      <!-- Revealed actions stay in the DOM and are hidden with opacity only — `v-if` would drop
           them out of the tab order and out of a screen reader's reach. Below next-md they are
           always visible, because touch has no hover. -->
      <template #row-actions="{ row }">
        <div
          class="flex items-center justify-end gap-next-1 opacity-100 transition-opacity next-md:opacity-0 group-hover:opacity-100 focus-within:opacity-100"
        >
          <Button
            variant="ghost"
            size="icon-xs"
            leading-icon="eye"
            :aria-label="t('knowledge.entries.actions.openAria', '', { title: row.title })"
            @click="openReader(row)"
          />
          <DropdownMenu placement="bottom-end" :aria-label="t('knowledge.entries.actions.more', '', { title: row.title })">
            <template #trigger="{ props: triggerProps }">
              <Button
                v-bind="triggerProps"
                variant="ghost"
                size="icon-xs"
                leading-icon="more-vertical"
                :aria-label="t('knowledge.entries.actions.more', '', { title: row.title })"
              />
            </template>
            <DropdownMenuItem icon="clock" :label="t('knowledge.entries.actions.history')" @select="openHistory(row)">
              {{ t('knowledge.entries.actions.history') }}
            </DropdownMenuItem>
            <!-- Replaces what §25.1 removed: "Duplicate" was the last way to create an entry
                 without the composer. This one proposes a CHANGE to the entry instead. -->
            <DropdownMenuItem
              icon="sparkles"
              :label="t('knowledge.entries.actions.propose')"
              @select="proposeChange(row)"
            >
              {{ t('knowledge.entries.actions.propose') }}
            </DropdownMenuItem>
          </DropdownMenu>
        </div>
      </template>

      <template #empty>
        <EmptyState
          size="sm"
          :variant="hasActiveFilters ? 'search' : 'default'"
          :icon="hasActiveFilters ? 'search' : 'file-text'"
          :title="hasActiveFilters ? t('knowledge.entries.emptySearch.title') : t('knowledge.entries.empty.title')"
        >
          <template #action>
            <Button v-if="hasActiveFilters" variant="outline" size="sm" leading-icon="x" @click="clearAll">
              {{ t('knowledge.filters.clearAll') }}
            </Button>
            <Button v-else size="sm" leading-icon="plus" @click="newEntry">
              {{ t('knowledge.entries.new') }}
            </Button>
          </template>
        </EmptyState>
      </template>

      <template #error>
        <EmptyState variant="error" size="sm" :title="t('knowledge.common.loadError')">
          <template #action>
            <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="refetch">
              {{ t('knowledge.common.retry') }}
            </Button>
          </template>
        </EmptyState>
      </template>

      <template #footer>
        <Alert v-if="store.entriesLoadMoreErrored" variant="danger" size="sm">
          <div class="flex items-center justify-between gap-next-2">
            <span>{{ t('knowledge.common.loadError') }}</span>
            <Button
              variant="ghost"
              size="xs"
              leading-icon="rotate-ccw"
              @click="store.retryLoadMoreEntries(baseId, filters)"
            >
              {{ t('knowledge.common.retry') }}
            </Button>
          </div>
        </Alert>

        <div
          v-if="store.entriesHasMore && !store.entriesErrored && !store.entriesLoadMoreErrored"
          ref="sentinelRef"
          aria-hidden="true"
          class="h-px w-full"
        />
      </template>
    </Table>

    <KnowledgeVersionsDrawer v-model:open="historyOpen" :entry="(historyEntry as never)" />

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
