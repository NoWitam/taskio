<script setup lang="ts">
// Pagination — classic page navigation for the "next" frontend.
//
// Prev / next buttons + numbered page buttons with ellipsis windowing controlled
// by `siblingCount` (pages either side of the current page) and `boundaryCount`
// (pages pinned at each end). The current page is `v-model` and carries
// `aria-current="page"`. Prev/next disable at the edges.
//
// Optionally renders a compact summary ("1–20 z 154") and a per-page-size Select
// (`v-model:pageSize`, `pageSizes`). Sizes `sm` / `md`.
//
// NOTE: this is OFFSET (numbered page) pagination. Cursor-pagination UIs should
// use infinite scroll + skeletons (see the Skeleton usage rules), not this
// component.
//
// A11y: a `<nav aria-label="Pagination">` of real Buttons; ellipses are inert
// `aria-hidden` spans; the page-size Select is labelled.
import { computed } from 'vue';
import Button from '../primitives/Button.vue';
import Select, { type SelectOption } from '../forms/Select.vue';
import { useI18n } from '../../app/i18n';

const { t } = useI18n();

type PaginationSize = 'sm' | 'md';

const props = withDefaults(
  defineProps<{
    /** Total number of pages (when known). Required for numbered windowing. */
    pageCount: number;
    /** Pages shown either side of the current page. */
    siblingCount?: number;
    /** Pages pinned at each boundary (start / end). */
    boundaryCount?: number;
    size?: PaginationSize;
    disabled?: boolean;

    /** Total item count, for the compact summary + auto page-count when set. */
    total?: number;
    /** Items per page (drives the summary range + the page-size Select value). */
    pageSize?: number;
    /** Show the "X–Y of N" summary. */
    showSummary?: boolean;
    /** Page-size options; when set, renders a per-page Select. */
    pageSizes?: number[];

    // i18n-friendly labels (Polish-leaning defaults to match the app).
    prevLabel?: string;
    nextLabel?: string;
    /** ({ from, to, total }) => string for the summary. */
    summaryLabel?: (range: { from: number; to: number; total: number }) => string;
    pageSizeLabel?: string;
    /** (n) => string for each page-size option label. */
    pageSizeOptionLabel?: (n: number) => string;
  }>(),
  {
    siblingCount: 1,
    boundaryCount: 1,
    size: 'md',
    disabled: false,
    showSummary: false,
  },
);

// i18n-defaulted labels (overridable via props).
const prevLabelText = computed(() => props.prevLabel ?? t('pagination.previous', 'Previous'));
const nextLabelText = computed(() => props.nextLabel ?? t('pagination.next', 'Next'));
const pageSizeLabelText = computed(() => props.pageSizeLabel ?? t('pagination.pageSize', 'Per page'));

const emit = defineEmits<{
  (e: 'update:pageSize', value: number): void;
}>();

const page = defineModel<number>({ default: 1 });

const ELLIPSIS = 'ellipsis' as const;
type PageToken = number | typeof ELLIPSIS;

const totalPages = computed(() => Math.max(1, props.pageCount));

// Build the windowed list of page tokens (numbers + ellipses), mirroring MUI's
// usePagination range algorithm.
const range = computed<PageToken[]>(() => {
  const count = totalPages.value;
  const boundary = Math.max(0, props.boundaryCount);
  const sibling = Math.max(0, props.siblingCount);
  const current = Math.min(Math.max(1, page.value), count);

  // If everything fits, list all pages.
  const totalSlots = boundary * 2 + sibling * 2 + 3; // first/last block + current + 2 ellipses
  if (count <= totalSlots) {
    return Array.from({ length: count }, (_, i) => i + 1);
  }

  const startPages = rangeNums(1, Math.min(boundary, count));
  const endPages = rangeNums(Math.max(count - boundary + 1, boundary + 1), count);

  const siblingsStart = Math.max(
    Math.min(current - sibling, count - boundary - sibling * 2 - 1),
    boundary + 2,
  );
  const siblingsEnd = Math.min(
    Math.max(current + sibling, boundary + sibling * 2 + 2),
    endPages.length > 0 ? endPages[0] - 2 : count - 1,
  );

  const items: PageToken[] = [
    ...startPages,
    ...(siblingsStart > boundary + 2
      ? [ELLIPSIS]
      : boundary + 1 < count - boundary
        ? [boundary + 1]
        : []),
    ...rangeNums(siblingsStart, siblingsEnd),
    ...(siblingsEnd < count - boundary - 1
      ? [ELLIPSIS]
      : count - boundary > boundary
        ? [count - boundary]
        : []),
    ...endPages,
  ];
  return items;
});

function rangeNums(start: number, end: number): number[] {
  const length = end - start + 1;
  return length > 0 ? Array.from({ length }, (_, i) => start + i) : [];
}

const isFirst = computed(() => page.value <= 1);
const isLast = computed(() => page.value >= totalPages.value);

function go(target: number): void {
  if (props.disabled) return;
  const clamped = Math.min(Math.max(1, target), totalPages.value);
  if (clamped !== page.value) page.value = clamped;
}

// --- Summary ----------------------------------------------------------------
const summary = computed<string | null>(() => {
  if (!props.showSummary || props.total == null || props.pageSize == null) return null;
  const from = props.total === 0 ? 0 : (page.value - 1) * props.pageSize + 1;
  const to = Math.min(page.value * props.pageSize, props.total);
  if (props.summaryLabel) return props.summaryLabel({ from, to, total: props.total });
  return t('pagination.summary', '{from}–{to} of {total}', {
    from,
    to,
    total: props.total,
  });
});

// --- Page-size Select -------------------------------------------------------
const pageSizeModel = computed<string | null>({
  get: () => (props.pageSize != null ? String(props.pageSize) : null),
  set: (val) => {
    if (val != null) emit('update:pageSize', Number(val));
  },
});

const pageSizeOptions = computed<SelectOption[]>(() =>
  (props.pageSizes ?? []).map((n) => ({
    value: String(n),
    label: props.pageSizeOptionLabel ? props.pageSizeOptionLabel(n) : String(n),
  })),
);

const buttonSize = computed<'sm' | 'md'>(() => props.size);
const iconButtonSize = computed(() => (props.size === 'sm' ? 'h-8 w-8' : 'h-10 w-10'));
const pageButtonSize = computed(() =>
  props.size === 'sm' ? 'h-8 min-w-8 px-next-2' : 'h-10 min-w-10 px-next-2_5',
);
</script>

<template>
  <nav
    aria-label="Pagination"
    class="next-pagination flex flex-wrap items-center justify-between gap-next-3"
  >
    <!-- Summary (left). -->
    <p
      v-if="summary"
      class="order-1 text-next-sm text-next-muted-foreground"
      aria-live="polite"
    >
      {{ summary }}
    </p>

    <!-- Page controls (center / right). -->
    <ul class="order-3 flex items-center gap-next-1 next-md:order-2">
      <li>
        <Button
          variant="outline"
          :size="buttonSize"
          leading-icon="chevron-left"
          :disabled="disabled || isFirst"
          :aria-label="prevLabelText"
          @click="go(page - 1)"
        >
          <span class="hidden next-sm:inline">{{ prevLabelText }}</span>
        </Button>
      </li>

      <li v-for="(token, i) in range" :key="`${token}-${i}`" class="flex items-center">
        <span
          v-if="token === ELLIPSIS"
          :class="['inline-flex items-center justify-center text-next-muted-foreground', iconButtonSize]"
          aria-hidden="true"
        >
          …
        </span>
        <button
          v-else
          type="button"
          :class="[
            'inline-flex items-center justify-center rounded-next-md text-next-sm font-next-medium transition-colors duration-[var(--duration-next-fast)] ease-[var(--ease-next-standard)] outline-none focus-visible:ring-2 focus-visible:ring-next-ring',
            pageButtonSize,
            token === page
              ? 'bg-next-primary text-next-primary-foreground'
              : 'text-next-fg hover:bg-next-accent hover:text-next-accent-foreground',
            disabled ? 'pointer-events-none opacity-60' : 'cursor-pointer',
          ]"
          :aria-current="token === page ? 'page' : undefined"
          :aria-label="t('pagination.page', 'Page {page}', { page: token })"
          :disabled="disabled"
          @click="go(token)"
        >
          {{ token }}
        </button>
      </li>

      <li>
        <Button
          variant="outline"
          :size="buttonSize"
          trailing-icon="chevron-right"
          :disabled="disabled || isLast"
          :aria-label="nextLabelText"
          @click="go(page + 1)"
        >
          <span class="hidden next-sm:inline">{{ nextLabelText }}</span>
        </Button>
      </li>
    </ul>

    <!-- Per-page-size Select (right). -->
    <label
      v-if="pageSizes && pageSizes.length"
      class="order-2 flex items-center gap-next-2 text-next-sm text-next-muted-foreground next-md:order-3"
    >
      <span class="whitespace-nowrap">{{ pageSizeLabelText }}</span>
      <Select
        v-model="pageSizeModel"
        :options="pageSizeOptions"
        :size="size"
        :disabled="disabled"
        class="w-20"
        :aria-label="t('pagination.pageSizeLabel', 'Items per page')"
      />
    </label>
  </nav>
</template>
