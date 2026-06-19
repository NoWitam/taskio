<script setup lang="ts">
// Timeline — a vertical activity / history feed for the "next" frontend.
//
// Renders an ordered list (`<ol aria-label>`) of TimelineItem nodes connected by
// a vertical line. Compose items via the default slot (full control) and/or pass
// an `items` array for the common case. Density via `compact`.
//
// States it owns:
//   • loading — several skeleton items mimicking node + title + lines (skeleton
//     rule), never a spinner.
//   • empty   — an EmptyState (sm) when there are no items.
//   • success — the items.
// Per-item states (highlighted / pending / last) live on TimelineItem.
//
// A11y: `<ol>` with an `aria-label`; each item is an `<li>`; timestamps use a
// real `<time datetime>`. The last item drops its connector automatically when
// rendering from the `items` array.
import { computed, provide, useSlots } from 'vue';
import Skeleton from '../data/Skeleton.vue';
import EmptyState from '../data/EmptyState.vue';
import TimelineItem from './TimelineItem.vue';
import type { IconName } from '../primitives/Icon.vue';
import { TIMELINE_KEY } from './timeline';

type NodeTone = 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info';

export interface TimelineEntry {
  /** Stable key. */
  id?: string | number;
  title: string;
  icon?: IconName;
  tone?: NodeTone;
  time?: string;
  datetime?: string;
  description?: string;
  highlighted?: boolean;
  pending?: boolean;
}

const props = withDefaults(
  defineProps<{
    /** Convenience data source (or compose TimelineItem in the default slot). */
    items?: TimelineEntry[];
    /** Dense layout. */
    compact?: boolean;
    /** Clamp each entry's description to N lines (when using `items`). */
    clampLines?: number;
    /** Accessible label for the feed. */
    ariaLabel?: string;
    /** Loading skeleton state (mimics node + lines, several). */
    loading?: boolean;
    /** How many skeleton items to render while loading. */
    loadingCount?: number;
    /** Empty-state title + description (shown when there are no items). */
    emptyTitle?: string;
    emptyDescription?: string;
  }>(),
  {
    compact: false,
    ariaLabel: 'Activity timeline',
    loading: false,
    loadingCount: 4,
    emptyTitle: 'No activity yet',
  },
);

const slots = useSlots();

provide(TIMELINE_KEY, { compact: computed(() => props.compact) });

const usingItems = computed(() => props.items !== undefined);
const isEmpty = computed(
  () => !props.loading && usingItems.value && (props.items?.length ?? 0) === 0 && !slots.default,
);
const skeletons = computed(() => Math.max(1, props.loadingCount));
</script>

<template>
  <div class="next-timeline">
    <!-- Loading: several skeleton items mirroring node + title + lines. -->
    <ol
      v-if="loading"
      class="next-timeline__list"
      :aria-label="ariaLabel"
      aria-busy="true"
    >
      <li
        v-for="n in skeletons"
        :key="n"
        class="relative flex gap-next-3"
        :class="compact ? 'pb-next-4' : 'pb-next-6'"
      >
        <div class="relative flex shrink-0 flex-col items-center">
          <Skeleton variant="circle" :diameter="compact ? '1.75rem' : '2.25rem'" />
          <span
            v-if="n < skeletons"
            class="absolute bottom-0 w-px bg-next-border"
            :class="compact ? 'top-7' : 'top-9'"
            aria-hidden="true"
          />
        </div>
        <div class="flex min-w-0 flex-1 flex-col gap-next-2 pb-next-2">
          <Skeleton variant="text" width="40%" />
          <Skeleton variant="text" width="80%" />
        </div>
      </li>
    </ol>

    <!-- Empty: a compact EmptyState. -->
    <EmptyState
      v-else-if="isEmpty"
      size="sm"
      :title="emptyTitle"
      :description="emptyDescription"
      icon="clock"
    />

    <!-- Success: the items + any composed default slot. -->
    <ol v-else class="next-timeline__list" :aria-label="ariaLabel">
      <TimelineItem
        v-for="(entry, i) in items"
        :key="entry.id ?? i"
        :title="entry.title"
        :icon="entry.icon"
        :tone="entry.tone"
        :time="entry.time"
        :datetime="entry.datetime"
        :description="entry.description"
        :clamp-lines="clampLines"
        :highlighted="entry.highlighted"
        :pending="entry.pending"
        :last="i === (items?.length ?? 0) - 1 && !$slots.default"
      />
      <slot />
    </ol>
  </div>
</template>
