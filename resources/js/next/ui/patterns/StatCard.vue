<script setup lang="ts">
// StatCard — a KPI / metric card for the "next" frontend.
//
// Built ON Card. Shows a label, a big tabular-nums value, an optional trend
// delta, an optional leading icon, optional helper/footnote text, and an
// optional sparkline (a bare `#sparkline` slot — NO chart library).
//
// Trend delta is NEVER color-only: a positive/negative `delta` renders a
// direction ARROW + an explicit sign + a success/danger TONE. `invertTrend`
// flips the tone mapping for metrics where "down is good" (e.g. error rate,
// cost) — the arrow still follows the raw sign, only the color swaps.
//
// `to` makes the whole card a single accessible link (Card's stretched-link).
// `loading` renders a skeleton variant mirroring the geometry (icon + label +
// big value + delta), never a spinner. Sizes `md` / `lg`.
import { computed, resolveComponent, type Component } from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import Card from '../layout/Card.vue';
import Icon, { type IconName } from '../primitives/Icon.vue';
import Skeleton from '../data/Skeleton.vue';

type StatSize = 'md' | 'lg';

const props = withDefaults(
  defineProps<{
    /** Metric name (e.g. "Active users"). */
    label: string;
    /** The metric value. Strings render verbatim; numbers get tabular-nums. */
    value: string | number;
    /** Leading icon (rendered in a tinted bubble). */
    icon?: IconName;
    /**
     * Signed change vs. the previous period. Renders an arrow + sign + tone.
     * Positive → up arrow; negative → down arrow; 0 → neutral (no tone).
     */
    delta?: number;
    /**
     * For "down is good" metrics (error rate, cost): a negative delta reads as
     * success and a positive delta as danger. The arrow still follows the sign.
     */
    invertTrend?: boolean;
    /** Suffix appended to the delta (e.g. "%", "pts"). */
    deltaSuffix?: string;
    /** Accessible/explanatory text for the delta (e.g. "vs last week"). */
    deltaLabel?: string;
    /** Helper text / footnote under the value. */
    helper?: string;

    // --- Interactive ---
    /** Router target — makes the whole card a link. */
    to?: RouteLocationRaw;
    /** Href — makes the whole card an anchor link. */
    href?: string;
    target?: string;
    /** Accessible name for the whole-card link. */
    actionLabel?: string;

    size?: StatSize;
    loading?: boolean;
  }>(),
  {
    invertTrend: false,
    size: 'md',
    loading: false,
  },
);

const isInteractive = computed(() => props.to !== undefined || props.href !== undefined);

const useRouterLink = computed(
  () => props.to !== undefined && typeof resolveComponent('router-link') !== 'string',
);
const actionTag = computed<Component | 'a'>(() =>
  useRouterLink.value ? (resolveComponent('router-link') as Component) : 'a',
);

// Delta direction + tone (never color-only — arrow + sign carry it too).
const deltaDir = computed<'up' | 'down' | 'flat'>(() => {
  if (props.delta === undefined || props.delta === 0) return 'flat';
  return props.delta > 0 ? 'up' : 'down';
});
const deltaIcon = computed<IconName>(() =>
  deltaDir.value === 'up' ? 'arrow-up' : deltaDir.value === 'down' ? 'arrow-down' : 'minus',
);
// "Good" = success tone, "bad" = danger tone, flat = neutral. `invertTrend`
// swaps which direction counts as good.
const deltaTone = computed<'success' | 'danger' | 'neutral'>(() => {
  if (deltaDir.value === 'flat') return 'neutral';
  const upIsGood = !props.invertTrend;
  const isGood = deltaDir.value === 'up' ? upIsGood : !upIsGood;
  return isGood ? 'success' : 'danger';
});
const deltaToneClass = computed(() => {
  if (deltaTone.value === 'success') return 'text-next-success';
  if (deltaTone.value === 'danger') return 'text-next-danger';
  return 'text-next-muted-foreground';
});
const deltaText = computed(() => {
  if (props.delta === undefined) return '';
  const sign = props.delta > 0 ? '+' : props.delta < 0 ? '−' : '';
  const magnitude = Math.abs(props.delta);
  return `${sign}${magnitude}${props.deltaSuffix ?? ''}`;
});

const SIZE = {
  md: { value: 'text-next-3xl', bubble: 'h-10 w-10 text-next-lg' },
  lg: { value: 'text-next-4xl', bubble: 'h-12 w-12 text-next-xl' },
} as const;
const sz = computed(() => SIZE[props.size]);
</script>

<template>
  <Card
    :variant="isInteractive ? 'interactive' : 'default'"
    class="next-stat-card"
  >
    <!-- Loading skeleton mirrors the geometry: icon bubble + label + big value +
         delta line. Several shapes, not a spinner. -->
    <div v-if="loading" class="flex items-start gap-next-3" aria-hidden="true">
      <Skeleton variant="circle" :diameter="size === 'lg' ? '3rem' : '2.5rem'" />
      <div class="flex min-w-0 flex-1 flex-col gap-next-2">
        <Skeleton variant="text" width="45%" />
        <Skeleton variant="rect" width="40%" :height="size === 'lg' ? '2.25rem' : '1.875rem'" radius="md" />
        <Skeleton variant="text" width="30%" />
      </div>
    </div>

    <div v-else class="flex items-start gap-next-3">
      <span
        v-if="icon"
        class="flex shrink-0 items-center justify-center rounded-next-lg bg-next-muted text-next-muted-foreground"
        :class="sz.bubble"
        aria-hidden="true"
      >
        <Icon :name="icon" />
      </span>

      <div class="flex min-w-0 flex-1 flex-col gap-next-0_5">
        <!-- Label is the whole-card stretched action when interactive. -->
        <component
          :is="isInteractive ? actionTag : 'p'"
          class="truncate text-next-sm font-next-medium text-next-muted-foreground outline-none"
          :class="isInteractive ? 'next-stat-card__action' : ''"
          :to="useRouterLink ? to : undefined"
          :href="!useRouterLink && isInteractive ? href : undefined"
          :target="!useRouterLink && isInteractive ? target : undefined"
          :rel="!useRouterLink && target === '_blank' ? 'noopener noreferrer' : undefined"
          :aria-label="actionLabel"
        >
          {{ label }}
        </component>

        <p
          class="font-next-semibold tabular-nums text-next-fg"
          :class="sz.value"
        >
          {{ value }}
        </p>

        <!-- Trend delta: arrow + sign + tone (never color-only). -->
        <div
          v-if="delta !== undefined || $slots.delta"
          class="mt-next-0_5 flex items-center gap-next-1 text-next-xs"
        >
          <slot name="delta">
            <span class="inline-flex items-center gap-next-0_5 font-next-medium" :class="deltaToneClass">
              <Icon :name="deltaIcon" class="text-[0.9em]" :stroke-width="2.5" aria-hidden="true" />
              <span class="tabular-nums">{{ deltaText }}</span>
            </span>
            <span v-if="deltaLabel" class="text-next-muted-foreground">{{ deltaLabel }}</span>
          </slot>
        </div>

        <p v-if="helper" class="mt-next-0_5 text-next-xs text-next-muted-foreground">
          {{ helper }}
        </p>

        <!-- Optional sparkline — a bare slot; no chart lib ships here. -->
        <div v-if="$slots.sparkline" class="mt-next-2">
          <slot name="sparkline" />
        </div>
      </div>
    </div>
  </Card>
</template>

<style scoped>
/* Stretched link over the label so the whole KPI card is one accessible action. */
.next-stat-card__action::after {
  content: '';
  position: absolute;
  inset: 0;
  z-index: 1;
}
.next-stat-card__action:focus-visible {
  outline: none;
}
</style>
