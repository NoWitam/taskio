<script setup lang="ts">
// TasksView — the Tasks BROWSE page for the isolated "next" frontend (Batch 1:
// list / filters / board). Detail Drawer + create Modal land in Batch 2 (seams
// are marked below).
//
// Composition (all design-system components, all strings via t()):
//   • PageHeader with a "New task" primary action (SEAM → Batch 2 create Modal;
//     for now routes to `?new=1`).
//   • FilterBar: debounced search, priority Select, assignee UserSelect (global
//     people picker), labels LabelSelect (global label picker with the AND/OR
//     operator INSIDE its dropdown via v-model:operator), a DateRangePicker, a
//     date-preset Select, and a "hide without deadline" Switch. Active-filter
//     chips + clear-all.
//   • A board-only view with Active / Archive / Trash Tabs (legacy parity). The
//     active tab drives which statuses are fetched + shown: Active = the four
//     primary columns (to_do, in_progress, in_test, done); Archive = the archive
//     bucket (with an auto-archive info Alert); Trash = the trash bucket. Each
//     column has independent infinite scroll + skeleton + EmptyState.
//
// Filter state lives HERE and is pushed to the store (reset on change, debounced
// for search) + mirrored into the router query (URL sync).
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import FilterBar, { type ActiveFilter } from '../../ui/patterns/FilterBar.vue';
import Tabs, { type TabItem } from '../../ui/navigation/Tabs.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import UserSelect from '../../ui/forms/UserSelect.vue';
import LabelSelect from '../../ui/forms/LabelSelect.vue';
import DateRangePicker, { type DateRangeValue } from '../../ui/forms/DateRangePicker.vue';
import Switch from '../../ui/forms/Switch.vue';
import Button from '../../ui/primitives/Button.vue';
import TaskBoardColumn from './TaskBoardColumn.vue';
import TaskDetailsDrawer from './TaskDetailsDrawer.vue';
import TaskFormModal from './TaskFormModal.vue';
import { useTasksStore } from '../../app/stores/tasks';
import { useDebounce } from '../../app/composables/useDebounce';
import { useI18n } from '../../app/i18n';
import {
  ALL_PRIORITIES,
  BOARD_STATUSES,
  statusMeta,
  type TaskFilters,
  type TaskListItem,
  type TaskPriority,
  type TaskStatus,
} from './types';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const store = useTasksStore();

// The board exposes three tabs (legacy parity): the Active board (4 columns),
// the Archive bucket (1 column), and the Trash bucket (1 column). The active tab
// drives which statuses are fetched + shown.
type BoardTab = 'active' | 'archive' | 'trash';
const TAB_STATUSES: Record<BoardTab, TaskStatus[]> = {
  active: BOARD_STATUSES,
  archive: ['archive'],
  trash: ['trash'],
};
const ALL_TABS: BoardTab[] = ['active', 'archive', 'trash'];

// --- Filter + tab state (owned here) -------------------------------------
const search = ref('');
const priority = ref<TaskPriority | null>(null);
const assignees = ref<string[]>([]);
const labels = ref<string[]>([]);
const labelOperator = ref<'AND' | 'OR'>('OR');
const dateRange = ref<DateRangeValue>({ start: null, end: null });
const datePreset = ref<string | null>(null);
const hideWithoutDeadline = ref(false);

const tab = ref<BoardTab>('active');

// --- Options --------------------------------------------------------------
const priorityOptions = computed<SelectOption[]>(() =>
  ALL_PRIORITIES.map((p) => ({ value: p, label: t(`tasks.priorities.${p}`) })),
);
const datePresetOptions = computed<SelectOption[]>(() =>
  (['today', 'this_week', 'last_week', 'this_month'] as const).map((p) => ({
    value: p,
    label: t(`tasks.datePresets.${p}`),
  })),
);
// Tabs: Active / Archive / Trash, each with its status icon + i18n label.
const tabItems = computed<TabItem<BoardTab>[]>(() => [
  { value: 'active', label: t('tasks.tabs.active'), icon: 'layout-dashboard' },
  { value: 'archive', label: t('tasks.tabs.archive'), icon: statusMeta('archive').icon },
  { value: 'trash', label: t('tasks.tabs.trash'), icon: statusMeta('trash').icon },
]);

// Columns rendered for the active tab.
const tabStatuses = computed<TaskStatus[]>(() => TAB_STATUSES[tab.value]);

// --- Aggregated filter object (mirrors the query params 1:1) ---------------
const filters = computed<TaskFilters>(() => ({
  search: search.value || undefined,
  priority: priority.value ?? undefined,
  user_id: assignees.value.length ? assignees.value : undefined,
  labels: labels.value.length ? labels.value : undefined,
  // labelOperator only matters when labels are selected.
  labelOperator: labels.value.length ? labelOperator.value : undefined,
  date_from: dateRange.value.start ?? undefined,
  date_to: dateRange.value.end ?? undefined,
  date_preset: (datePreset.value as TaskFilters['date_preset']) ?? undefined,
  hide_without_deadline: hideWithoutDeadline.value || undefined,
}));

const hasActiveFilters = computed(
  () =>
    !!search.value ||
    !!priority.value ||
    assignees.value.length > 0 ||
    labels.value.length > 0 ||
    !!dateRange.value.start ||
    !!dateRange.value.end ||
    !!datePreset.value ||
    hideWithoutDeadline.value,
);

// --- Active-filter chips for the FilterBar ---------------------------------
const activeFilters = computed<ActiveFilter[]>(() => {
  const chips: ActiveFilter[] = [];
  if (search.value) {
    chips.push({ key: 'search', label: t('tasks.filters.chip.search', '', { value: search.value }) });
  }
  if (priority.value) {
    chips.push({
      key: 'priority',
      label: t('tasks.filters.chip.priority', '', { value: t(`tasks.priorities.${priority.value}`) }),
    });
  }
  if (assignees.value.length) {
    chips.push({ key: 'user_id', label: t('tasks.filters.chip.assignee', '', { count: assignees.value.length }) });
  }
  if (labels.value.length) {
    chips.push({ key: 'labels', label: t('tasks.filters.chip.labels', '', { count: labels.value.length }) });
  }
  if (dateRange.value.start || dateRange.value.end) {
    chips.push({
      key: 'dateRange',
      label: t('tasks.filters.chip.dateRange', '', {
        from: dateRange.value.start ?? '…',
        to: dateRange.value.end ?? '…',
      }),
    });
  }
  if (datePreset.value) {
    chips.push({
      key: 'datePreset',
      label: t('tasks.filters.chip.datePreset', '', { value: t(`tasks.datePresets.${datePreset.value}`) }),
    });
  }
  if (hideWithoutDeadline.value) {
    chips.push({ key: 'hideWithoutDeadline', label: t('tasks.filters.chip.hideWithoutDeadline') });
  }
  return chips;
});

function removeFilter(key: string): void {
  switch (key) {
    case 'search':
      search.value = '';
      break;
    case 'priority':
      priority.value = null;
      break;
    case 'user_id':
      assignees.value = [];
      break;
    case 'labels':
      labels.value = [];
      break;
    case 'dateRange':
      dateRange.value = { start: null, end: null };
      break;
    case 'datePreset':
      datePreset.value = null;
      break;
    case 'hideWithoutDeadline':
      hideWithoutDeadline.value = false;
      break;
  }
}

function clearAll(): void {
  search.value = '';
  priority.value = null;
  assignees.value = [];
  labels.value = [];
  labelOperator.value = 'OR';
  dateRange.value = { start: null, end: null };
  datePreset.value = null;
  hideWithoutDeadline.value = false;
}

// --- Fetch orchestration --------------------------------------------------
// Which statuses are currently visible (driven by the active tab).
const visibleStatuses = computed<TaskStatus[]>(() => tabStatuses.value);

function refetchVisible(): void {
  visibleStatuses.value.forEach((status) =>
    store.fetchByStatus(status, filters.value, { reset: true }),
  );
}

// Debounce only refetch-on-search; other filters refetch immediately.
const debouncedRefetch = useDebounce(refetchVisible, 350);

// Refetch when the (debounced) filter object changes.
watch(
  filters,
  () => debouncedRefetch(),
  { deep: true },
);

// Refetch immediately when the visible status set changes (view / list status).
watch(visibleStatuses, () => refetchVisible(), { deep: true });

// --- URL sync (filters + view + status) -----------------------------------
let hydrating = false;

function hydrateFromQuery(): void {
  hydrating = true;
  const q = route.query;
  const str = (v: unknown): string => (Array.isArray(v) ? String(v[0] ?? '') : String(v ?? ''));
  const arr = (v: unknown): string[] =>
    v == null ? [] : Array.isArray(v) ? v.map((x) => String(x)) : [String(v)];

  search.value = str(q.search);
  const p = str(q.priority);
  priority.value = (ALL_PRIORITIES as string[]).includes(p) ? (p as TaskPriority) : null;
  assignees.value = arr(q.user_id);
  labels.value = arr(q.labels);
  labelOperator.value = str(q.labelOperator) === 'AND' ? 'AND' : 'OR';
  dateRange.value = {
    start: str(q.date_from) || null,
    end: str(q.date_to) || null,
  };
  const preset = str(q.date_preset);
  datePreset.value = ['today', 'this_week', 'last_week', 'this_month'].includes(preset)
    ? preset
    : null;
  hideWithoutDeadline.value = str(q.hide_without_deadline) === '1';

  const tb = str(q.tab);
  tab.value = (ALL_TABS as string[]).includes(tb) ? (tb as BoardTab) : 'active';
  hydrating = false;
}

function syncQuery(): void {
  if (hydrating) return;
  const query: Record<string, string | string[]> = {};
  if (search.value) query.search = search.value;
  if (priority.value) query.priority = priority.value;
  if (assignees.value.length) query.user_id = assignees.value;
  if (labels.value.length) {
    query.labels = labels.value;
    query.labelOperator = labelOperator.value;
  }
  if (dateRange.value.start) query.date_from = dateRange.value.start;
  if (dateRange.value.end) query.date_to = dateRange.value.end;
  if (datePreset.value) query.date_preset = datePreset.value;
  if (hideWithoutDeadline.value) query.hide_without_deadline = '1';
  if (tab.value !== 'active') query.tab = tab.value;

  void router.replace({ query });
}

watch(
  [filters, tab],
  () => syncQuery(),
  { deep: true },
);

// --- Detail Drawer + create/edit Modal (driven by the route query) --------
// `?task=<id>`            → open the detail Drawer for that task.
// `?task=<id>&edit=1`     → also open the form Modal in EDIT mode.
// `?new=1`                → open the form Modal in CREATE mode.
const str = (v: unknown): string =>
  Array.isArray(v) ? String(v[0] ?? '') : String(v ?? '');

const drawerTaskId = computed<string | null>(() => str(route.query.task) || null);
const drawerOpen = computed<boolean>({
  get: () => drawerTaskId.value !== null,
  set: (value) => {
    if (!value) closeDrawer();
  },
});

const isEditing = computed(() => str(route.query.edit) === '1');
const isCreating = computed(() => str(route.query.new) === '1');

// The form Modal is open for create (?new=1) OR edit (?task=&edit=1).
const formOpen = computed<boolean>({
  get: () => isCreating.value || (drawerTaskId.value !== null && isEditing.value),
  set: (value) => {
    if (!value) closeForm();
  },
});
// In edit mode the store's loaded `detail` (fetched by the Drawer) prefills it.
const formTask = computed(() =>
  isEditing.value && store.detail && String(store.detail.id) === drawerTaskId.value
    ? store.detail
    : null,
);

function dropQueryKeys(keys: string[]): void {
  const query = { ...route.query };
  keys.forEach((k) => delete query[k]);
  void router.replace({ query });
}

function onSelect(task: TaskListItem): void {
  void router.push({ query: { ...route.query, task: String(task.id) } });
}

function onNewTask(): void {
  void router.push({ query: { ...route.query, new: '1' } });
}

function closeDrawer(): void {
  dropQueryKeys(['task', 'edit']);
}

function onDrawerEdit(id: string | number): void {
  void router.replace({ query: { ...route.query, task: String(id), edit: '1' } });
}

function closeForm(): void {
  // Closing CREATE drops `?new`; closing EDIT drops only `?edit` (keep the Drawer).
  if (isCreating.value) dropQueryKeys(['new']);
  else dropQueryKeys(['edit']);
}

// After a create, refresh the visible buckets so a brand-new task surfaces even
// in a bucket the store hadn't initialized yet (upsert only touches fetched
// buckets). Edits/status/delete are reconciled in-place by the store.
function onFormSaved(): void {
  if (isCreating.value) refetchVisible();
}

onMounted(() => {
  hydrateFromQuery();
  refetchVisible();
});
</script>

<template>
  <!-- Full-height page: PageHeader + FilterBar + the Tabs strip stay fixed at the
       top while the board fills the remaining viewport height and scrolls its own
       columns internally (the page itself never scrolls). `flex-1 min-h-0` makes
       this fill the height region the AppLayout gutter establishes; the gutter
       padding is the comfortable gap around the board. -->
  <div class="flex min-h-0 flex-1 flex-col gap-next-6">
    <PageHeader :title="t('tasks.title')" :description="t('tasks.subtitle')" icon="list-checks">
      <template #actions>
        <Button leading-icon="plus" @click="onNewTask">
          {{ t('tasks.newTask') }}
        </Button>
      </template>
    </PageHeader>

    <FilterBar
      v-model:search="search"
      :search-placeholder="t('tasks.filters.search')"
      :active-filters="activeFilters"
      :clear-all-label="t('tasks.filters.clearAll')"
      @remove-filter="removeFilter"
      @clear-all="clearAll"
    >
      <!-- Priority -->
      <div class="min-w-[10rem]">
        <Select
          v-model="priority"
          :options="priorityOptions"
          size="sm"
          :placeholder="t('tasks.filters.priority')"
          :aria-label="t('tasks.filters.priority')"
        />
      </div>

      <!-- Assignee (global UserSelect, multiple) -->
      <div class="min-w-[12rem]">
        <UserSelect
          v-model:values="assignees"
          multiple
          summary
          size="sm"
          :placeholder="t('tasks.filters.assignee')"
          :aria-label="t('tasks.filters.assignee')"
        />
      </div>

      <!-- Labels (global LabelSelect, multiple) + in-dropdown AND/OR operator -->
      <div class="min-w-[12rem]">
        <LabelSelect
          v-model="labels"
          v-model:operator="labelOperator"
          summary
          size="sm"
          :placeholder="t('tasks.filters.labels')"
          :aria-label="t('tasks.filters.labels')"
        />
      </div>

      <!-- Deadline range -->
      <div class="min-w-[14rem]">
        <DateRangePicker
          v-model="dateRange"
          size="sm"
          :aria-label="t('tasks.filters.dateRange')"
        />
      </div>

      <!-- Deadline preset -->
      <div class="min-w-[10rem]">
        <Select
          v-model="datePreset"
          :options="datePresetOptions"
          size="sm"
          :placeholder="t('tasks.filters.datePreset')"
          :aria-label="t('tasks.filters.datePreset')"
        />
      </div>

      <!-- Hide without deadline -->
      <Switch
        v-model="hideWithoutDeadline"
        size="sm"
        :label="t('tasks.filters.hideWithoutDeadline')"
      />
    </FilterBar>

    <!-- Board with Active / Archive / Trash tabs. The active tab drives which
         status columns are rendered + fetched; each column keeps its own
         infinite-scroll + skeleton + EmptyState behavior (TaskList per status). -->
    <Tabs
      v-model="tab"
      :items="tabItems"
      variant="pills"
      fill
      :aria-label="t('tasks.tabs.label')"
    >
      <!-- ACTIVE: the four primary status columns. The grid FILLS the panel height
           (`flex-1 min-h-0`); each column is full-height and scrolls internally so
           the page itself never scrolls. -->
      <template #panel-active>
        <div
          class="grid min-h-0 flex-1 grid-cols-1 gap-next-4 next-md:grid-cols-2 next-xl:grid-cols-4"
        >
          <TaskBoardColumn
            v-for="status in TAB_STATUSES.active"
            :key="status"
            :status="status"
            :filters="filters"
            :has-active-filters="hasActiveFilters"
            class="min-h-0"
            @select="onSelect"
          />
        </div>
      </template>

      <!-- ARCHIVE: a single column + the auto-archive informational note. The note
           is fixed; the column fills the rest of the panel height. -->
      <template #panel-archive>
        <div class="flex min-h-0 flex-1 flex-col gap-next-4">
          <Alert
            variant="info"
            size="sm"
            :title="t('tasks.autoArchive.title')"
          >
            {{ t('tasks.autoArchive.body') }}
          </Alert>
          <div class="grid min-h-0 flex-1 grid-cols-1 gap-next-4 next-md:max-w-md">
            <TaskBoardColumn
              v-for="status in TAB_STATUSES.archive"
              :key="status"
              :status="status"
              :filters="filters"
              :has-active-filters="hasActiveFilters"
              class="min-h-0"
              @select="onSelect"
            />
          </div>
        </div>
      </template>

      <!-- TRASH: a single column + an informational note (parallel to Archive).
           No backend auto-purge exists, so the note is a generic restore/permanent
           reminder rather than a retention period. -->
      <template #panel-trash>
        <div class="flex min-h-0 flex-1 flex-col gap-next-4">
          <Alert
            variant="info"
            size="sm"
            :title="t('tasks.trashInfo.title')"
          >
            {{ t('tasks.trashInfo.body') }}
          </Alert>
          <div class="grid min-h-0 flex-1 grid-cols-1 gap-next-4 next-md:max-w-md">
            <TaskBoardColumn
              v-for="status in TAB_STATUSES.trash"
              :key="status"
              :status="status"
              :filters="filters"
              :has-active-filters="hasActiveFilters"
              class="min-h-0"
              @select="onSelect"
            />
          </div>
        </div>
      </template>
    </Tabs>

    <!-- Detail Drawer (opened from `?task=<id>`). -->
    <TaskDetailsDrawer
      v-model:open="drawerOpen"
      :task-id="drawerTaskId"
      @edit="onDrawerEdit"
      @close="closeDrawer"
    />

    <!-- Create / edit Modal (opened from `?new=1` or `?task=&edit=1`). -->
    <TaskFormModal
      v-model:open="formOpen"
      :task="formTask"
      @saved="onFormSaved"
    />
  </div>
</template>
