<script setup lang="ts">
// DescriptionList — key→value pairs for detail panels in the "next" frontend.
//
// Renders a proper `<dl>` / `<dt>` / `<dd>` structure with three layouts:
//   • `stacked`    — label above value, rows stacked (default; good in cards).
//   • `horizontal` — label and value side by side (label column + value).
//   • `grid`       — a responsive multi-column grid of stacked pairs.
//
// Items come from the `items` prop (`{ label, value?, ... }`). A value can be
// overridden per item with a `#value-<key>` slot (for badges, links, status),
// or supply everything via the default `#` slot of bare `<DescriptionItem>`s.
// Empty / nullish values render an em dash so the row never looks broken.
import { computed } from 'vue';

export interface DescriptionItem {
  /** Stable key — also used to address the per-item `#value-<key>` slot. */
  key: string;
  label: string;
  /** Plain value; omit to use a `#value-<key>` slot or show the empty dash. */
  value?: string | number | null;
}

type DLLayout = 'stacked' | 'horizontal' | 'grid';

const props = withDefaults(
  defineProps<{
    items: DescriptionItem[];
    layout?: DLLayout;
    /** Columns for `grid` layout at >= next-md (base is always 1). */
    columns?: 1 | 2 | 3 | 4;
    /** Placeholder for empty/nullish values. */
    emptyValue?: string;
    /** Size scale for the text. */
    size?: 'sm' | 'md';
  }>(),
  {
    layout: 'stacked',
    columns: 2,
    emptyValue: '—',
    size: 'md',
  },
);

const GRID_COLS: Record<NonNullable<typeof props.columns>, string> = {
  1: 'next-md:grid-cols-1',
  2: 'next-md:grid-cols-2',
  3: 'next-md:grid-cols-3',
  4: 'next-md:grid-cols-4',
};

const listClass = computed(() => {
  if (props.layout === 'grid') {
    return ['grid grid-cols-1 gap-next-4', GRID_COLS[props.columns]];
  }
  if (props.layout === 'horizontal') {
    return ['flex flex-col divide-y divide-next-border'];
  }
  // stacked
  return ['flex flex-col gap-next-4'];
});

const labelSize = computed(() =>
  props.size === 'sm' ? 'text-next-xs' : 'text-next-sm',
);
const valueSize = computed(() =>
  props.size === 'sm' ? 'text-next-sm' : 'text-next-base',
);

function isEmpty(value: DescriptionItem['value']): boolean {
  return value === null || value === undefined || value === '';
}
</script>

<template>
  <dl class="next-description-list min-w-0" :class="listClass">
    <div
      v-for="item in items"
      :key="item.key"
      class="next-description-list__item min-w-0"
      :class="
        layout === 'horizontal'
          ? 'grid grid-cols-[minmax(8rem,_1fr)_2fr] items-baseline gap-next-4 py-next-3 first:pt-0 last:pb-0'
          : 'flex flex-col gap-next-1'
      "
    >
      <dt class="min-w-0 font-next-medium text-next-muted-foreground" :class="labelSize">
        {{ item.label }}
      </dt>
      <dd class="min-w-0 text-next-fg" :class="valueSize">
        <!-- Per-item value slot wins (badges/links/status); else the plain value
             or the empty dash. -->
        <slot :name="`value-${item.key}`" :item="item" :value="item.value">
          <span v-if="isEmpty(item.value)" class="text-next-muted-foreground">
            {{ emptyValue }}
          </span>
          <template v-else>{{ item.value }}</template>
        </slot>
      </dd>
    </div>

    <!-- Free-form children (bare rows) appended after the prop-driven items. -->
    <slot />
  </dl>
</template>
