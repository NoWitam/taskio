<script setup lang="ts">
// Gallery: Table — the typed data table (Data tier).
//
// Shows the typed column API, cell slots, sortable headers (v-model:sort),
// selection (v-model:selected, select-all indeterminate), sticky header, zebra,
// dense, row click, every STATE (loading-skeleton / empty / error / success),
// the responsive stacked-card strategy in a resizable box, a composed
// Table + Pagination + EmptyState example, light + dark, and the API + a11y.
import { computed, ref } from 'vue';
import Table, { type TableColumn, type SortState } from '../../ui/data/Table.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';
import Pagination from '../../ui/navigation/Pagination.vue';
import Button from '../../ui/primitives/Button.vue';
import DropdownMenu from '../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../ui/overlay/DropdownMenuItem.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

interface Member extends Record<string, unknown> {
  id: number;
  name: string;
  email: string;
  role: string;
  status: string;
  forms: number;
}

const members: Member[] = [
  { id: 1, name: 'Hanna Kowalska', email: 'hanna@taskio.io', role: 'Owner', status: 'active', forms: 24 },
  { id: 2, name: 'Marek Wójcik', email: 'marek@taskio.io', role: 'Admin', status: 'active', forms: 11 },
  { id: 3, name: 'Zofia Lewandowska', email: 'zofia@taskio.io', role: 'Editor', status: 'pending', forms: 3 },
  { id: 4, name: 'Piotr Zieliński', email: 'piotr@taskio.io', role: 'Viewer', status: 'inactive', forms: 0 },
  { id: 5, name: 'Anna Nowak', email: 'anna@taskio.io', role: 'Editor', status: 'archived', forms: 7 },
];

const columns: TableColumn<Member>[] = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'email', label: 'Email' },
  { key: 'role', label: 'Role', sortable: true },
  { key: 'status', label: 'Status', align: 'center' },
  { key: 'forms', label: 'Forms', align: 'end', sortable: true, width: '6rem' },
];

// --- Sort demo (external sorting) -------------------------------------------
const sort = ref<SortState | null>({ key: 'name', dir: 'asc' });
const sortedMembers = computed(() => {
  if (!sort.value) return members;
  const { key, dir } = sort.value;
  return [...members].sort((a, b) => {
    const av = a[key as keyof Member];
    const bv = b[key as keyof Member];
    const cmp = typeof av === 'number' && typeof bv === 'number'
      ? av - bv
      : String(av).localeCompare(String(bv));
    return dir === 'asc' ? cmp : -cmp;
  });
});

// --- Selection demo ---------------------------------------------------------
const selected = ref<Array<string | number>>([2]);

// --- Composed demo (pagination + empty) -------------------------------------
const composedPage = ref(1);
const filterEmpty = ref(false);
const composedRows = computed(() => (filterEmpty.value ? [] : sortedMembers.value));

const lastClicked = ref<string | null>(null);
function onRowClick(row: Member): void {
  lastClicked.value = row.name;
}

const propRows: ApiRow[] = [
  { name: 'columns', type: 'TableColumn<T>[]', default: '—', description: '{ key, label, align?, width?, sortable?, hidden? }.' },
  { name: 'rows', type: 'T[]', default: '—', description: 'The row data.' },
  { name: 'rowKey', type: 'keyof T | (row,i)=>id', default: 'index', description: 'Stable row identity for keys + selection.' },
  { name: 'loading', type: 'boolean', default: 'false', description: 'Render skeleton rows mimicking the columns (several).' },
  { name: 'error', type: 'boolean', default: 'false', description: 'Render the #error slot instead of the body.' },
  { name: 'loadingRows', type: 'number', default: '5', description: 'Number of skeleton rows while loading.' },
  { name: 'stickyHeader', type: 'boolean', default: 'false', description: 'Pin the header on vertical scroll.' },
  { name: 'zebra', type: 'boolean', default: 'false', description: 'Alternating row tint.' },
  { name: 'dense', type: 'boolean', default: 'false', description: 'Tighter row padding.' },
  { name: 'hoverable', type: 'boolean', default: 'true', description: 'Row hover highlight.' },
  { name: 'selectable', type: 'boolean', default: 'false', description: 'Add a checkbox column + select-all (indeterminate when partial).' },
  { name: 'clickableRows', type: 'boolean', default: 'false', description: 'Whole-row accessible action (emits row-click).' },
  { name: 'responsive', type: "'scroll' | 'stack'", default: "'scroll'", description: 'stack: card list below next-md (a real transform, not a shrunken table).' },
  { name: 'caption', type: 'string', default: '—', description: 'Visually-hidden table caption.' },
  { name: 'v-model:sort', type: 'SortState | null', default: 'null', description: '{ key, dir } — sorting is performed externally.' },
  { name: 'v-model:selected', type: '(string|number)[]', default: '[]', description: 'Selected row keys.' },
];
const slotRows: ApiRow[] = [
  { name: 'cell-<key>', type: '{ row, value }', description: 'Custom cell renderer per column.' },
  { name: 'row-actions', type: '{ row, index }', description: 'Trailing actions column.' },
  { name: 'empty', type: '—', description: 'Empty body (drop an EmptyState).' },
  { name: 'error', type: '—', description: 'Error body (drop an EmptyState variant="error" + retry).' },
  { name: 'footer', type: '—', description: 'Below the table (e.g. Pagination).' },
];
const eventRows: ApiRow[] = [
  { name: 'row-click', type: '(row, index)', description: 'Emitted when clickableRows is on and a row is activated.' },
];
</script>

<template>
  <StoryPage
    title="Table"
    description="A typed data table: column API + scoped cell slots, external sortable headers, row selection with select-all, sticky header, zebra/dense, row click, full loading/empty/error/success states, and a responsive stacked-card strategy below next-md."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>A real <code>&lt;table&gt;</code> with <code>&lt;th scope="col"&gt;</code>; sort buttons toggle <code>aria-sort</code> on their header.</li>
        <li>Select-all sets <code>aria-checked="mixed"</code> + the native <code>indeterminate</code> when only some rows are selected; each checkbox is labelled.</li>
        <li>Loading renders <strong>skeleton rows that mimic the columns</strong> (several) — never a spinner + "Loading…".</li>
        <li>A clickable row exposes its first cell as the accessible row action (a real button).</li>
        <li>Below <code>next-md</code>, <code>responsive="stack"</code> renders label/value cards — a transform, not a shrunken table.</li>
      </ul>
    </template>

    <StorySection title="Basic + cell slots" description="Default cells render raw values; the status column uses a #cell-status slot with a StatusBadge.">
      <Table :columns="columns" :rows="members" caption="Workspace members">
        <template #cell-status="{ value }">
          <StatusBadge :status="String(value)" size="sm" />
        </template>
      </Table>
    </StorySection>

    <StorySection title="Sortable (external) + sticky header + zebra + dense" description="Click a sortable header to cycle asc → desc → none (host reorders rows). Header is sticky inside the scroll box.">
      <div class="max-h-72 overflow-y-auto rounded-next-lg border border-next-border">
        <Table
          v-model:sort="sort"
          :columns="columns"
          :rows="[...sortedMembers, ...sortedMembers]"
          sticky-header
          zebra
          dense
          class="[&>div]:border-0"
        >
          <template #cell-status="{ value }">
            <StatusBadge :status="String(value)" size="sm" />
          </template>
        </Table>
      </div>
      <p class="mt-next-2 text-next-sm text-next-muted-foreground">
        sort: {{ sort ? `${sort.key} ${sort.dir}` : 'none' }}
      </p>
    </StorySection>

    <StorySection title="Selectable + row actions" description="Checkbox column with select-all (partial → indeterminate); a trailing actions menu per row.">
      <Table
        v-model:selected="selected"
        :columns="columns"
        :rows="sortedMembers"
        row-key="id"
        selectable
      >
        <template #cell-status="{ value }">
          <StatusBadge :status="String(value)" size="sm" />
        </template>
        <template #row-actions>
          <DropdownMenu aria-label="Row actions" placement="bottom-end">
            <template #trigger="{ props: triggerProps, open }">
              <Button
                v-bind="triggerProps"
                variant="ghost"
                size="icon"
                aria-label="Row actions"
                :aria-expanded="open"
              >
                <span aria-hidden="true">⋯</span>
              </Button>
            </template>
            <DropdownMenuItem icon="eye">View</DropdownMenuItem>
            <DropdownMenuItem icon="mail">Message</DropdownMenuItem>
            <DropdownMenuItem icon="trash" destructive>Remove</DropdownMenuItem>
          </DropdownMenu>
        </template>
      </Table>
      <p class="mt-next-2 text-next-sm text-next-muted-foreground">selected ids: {{ selected.join(', ') || '—' }}</p>
    </StorySection>

    <StorySection title="States: loading (skeleton rows)" description="Skeleton rows mimic the real column layout — several rows, not a spinner.">
      <Table :columns="columns" :rows="[]" loading :loading-rows="4" />
    </StorySection>

    <StorySection title="States: empty" description="The #empty slot renders an EmptyState spanning the table.">
      <Table :columns="columns" :rows="[]">
        <template #empty>
          <EmptyState
            size="sm"
            variant="search"
            title="No members match"
            description="Try clearing the search or filters."
          >
            <template #action>
              <Button size="sm" variant="outline" leading-icon="x">Clear filters</Button>
            </template>
          </EmptyState>
        </template>
      </Table>
    </StorySection>

    <StorySection title="States: error" description="The #error slot renders an EmptyState variant='error' + retry.">
      <Table :columns="columns" :rows="[]" error>
        <template #error>
          <EmptyState
            variant="error"
            size="sm"
            title="Couldn’t load members"
            description="The request failed. Retry to try again."
          >
            <template #action>
              <Button size="sm" variant="outline" leading-icon="arrow-right">Retry</Button>
            </template>
          </EmptyState>
        </template>
      </Table>
    </StorySection>

    <StorySection title="Row click" description="The whole row is one accessible action (the first cell is a real button). Click a row.">
      <Table
        :columns="columns"
        :rows="sortedMembers"
        row-key="id"
        clickable-rows
        @row-click="onRowClick"
      >
        <template #cell-status="{ value }">
          <StatusBadge :status="String(value)" size="sm" />
        </template>
      </Table>
      <p class="mt-next-2 text-next-sm text-next-muted-foreground">last clicked: {{ lastClicked ?? '—' }}</p>
    </StorySection>

    <StorySection title="Responsive: stacked cards" description="responsive='stack' renders label/value cards below next-md. Shrink this resizable box past 768px to see the transform (not a shrunken table).">
      <div class="resize-x overflow-auto rounded-next-md border border-dashed border-next-border p-next-3" style="max-width: 100%; min-width: 18rem">
        <Table
          :columns="columns"
          :rows="sortedMembers"
          row-key="id"
          responsive="stack"
          selectable
        >
          <template #cell-status="{ value }">
            <StatusBadge :status="String(value)" size="sm" />
          </template>
          <template #row-actions>
            <Button variant="ghost" size="sm" leading-icon="eye">View</Button>
          </template>
        </Table>
      </div>
    </StorySection>

    <StorySection title="Composed: Table + Pagination + EmptyState" description="The realistic shape — a footer Pagination, and an empty state when a filter removes all rows. Toggle the filter.">
      <div class="flex flex-col gap-next-3">
        <div class="flex items-center gap-next-2">
          <Button size="sm" variant="outline" @click="filterEmpty = !filterEmpty">
            {{ filterEmpty ? 'Show rows' : 'Simulate no results' }}
          </Button>
        </div>
        <Table
          :columns="columns"
          :rows="composedRows"
          row-key="id"
          responsive="stack"
        >
          <template #cell-status="{ value }">
            <StatusBadge :status="String(value)" size="sm" />
          </template>
          <template #empty>
            <EmptyState
              size="sm"
              variant="search"
              title="No results"
              description="No members match the current filter."
            >
              <template #action>
                <Button size="sm" variant="outline" leading-icon="x" @click="filterEmpty = false">Clear filter</Button>
              </template>
            </EmptyState>
          </template>
          <template #footer>
            <Pagination
              v-model="composedPage"
              :page-count="8"
              :total="154"
              :page-size="20"
              show-summary
            />
          </template>
        </Table>
      </div>
    </StorySection>

    <StorySection title="Light + dark">
      <div class="grid gap-next-4 sm:grid-cols-2">
        <div class="next-root rounded-next-lg border border-next-border bg-next-bg p-next-3">
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">light</p>
          <Table :columns="columns" :rows="sortedMembers.slice(0, 3)" zebra>
            <template #cell-status="{ value }"><StatusBadge :status="String(value)" size="sm" /></template>
          </Table>
        </div>
        <div class="next-root dark rounded-next-lg border border-next-border bg-next-bg p-next-3">
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">dark</p>
          <Table :columns="columns" :rows="sortedMembers.slice(0, 3)" zebra dense>
            <template #cell-status="{ value }"><StatusBadge :status="String(value)" size="sm" /></template>
          </Table>
        </div>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Slots" type-header="Scope" :rows="slotRows" />
        <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
