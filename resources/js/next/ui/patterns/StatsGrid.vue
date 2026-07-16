<script setup lang="ts">
// StatsGrid — a responsive wrapper for StatCards (next frontend).
//
// Built ON Grid. Lays StatCards in a responsive grid with EQUAL heights
// (cards stretch to the tallest in their row) and sane defaults
// (base 1 → sm 2 → lg `cols`). Pass a single number, or a per-breakpoint object
// to fully control the columns.
//
// `loading` is a passthrough convenience: it renders `count` skeleton StatCards
// in the SAME grid so the loading state mirrors the real layout (skeleton rule).
import { computed } from 'vue';
import Grid from '../layout/Grid.vue';
import StatCard from './StatCard.vue';

type ResponsiveCols = { base?: number; sm?: number; md?: number; lg?: number; xl?: number };

const props = withDefaults(
  defineProps<{
    /**
     * Columns at the widest breakpoint (a number) OR a full per-breakpoint
     * object. A bare number expands to the sane default ramp base 1 → sm 2 → N.
     */
    cols?: number | ResponsiveCols;
    /** Gap token key (matches Grid's spacing scale). */
    gap?: '2' | '3' | '4' | '5' | '6';
    /** Render `count` skeleton StatCards instead of the slot content. */
    loading?: boolean;
    /** How many skeleton cards to show while loading. */
    count?: number;
    /** Skeleton card size (matches your real StatCards). */
    loadingSize?: 'md' | 'lg';
  }>(),
  {
    cols: 4,
    gap: '4',
    loading: false,
    count: 4,
    loadingSize: 'md',
  },
);

// A bare number → the default responsive ramp (1 / 2 / N). An object passes through.
const resolvedCols = computed<ResponsiveCols>(() => {
  if (typeof props.cols === 'number') {
    return { base: 1, sm: Math.min(2, props.cols), lg: props.cols };
  }
  return props.cols;
});

const skeletons = computed(() => Math.max(1, props.count));
</script>

<template>
  <!-- `items-stretch` (the grid default) + StatCard's Card filling its cell gives
       equal-height rows. -->
  <Grid :cols="resolvedCols" :gap="gap" class="next-stats-grid items-stretch">
    <template v-if="loading">
      <StatCard
        v-for="n in skeletons"
        :key="n"
        class="h-full"
        label=""
        value=""
        loading
        :size="loadingSize"
      />
    </template>
    <slot v-else />
  </Grid>
</template>

<style scoped>
/* Each direct child fills its grid cell's height so rows are visually even. */
.next-stats-grid > :deep(*) {
  height: 100%;
}
</style>
