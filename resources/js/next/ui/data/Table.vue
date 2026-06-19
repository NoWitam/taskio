<script setup lang="ts" generic="T extends Record<string, unknown>">
// Table — a typed data table for the "next" frontend.
//
// Column-driven: pass `columns` ({ key, label, align?, width?, sortable?, hidden? })
// + `rows: T[]`. Cells render their raw value by default; override any column with
// a scoped `#cell-<key>="{ row, value }"` slot. A `#row-actions="{ row }"` slot
// renders a trailing actions column.
//
// Features:
//   • Sortable headers — click cycles asc → desc → none, surfaced via
//     `v-model:sort` ({ key, dir }); sorting is performed EXTERNALLY (the host
//     reorders rows). `aria-sort` reflects the current column.
//   • Selectable rows — a leading checkbox column with select-all (indeterminate
//     when partial), surfaced via `v-model:selected` (array of row keys).
//   • Row click — an accessible per-row action (the whole row becomes a button
//     row) emitting `row-click`.
//   • Sticky header, zebra striping, dense rows, row hover.
//
// States (mutually exclusive, in precedence order):
//   loading → skeleton rows that MIMIC the column layout (several), never a
//   spinner; error → the `#error` slot (drop an EmptyState variant="error" with a
//   retry Button); empty → the `#empty` slot (drop an EmptyState); success → rows.
//
// Responsive: `responsive="stack"` renders each row as a label/value CARD list
// below the `next-md` breakpoint instead of shrinking the table — a real
// transform, per the UX rule. `responsive="scroll"` keeps the table and scrolls
// horizontally.
//
// A11y: a real `<table>` with `<th scope="col">`; sort buttons toggle `aria-sort`;
// the select-all checkbox sets `aria-checked="mixed"` when partial; a clickable
// row exposes one button as the row's accessible action.
import { computed, ref } from 'vue';
import Icon from '../primitives/Icon.vue';
import Skeleton from './Skeleton.vue';
import { useI18n } from '../../app/i18n';

export type SortDir = 'asc' | 'desc';
export interface SortState {
  key: string;
  dir: SortDir;
}

export interface TableColumn<Row = Record<string, unknown>> {
  /** Field key (matches `row[key]`) + slot/identity key. */
  key: string;
  label: string;
  align?: 'start' | 'center' | 'end';
  /** Fixed column width (any CSS length). */
  width?: string;
  sortable?: boolean;
  /** Hide the column entirely (kept in the array for stable keys). */
  hidden?: boolean;
}

const { t } = useI18n();

const props = withDefaults(
  defineProps<{
    columns: TableColumn<T>[];
    rows: T[];
    /** Stable row identity used for selection + keys. Defaults to array index. */
    rowKey?: keyof T | ((row: T, index: number) => string | number);

    // States
    loading?: boolean;
    error?: boolean;
    /** Number of skeleton rows to render while loading. */
    loadingRows?: number;

    // Visual options
    stickyHeader?: boolean;
    zebra?: boolean;
    dense?: boolean;
    hoverable?: boolean;

    // Behavior
    selectable?: boolean;
    /** Whole-row click action (emits `row-click`). */
    clickableRows?: boolean;

    // Responsive strategy below `next-md`.
    responsive?: 'scroll' | 'stack';

    /** Caption for screen readers (visually hidden). */
    caption?: string;
  }>(),
  {
    loadingRows: 5,
    loading: false,
    error: false,
    stickyHeader: false,
    zebra: false,
    dense: false,
    hoverable: true,
    selectable: false,
    clickableRows: false,
    responsive: 'scroll',
  },
);

const emit = defineEmits<{
  (e: 'row-click', row: T, index: number): void;
}>();

const sort = defineModel<SortState | null>('sort', { default: null });
const selected = defineModel<Array<string | number>>('selected', { default: () => [] });

// --- Row identity -----------------------------------------------------------
function keyOf(row: T, index: number): string | number {
  if (typeof props.rowKey === 'function') return props.rowKey(row, index);
  if (props.rowKey != null) return row[props.rowKey] as string | number;
  return index;
}

// --- Visible columns --------------------------------------------------------
const visibleColumns = computed(() => props.columns.filter((c) => !c.hidden));
const colSpan = computed(
  () => visibleColumns.value.length + (props.selectable ? 1 : 0),
);

// --- Sorting ----------------------------------------------------------------
function ariaSort(col: TableColumn<T>): 'ascending' | 'descending' | 'none' | undefined {
  if (!col.sortable) return undefined;
  if (sort.value?.key !== col.key) return 'none';
  return sort.value.dir === 'asc' ? 'ascending' : 'descending';
}

function toggleSort(col: TableColumn<T>): void {
  if (!col.sortable) return;
  const cur = sort.value;
  if (cur?.key !== col.key) {
    sort.value = { key: col.key, dir: 'asc' };
  } else if (cur.dir === 'asc') {
    sort.value = { key: col.key, dir: 'desc' };
  } else {
    sort.value = null; // back to unsorted
  }
}

// The header sort affordance icon. Unsorted columns show a dimmed chevron-down
// (a "sortable" hint); the active column shows the current direction.
function sortIcon(col: TableColumn<T>): 'chevron-up' | 'chevron-down' {
  if (sort.value?.key !== col.key) return 'chevron-down';
  return sort.value.dir === 'asc' ? 'chevron-up' : 'chevron-down';
}

// --- Selection --------------------------------------------------------------
const allKeys = computed(() => props.rows.map((r, i) => keyOf(r, i)));
const selectedSet = computed(() => new Set(selected.value));
const allSelected = computed(
  () => props.rows.length > 0 && allKeys.value.every((k) => selectedSet.value.has(k)),
);
const someSelected = computed(
  () => selected.value.length > 0 && !allSelected.value,
);

const selectAllRef = ref<HTMLInputElement | null>(null);

function isRowSelected(row: T, index: number): boolean {
  return selectedSet.value.has(keyOf(row, index));
}

function toggleRow(row: T, index: number): void {
  const k = keyOf(row, index);
  const next = new Set(selected.value);
  if (next.has(k)) next.delete(k);
  else next.add(k);
  selected.value = [...next];
}

function toggleAll(): void {
  selected.value = allSelected.value ? [] : [...allKeys.value];
}

// --- Row click --------------------------------------------------------------
function onRowClick(row: T, index: number): void {
  if (!props.clickableRows) return;
  emit('row-click', row, index);
}

// --- Cell helpers -----------------------------------------------------------
function cellValue(row: T, col: TableColumn<T>): unknown {
  return row[col.key as keyof T];
}

function alignClass(align?: 'start' | 'center' | 'end'): string {
  if (align === 'center') return 'text-center';
  if (align === 'end') return 'text-right';
  return 'text-left';
}

// --- Cell padding (dense) ---------------------------------------------------
const cellPad = computed(() => (props.dense ? 'px-next-3 py-next-1_5' : 'px-next-4 py-next-3'));

const showEmpty = computed(
  () => !props.loading && !props.error && props.rows.length === 0,
);
const showRows = computed(
  () => !props.loading && !props.error && props.rows.length > 0,
);
</script>

<template>
  <div class="next-table flex flex-col">
    <!-- Error: replaces the whole table body region. -->
    <div
      v-if="error"
      class="rounded-next-lg border border-next-border bg-next-card"
    >
      <slot name="error" />
    </div>

    <!-- Stacked responsive layout (cards) below next-md, table at/above it. -->
    <template v-else>
      <!-- ===== Desktop / scroll table ===== -->
      <div
        :class="[
          'rounded-next-lg border border-next-border bg-next-card',
          responsive === 'stack' ? 'hidden next-md:block' : '',
          responsive === 'scroll' ? 'overflow-x-auto' : 'overflow-hidden',
        ]"
      >
        <table class="w-full min-w-full border-collapse text-next-sm">
          <caption v-if="caption" class="sr-only">{{ caption }}</caption>

          <thead
            :class="[
              'bg-next-muted text-next-muted-foreground',
              stickyHeader ? 'sticky top-0 z-[var(--z-next-raised)]' : '',
            ]"
          >
            <tr>
              <th
                v-if="selectable"
                scope="col"
                :class="['w-px', cellPad]"
              >
                <input
                  ref="selectAllRef"
                  type="checkbox"
                  class="next-table__checkbox h-4 w-4 cursor-pointer accent-[var(--color-next-primary)]"
                  :checked="allSelected"
                  :aria-checked="someSelected ? 'mixed' : allSelected ? 'true' : 'false'"
                  :indeterminate="someSelected"
                  :aria-label="t('table.selectAll', 'Select all rows')"
                  @change="toggleAll"
                />
              </th>

              <th
                v-for="col in visibleColumns"
                :key="col.key"
                scope="col"
                :style="col.width ? { width: col.width } : undefined"
                :aria-sort="ariaSort(col)"
                :class="[
                  'font-next-medium whitespace-nowrap',
                  alignClass(col.align),
                  cellPad,
                ]"
              >
                <button
                  v-if="col.sortable"
                  type="button"
                  class="group inline-flex items-center gap-next-1 rounded-next-sm font-next-medium outline-none transition-colors duration-[var(--duration-next-fast)] hover:text-next-fg focus-visible:ring-2 focus-visible:ring-next-ring"
                  :class="sort?.key === col.key ? 'text-next-fg' : ''"
                  :aria-label="t('table.sortBy', 'Sort by {label}', { label: col.label })"
                  @click="toggleSort(col)"
                >
                  <span>{{ col.label }}</span>
<Icon
                    :name="sortIcon(col)"
                    class="text-next-xs shrink-0"
                    :class="sort?.key === col.key ? 'opacity-100' : 'opacity-40 group-hover:opacity-70'"
                    aria-hidden="true"
                  />
                </button>
                <span v-else>{{ col.label }}</span>
              </th>

              <th
                v-if="$slots['row-actions']"
                scope="col"
                :class="['w-px text-right', cellPad]"
              >
                <span class="sr-only">{{ t('table.actions', 'Actions') }}</span>
              </th>
            </tr>
          </thead>

          <tbody>
            <!-- Loading: skeleton rows mimicking the column layout (several). -->
            <template v-if="loading">
              <tr v-for="n in loadingRows" :key="`sk-${n}`" class="border-t border-next-border">
                <td v-if="selectable" :class="cellPad">
                  <Skeleton variant="rect" :width="16" :height="16" radius="sm" />
                </td>
                <td
                  v-for="col in visibleColumns"
                  :key="col.key"
                  :class="[alignClass(col.align), cellPad]"
                >
                  <Skeleton variant="text" :width="`${50 + ((n * 17 + col.key.length * 7) % 45)}%`" />
                </td>
                <td v-if="$slots['row-actions']" :class="['text-right', cellPad]">
                  <Skeleton variant="circle" diameter="1.25rem" class="ml-auto" />
                </td>
              </tr>
            </template>

            <!-- Empty: span all columns with the #empty slot (drop an EmptyState). -->
            <tr v-else-if="showEmpty">
              <td :colspan="colSpan + ($slots['row-actions'] ? 1 : 0)" class="p-next-0">
                <slot name="empty" />
              </td>
            </tr>

            <!-- Rows. -->
            <tr
              v-else
              v-for="(row, index) in rows"
              :key="keyOf(row, index)"
              :class="[
                'border-t border-next-border transition-colors duration-[var(--duration-next-fast)]',
                zebra && index % 2 === 1 ? 'bg-next-muted/40' : '',
                hoverable ? 'hover:bg-next-accent/40' : '',
                isRowSelected(row, index) ? 'bg-next-primary-subtle/50' : '',
                clickableRows ? 'cursor-pointer' : '',
              ]"
              @click="onRowClick(row, index)"
            >
              <td v-if="selectable" :class="cellPad" @click.stop>
                <input
                  type="checkbox"
                  class="next-table__checkbox h-4 w-4 cursor-pointer accent-[var(--color-next-primary)]"
                  :checked="isRowSelected(row, index)"
                  :aria-label="t('table.selectRow', 'Select row {index}', { index: index + 1 })"
                  @change="toggleRow(row, index)"
                />
              </td>

              <td
                v-for="(col, ci) in visibleColumns"
                :key="col.key"
                :class="[alignClass(col.align), cellPad, 'text-next-fg']"
              >
                <!-- Make the FIRST cell the accessible row action when clickable. -->
                <button
                  v-if="clickableRows && ci === 0"
                  type="button"
                  class="-mx-next-1 -my-next-0_5 rounded-next-sm px-next-1 py-next-0_5 text-left outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
                  @click.stop="onRowClick(row, index)"
                >
                  <slot :name="`cell-${col.key}`" :row="row" :value="cellValue(row, col)">
                    {{ cellValue(row, col) }}
                  </slot>
                </button>
                <slot
                  v-else
                  :name="`cell-${col.key}`"
                  :row="row"
                  :value="cellValue(row, col)"
                >
                  {{ cellValue(row, col) }}
                </slot>
              </td>

              <td v-if="$slots['row-actions']" :class="['text-right', cellPad]" @click.stop>
                <slot name="row-actions" :row="row" :index="index" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- ===== Stacked responsive cards (below next-md, responsive="stack") ===== -->
      <div v-if="responsive === 'stack'" class="flex flex-col gap-next-3 next-md:hidden">
        <!-- Loading skeleton cards mimic the stacked layout. -->
        <template v-if="loading">
          <div
            v-for="n in loadingRows"
            :key="`sk-card-${n}`"
            class="rounded-next-lg border border-next-border bg-next-card p-next-4"
          >
            <div v-for="col in visibleColumns" :key="col.key" class="flex items-center justify-between gap-next-3 py-next-1">
              <Skeleton variant="text" width="30%" />
              <Skeleton variant="text" :width="`${30 + ((n * 11 + col.key.length * 5) % 30)}%`" />
            </div>
          </div>
        </template>

        <!-- Empty in stacked mode. -->
        <div v-else-if="showEmpty" class="rounded-next-lg border border-next-border bg-next-card">
          <slot name="empty" />
        </div>

        <!-- Row cards: each column becomes a label / value pair. -->
        <template v-else>
          <div
            v-for="(row, index) in rows"
            :key="keyOf(row, index)"
            :class="[
              'rounded-next-lg border border-next-border bg-next-card p-next-4',
              isRowSelected(row, index) ? 'ring-2 ring-next-primary/40' : '',
              clickableRows ? 'cursor-pointer' : '',
            ]"
            @click="onRowClick(row, index)"
          >
            <div class="flex items-start justify-between gap-next-3">
              <div class="flex min-w-0 flex-1 flex-col gap-next-1_5">
                <div
                  v-for="col in visibleColumns"
                  :key="col.key"
                  class="flex items-baseline justify-between gap-next-3"
                >
                  <span class="shrink-0 text-next-xs font-next-medium text-next-muted-foreground">
                    {{ col.label }}
                  </span>
                  <span class="min-w-0 truncate text-right text-next-sm text-next-fg">
                    <slot :name="`cell-${col.key}`" :row="row" :value="cellValue(row, col)">
                      {{ cellValue(row, col) }}
                    </slot>
                  </span>
                </div>
              </div>
              <div class="flex shrink-0 flex-col items-end gap-next-2" @click.stop>
                <input
                  v-if="selectable"
                  type="checkbox"
                  class="h-4 w-4 cursor-pointer accent-[var(--color-next-primary)]"
                  :checked="isRowSelected(row, index)"
                  :aria-label="t('table.selectRow', 'Select row {index}', { index: index + 1 })"
                  @change="toggleRow(row, index)"
                />
                <slot v-if="$slots['row-actions']" name="row-actions" :row="row" :index="index" />
              </div>
            </div>
          </div>
        </template>
      </div>
    </template>

    <!-- Footer slot (e.g. Pagination). -->
    <div v-if="$slots.footer && !error" class="mt-next-4">
      <slot name="footer" />
    </div>
  </div>
</template>

<style scoped>
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

/* Native checkboxes can't carry an indeterminate state declaratively; we bind it
   imperatively where the browser supports the `indeterminate` IDL attr (Vue's
   `:indeterminate` sets the property). The accent color uses the primary token. */
</style>
