<script setup lang="ts">
// Gallery: FilterBar — the standard list-screen filter row (Patterns tier).
//
// A LIVE working demo: a debounced search + two Selects + a DateRangePicker
// feeding a derived active-filters chip row (removable + clear-all), wired to a
// Table + Pagination + EmptyState screen mock so removing/clearing filters
// visibly changes the results. Also: a sticky variant, the chip overflow, light
// + dark, and the API + a11y.
import { computed, ref } from 'vue';
import FilterBar, { type ActiveFilter } from '../../ui/patterns/FilterBar.vue';
import Select from '../../ui/forms/Select.vue';
import DateRangePicker from '../../ui/forms/DateRangePicker.vue';
import Table, { type TableColumn } from '../../ui/data/Table.vue';
import Pagination from '../../ui/navigation/Pagination.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';
import Button from '../../ui/primitives/Button.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

// --- Live demo data ---------------------------------------------------------
interface Form extends Record<string, unknown> {
  id: number;
  name: string;
  owner: string;
  status: 'active' | 'draft' | 'archived' | 'pending';
}
const allForms: Form[] = [
  { id: 1, name: 'Summer campaign signup', owner: 'Anna Kowalska', status: 'active' },
  { id: 2, name: 'NPS survey Q2', owner: 'Piotr Nowak', status: 'active' },
  { id: 3, name: 'Beta waitlist', owner: 'Maria Wiśniewska', status: 'draft' },
  { id: 4, name: 'Event RSVP', owner: 'Jan Lewandowski', status: 'pending' },
  { id: 5, name: 'Old contact form', owner: 'Anna Kowalska', status: 'archived' },
  { id: 6, name: 'Product feedback', owner: 'Piotr Nowak', status: 'draft' },
];

const columns: TableColumn<Form>[] = [
  { key: 'name', label: 'Name' },
  { key: 'owner', label: 'Owner' },
  { key: 'status', label: 'Status', align: 'center' },
];

const search = ref('');
const statusFilter = ref<string | null>(null);
const ownerFilter = ref<string | null>(null);
const range = ref<{ start: string | null; end: string | null }>({ start: null, end: null });

const statusOptions = [
  { value: 'active', label: 'Active' },
  { value: 'draft', label: 'Draft' },
  { value: 'pending', label: 'Pending' },
  { value: 'archived', label: 'Archived' },
];
const ownerOptions = [
  { value: 'Anna Kowalska', label: 'Anna Kowalska' },
  { value: 'Piotr Nowak', label: 'Piotr Nowak' },
  { value: 'Maria Wiśniewska', label: 'Maria Wiśniewska' },
  { value: 'Jan Lewandowski', label: 'Jan Lewandowski' },
];

// Derive the active-filter chips from the live control state.
const activeFilters = computed<ActiveFilter[]>(() => {
  const out: ActiveFilter[] = [];
  if (search.value.trim()) out.push({ key: 'search', label: `Search: ${search.value.trim()}` });
  if (statusFilter.value) {
    const label = statusOptions.find((o) => o.value === statusFilter.value)?.label ?? statusFilter.value;
    out.push({ key: 'status', label: `Status: ${label}` });
  }
  if (ownerFilter.value) out.push({ key: 'owner', label: `Owner: ${ownerFilter.value}` });
  if (range.value.start || range.value.end) {
    out.push({ key: 'range', label: `Created: ${range.value.start ?? '…'} – ${range.value.end ?? '…'}` });
  }
  return out;
});

const filteredForms = computed(() => {
  const q = search.value.trim().toLowerCase();
  return allForms.filter((f) => {
    if (q && !f.name.toLowerCase().includes(q) && !f.owner.toLowerCase().includes(q)) return false;
    if (statusFilter.value && f.status !== statusFilter.value) return false;
    if (ownerFilter.value && f.owner !== ownerFilter.value) return false;
    return true;
  });
});

const page = ref(1);
const pageSize = 4;
const pageCount = computed(() => Math.max(1, Math.ceil(filteredForms.value.length / pageSize)));
const pagedForms = computed(() => {
  const start = (Math.min(page.value, pageCount.value) - 1) * pageSize;
  return filteredForms.value.slice(start, start + pageSize);
});

function removeFilter(key: string): void {
  if (key === 'search') search.value = '';
  if (key === 'status') statusFilter.value = null;
  if (key === 'owner') ownerFilter.value = null;
  if (key === 'range') range.value = { start: null, end: null };
  page.value = 1;
}
function clearAll(): void {
  search.value = '';
  statusFilter.value = null;
  ownerFilter.value = null;
  range.value = { start: null, end: null };
  page.value = 1;
}

// --- Chip overflow demo (many filters on one line) --------------------------
const manyFilters = ref<ActiveFilter[]>([
  { key: 'a', label: 'Status: Active' },
  { key: 'b', label: 'Owner: Anna Kowalska' },
  { key: 'c', label: 'Created: last 30 days' },
  { key: 'd', label: 'Tag: marketing' },
  { key: 'e', label: 'Tag: high-priority' },
  { key: 'f', label: 'Language: Polish' },
  { key: 'g', label: 'Search: campaign' },
]);
function removeMany(key: string): void {
  manyFilters.value = manyFilters.value.filter((f) => f.key !== key);
}
function clearMany(): void {
  manyFilters.value = [];
}

// --- Multi-value group + operator note demo ---------------------------------
// A single filter "group" (e.g. labels) renders ONE chip per value, plus an
// operator note when ≥2 values are selected, so the user sees exactly what is
// selected AND how the values combine — never a bare "3 selected".
const groupFilters = ref<ActiveFilter[]>([
  { key: 'priority', label: 'Priority: High' },
  {
    key: 'labels',
    values: [
      { key: 'labels:1', label: 'marketing' },
      { key: 'labels:2', label: 'high-priority' },
      { key: 'labels:3', label: 'q2' },
    ],
    operatorLabel: 'Labels: Any',
  },
]);
function removeGroup(key: string): void {
  groupFilters.value = groupFilters.value
    .map((f) =>
      f.values
        ? { ...f, values: f.values.filter((v) => v.key !== key) }
        : f,
    )
    .filter((f) => f.key !== key && (f.values ? f.values.length > 0 : true));
}
function clearGroups(): void {
  groupFilters.value = [];
}

const propRows: ApiRow[] = [
  { name: 'v-model:search', type: 'string', default: "''", description: 'The DEBOUNCED committed search value.' },
  { name: 'searchable', type: 'boolean', default: 'true', description: 'Show the debounced search input + make the bar role="search".' },
  { name: 'searchPlaceholder', type: 'string', default: "'Search…'", description: 'Search input placeholder.' },
  { name: 'searchDebounce', type: 'number', default: '300', description: 'Debounce (ms) before committing the search.' },
  { name: 'searchLabel', type: 'string', default: "'Search and filter'", description: 'Accessible label for the search region.' },
  { name: 'ariaLabel', type: 'string', default: "'Filters'", description: 'Bar label when there is no search.' },
  { name: 'activeFilters', type: 'ActiveFilter[]', default: '[]', description: 'Chips. Single: { key, label }. Multi-value group: { key, values: { key, label }[], operatorLabel? } → one removable chip per value + an Any/All note when ≥2 values. Removable; >1 chip shows Clear all.' },
  { name: 'clearAllLabel', type: 'string', default: "'Clear all'", description: 'Clear-all button label.' },
  { name: 'noFiltersLabel', type: 'string', default: '—', description: 'Text shown when no filters are active (omit to render nothing).' },
  { name: 'sticky', type: 'boolean', default: 'false', description: 'Stick the bar to the top of its scroll container.' },
  { name: 'controlSize', type: "'sm' | 'md' | 'lg'", default: "'md'", description: 'Ambient size provided to ALL slotted controls + the search input (each inherits it unless it sets its own size). Makes "filter controls are md" a structural rule.' },
];
const eventRows: ApiRow[] = [
  { name: 'remove-filter', type: '(key: string)', description: 'A chip ✕ (visible or via the +N panel) was clicked.' },
  { name: 'clear-all', type: '()', description: 'The "Clear all" button was clicked.' },
];
const slotRows: ApiRow[] = [
  { name: 'default', type: 'slot', description: 'Filter controls (Selects, DateRangeFilter, switches…). They inherit the bar’s controlSize.' },
  { name: 'results', type: 'slot', description: 'Results-count text (right-aligned).' },
  { name: 'actions', type: 'slot', description: 'Trailing actions (e.g. a New button).' },
];
</script>

<template>
  <StoryPage
    title="FilterBar"
    description="The standard list-screen filter row: a debounced search, arbitrary filter controls, a removable active-filters chip row (wraps to show all; clear-all + operator notes pinned right), a results count, and a trailing actions slot. role=search; chips are keyboard-removable."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>With search, the bar is a <code>role="search"</code> region labelled by <code>searchLabel</code>; otherwise a labelled <code>group</code>.</li>
        <li>Each chip's ✕ is keyboard-removable (it's a real Badge remove button); "Clear all" is a real Button.</li>
        <li>All active filters are shown — the chip row simply WRAPS onto more lines (no <code>+N</code> collapse). The Clear-all button + any operator notes stay pinned to the right of the first line.</li>
        <li>Search is debounced: the input updates instantly but only commits to <code>v-model:search</code> after <code>searchDebounce</code> ms; clearing commits immediately.</li>
      </ul>
    </template>

    <StorySection title="Live demo — search + 2 filters + date range + chips" description="Type to search (debounced), pick a status / owner / date range, then remove chips or clear all. The Table + Pagination + EmptyState below react live.">
      <div class="flex flex-col gap-next-4">
        <FilterBar
          v-model:search="search"
          search-placeholder="Search forms…"
          :active-filters="activeFilters"
          no-filters-label="No active filters"
          @remove-filter="removeFilter"
          @clear-all="clearAll"
        >
          <div class="min-w-0 flex-1 basis-36">
            <Select
              v-model="statusFilter"
              :options="statusOptions"
              leading-icon="check-circle"
              placeholder="Status"
              aria-label="Filter by status"
            />
          </div>
          <div class="min-w-0 flex-1 basis-40">
            <Select
              v-model="ownerFilter"
              :options="ownerOptions"
              leading-icon="user"
              placeholder="Owner"
              aria-label="Filter by owner"
            />
          </div>
          <div class="min-w-0 flex-1 basis-44">
            <DateRangePicker v-model="range" aria-label="Created date range" />
          </div>

          <template #results>
            {{ filteredForms.length }} of {{ allForms.length }} forms
          </template>
          <template #actions>
            <Button size="sm" leading-icon="plus">New form</Button>
          </template>
        </FilterBar>

        <Table :columns="columns" :rows="pagedForms" row-key="id" caption="Filtered forms">
          <template #cell-status="{ value }">
            <StatusBadge :status="String(value)" size="sm" />
          </template>
          <template #empty>
            <EmptyState
              variant="search"
              size="sm"
              title="No forms match your filters"
              description="Try a different search term or clear some filters."
            >
              <template #action>
                <Button size="sm" variant="outline" @click="clearAll">Clear all filters</Button>
              </template>
            </EmptyState>
          </template>
          <template #footer>
            <div v-if="filteredForms.length" class="flex justify-end p-next-3">
              <Pagination v-model="page" :page-count="pageCount" size="sm" />
            </div>
          </template>
        </Table>
      </div>
    </StorySection>

    <StorySection title="Wrapping + clear-all" description="Every active filter is shown; when they don't fit one line the row wraps. Remove individual chips; >1 chip shows Clear all (pinned right, first line).">
      <FilterBar
        :searchable="false"
        :active-filters="manyFilters"
        aria-label="Saved filters"
        @remove-filter="removeMany"
        @clear-all="clearMany"
      >
        <span class="text-next-sm text-next-muted-foreground">Narrow the window to see the chips wrap.</span>
      </FilterBar>
    </StorySection>

    <StorySection title="Multi-value groups + operator note" description="A filter group with multiple values renders one removable chip per value (showing the real label, not a count) plus an Any/All operator note once ≥2 values are selected.">
      <FilterBar
        :searchable="false"
        :active-filters="groupFilters"
        aria-label="Grouped filters"
        @remove-filter="removeGroup"
        @clear-all="clearGroups"
      />
    </StorySection>

    <StorySection title="Sticky" description="Set :sticky to pin the bar to the top of its scroll container while the list scrolls.">
      <div class="max-h-64 overflow-y-auto rounded-next-lg border border-next-border p-next-2">
        <FilterBar
          sticky
          search-placeholder="Search…"
          :active-filters="[{ key: 'x', label: 'Status: Active' }]"
          @remove-filter="() => {}"
        />
        <div class="space-y-next-2 p-next-2">
          <p v-for="n in 12" :key="n" class="text-next-sm text-next-muted-foreground">Scrollable list row {{ n }}</p>
        </div>
      </div>
    </StorySection>

    <StorySection title="Light + dark">
      <div class="grid gap-next-4 lg:grid-cols-2">
        <div class="next-root rounded-next-lg border border-next-border bg-next-bg p-next-4">
          <FilterBar
            search-placeholder="Search…"
            :active-filters="[{ key: 'a', label: 'Status: Active' }, { key: 'b', label: 'Owner: Anna' }]"
            @remove-filter="() => {}"
            @clear-all="() => {}"
          />
        </div>
        <div class="next-root dark rounded-next-lg border border-next-border bg-next-bg p-next-4">
          <FilterBar
            search-placeholder="Search…"
            :active-filters="[{ key: 'a', label: 'Status: Active' }, { key: 'b', label: 'Owner: Anna' }]"
            @remove-filter="() => {}"
            @clear-all="() => {}"
          />
        </div>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
        <ApiTable title="Slots" type-header="Kind" :rows="slotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
