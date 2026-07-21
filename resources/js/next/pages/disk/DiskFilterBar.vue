<script setup lang="ts">
// DiskFilterBar — the disk browser's filter bar (folder view only): a file-type multi-select, the
// dual-mode DiskSearchInput (where × name/description), a sort control, the removable chip row, and
// the MANDATORY #top Saved Views tab bar (context 'disk'). The single source of truth is the disk
// store's `filters`; every control writes through `store.applyFilters`, which refetches the level.
import { computed, onMounted, ref } from 'vue';
import FilterBar, { type ActiveFilter } from '../../ui/patterns/FilterBar.vue';
import FilterTabBar from '../../ui/patterns/FilterTabBar.vue';
import SaveViewModal, { type SaveViewSubmit } from '../../ui/patterns/SaveViewModal.vue';
import ConfirmDialog from '../../ui/overlay/ConfirmDialog.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import DiskSearchInput from './DiskSearchInput.vue';
import { fileTypeIcon } from './fileIcon';
import { useDiskStore } from '../../app/stores/disk';
import { useToast } from '../../app/composables/useToast';
import { useFilterTabs, type FilterSnapshot } from '../../app/composables/useFilterTabs';
import { useFilterTabsStore } from '../../app/stores/filterTabs';
import type { FilterTab } from '../../app/stores/filterTabs';
import { toIconEnumValue } from '../../ui/forms/filterTabIcon';
import { useI18n } from '../../app/i18n';
import { DEFAULT_DISK_FILTERS, defaultDirFor, type DiskFilters } from './types';

const SAVED_VIEWS_CONTEXT = 'disk';
const FILE_TYPES = ['image', 'video', 'audio', 'text', 'document', 'spreadsheet', 'archive', 'another'] as const;

const { t } = useI18n();
const store = useDiskStore();
const toast = useToast();
const filterTabsStore = useFilterTabsStore();

// --- Filter state — the store IS the source of truth; write through applyFilters -----
function patch(partial: Partial<DiskFilters>): void {
  void store.applyFilters({ ...store.filters, ...partial });
}
const types = computed<string[]>({ get: () => store.filters.types, set: (v) => patch({ types: v }) });
const q = computed<string>({ get: () => store.filters.q, set: (v) => patch({ q: v }) });
const where = computed({ get: () => store.filters.where, set: (v) => patch({ where: v }) });
const searchIn = computed({ get: () => store.filters.searchIn, set: (v) => patch({ searchIn: v }) });
const sort = computed({ get: () => store.filters.sort, set: (v) => patch({ sort: v, dir: defaultDirFor(v) }) });

function toggleDir(): void {
  patch({ dir: store.filters.dir === 'asc' ? 'desc' : 'asc' });
}

// --- Options ---------------------------------------------------------------
const typeOptions = computed<SegmentOption[]>(() => [
  { value: 'folder', label: t('disk.filters.type.folder', 'Folders'), icon: 'folder' },
  ...FILE_TYPES.map((ft) => ({ value: ft, label: t(`disk.filters.type.${ft}`, ft), icon: fileTypeIcon(ft) })),
]);
const sortOptions = computed<SegmentOption[]>(() => [
  { value: 'name', label: t('disk.filters.sort.name', 'Name'), icon: 'type' },
  { value: 'created_at', label: t('disk.filters.sort.created_at', 'Date added'), icon: 'calendar' },
]);

function typeLabel(v: string): string {
  return v === 'folder' ? t('disk.filters.type.folder', 'Folders') : t(`disk.filters.type.${v}`, v);
}
const sortDirty = computed(() => store.filters.sort !== DEFAULT_DISK_FILTERS.sort || store.filters.dir !== defaultDirFor(store.filters.sort));

// --- Chips -----------------------------------------------------------------
const activeFilters = computed<ActiveFilter[]>(() => {
  const chips: ActiveFilter[] = [];
  const f = store.filters;
  if (f.types.length) {
    chips.push({ key: 'types', values: f.types.map((v) => ({ key: `types:${v}`, label: typeLabel(v) })) });
  }
  if (f.where !== 'folder') chips.push({ key: `where=${f.where}`, label: t(`disk.filters.where.${f.where}`) });
  if (f.searchIn !== 'name') chips.push({ key: `search_in=${f.searchIn}`, label: t('disk.filters.chip.searchIn', 'In name & description') });
  if (sortDirty.value) {
    chips.push({ key: `sort=${f.sort}:${f.dir}`, label: t('disk.filters.chip.sort', 'Sorted: {label}', { label: `${t(`disk.filters.sort.${f.sort}`)} ${t(`disk.filters.dir.${f.dir}`)}` }) });
  }
  return chips;
});
const hasActiveFilters = computed(() => activeFilters.value.length > 0 || !!store.filters.q);

const decoratedFilters = computed<ActiveFilter[]>(() => savedViews.decorateActiveFilters(activeFilters.value));

function removeFilter(key: string): void {
  if (key.startsWith('types:')) { patch({ types: store.filters.types.filter((v) => v !== key.slice('types:'.length)) }); return; }
  if (key.startsWith('where=')) { patch({ where: 'folder' }); return; }
  if (key.startsWith('search_in=')) { patch({ searchIn: 'name' }); return; }
  if (key.startsWith('sort=')) { patch({ sort: DEFAULT_DISK_FILTERS.sort, dir: defaultDirFor(DEFAULT_DISK_FILTERS.sort) }); return; }
}
function clearAll(): void {
  void store.resetFilters();
}

// --- Saved views (context 'disk') -----------------------------------------
function snapArr(v: unknown): string[] {
  return Array.isArray(v) ? v.map(String) : v != null && v !== '' ? [String(v)] : [];
}
function serialize(): FilterSnapshot {
  const f = store.filters;
  const snap: FilterSnapshot = {};
  if (f.types.length) snap.types = [...f.types];
  if (f.q.trim()) snap.q = f.q.trim();
  if (f.searchIn !== 'name') snap.search_in = f.searchIn;
  if (f.where !== 'folder') snap.search_where = f.where;
  if (f.sort !== 'name') snap.sort = f.sort;
  if (f.dir !== defaultDirFor(f.sort)) snap.dir = f.dir;
  return snap;
}
function fromSnapshot(snap: FilterSnapshot): DiskFilters {
  const sort = (snap.sort === 'created_at' ? 'created_at' : 'name') as DiskFilters['sort'];
  return {
    types: snapArr(snap.types),
    q: typeof snap.q === 'string' ? snap.q : '',
    searchIn: snap.search_in === 'name_description' ? 'name_description' : 'name',
    where: (['subtree', 'everywhere'].includes(String(snap.search_where)) ? snap.search_where : 'folder') as DiskFilters['where'],
    sort,
    dir: (snap.dir === 'asc' || snap.dir === 'desc' ? snap.dir : defaultDirFor(sort)) as DiskFilters['dir'],
  };
}
function normalize(snap: FilterSnapshot): string[] {
  const keys: string[] = [];
  snapArr(snap.types).sort().forEach((v) => keys.push(`types:${v}`));
  if (snap.q) keys.push(`q=${String(snap.q)}`);
  if (snap.search_where && snap.search_where !== 'folder') keys.push(`where=${String(snap.search_where)}`);
  if (snap.search_in && snap.search_in !== 'name') keys.push(`search_in=${String(snap.search_in)}`);
  if (snap.sort && snap.sort !== 'name') keys.push(`sort=${String(snap.sort)}`);
  if (snap.dir) keys.push(`dir=${String(snap.dir)}`);
  return keys;
}
function restoreValue(key: string, snap: FilterSnapshot): void {
  const target = fromSnapshot(snap);
  if (key.startsWith('types:')) { const v = key.slice('types:'.length); if (!store.filters.types.includes(v)) patch({ types: [...store.filters.types, v] }); return; }
  if (key.startsWith('where=')) { patch({ where: target.where }); return; }
  if (key.startsWith('search_in=')) { patch({ searchIn: target.searchIn }); return; }
  if (key.startsWith('sort=') || key.startsWith('dir=')) { patch({ sort: target.sort, dir: target.dir }); return; }
  if (key.startsWith('q=')) { patch({ q: target.q }); }
}

const savedViews = useFilterTabs(SAVED_VIEWS_CONTEXT, {
  serialize,
  apply: (snap) => void store.applyFilters(fromSnapshot(snap)),
  normalize,
  restoreValue,
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
      await filterTabsStore.update(SAVED_VIEWS_CONTEXT, editingView.value.id, { name: payload.name, icon: iconEnum, filters: serialize() });
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
  } catch {
    toast.danger(t('tasks.savedViews.errors.invalidReorder'));
    void savedViews.load();
  }
}

onMounted(() => void savedViews.load());
</script>

<template>
  <FilterBar
    :searchable="false"
    :aria-label="t('disk.filters.ariaLabel', 'Disk filters')"
    :active-filters="decoratedFilters"
    :clear-all-label="t('disk.filters.clearAll', 'Clear filters')"
    @remove-filter="removeFilter"
    @clear-all="clearAll"
    @restore-filter="savedViews.restoreFilter"
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

    <!-- Two rows: (1) search stretches full width + a fixed sort control on the right;
         (2) the file-type picker as a multi-select SegmentedControl with a "Select all". -->
    <div class="flex w-full flex-col gap-next-3">
      <div class="flex flex-wrap items-center gap-next-3">
        <div class="min-w-[12rem] flex-1">
          <DiskSearchInput v-model="q" v-model:where="where" v-model:search-in="searchIn" class="w-full" />
        </div>
        <div class="flex shrink-0 items-center gap-next-1">
          <SegmentedControl v-model="sort" :options="sortOptions" size="sm" :aria-label="t('disk.filters.sortLabel', 'Sort by')" />
          <Button
            variant="outline"
            size="icon-sm"
            :aria-label="t('disk.filters.toggleDir', 'Toggle sort direction')"
            @click="toggleDir"
          >
            <Icon :name="store.filters.dir === 'asc' ? 'arrow-up' : 'arrow-down'" aria-hidden="true" />
          </Button>
        </div>
      </div>

      <SegmentedControl
        v-model="types"
        multiple
        select-all
        size="sm"
        :options="typeOptions"
        :aria-label="t('disk.filters.typePlaceholder', 'Type')"
      />
    </div>
  </FilterBar>

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
    :title="t('tasks.savedViews.confirm.deleteTitle', 'Delete view?')"
    :message="deleteMessage"
    :confirm-label="t('common.delete', 'Delete')"
    @confirm="onConfirmDeleteView"
  />
</template>
