<script setup lang="ts">
// ApprovalStageStepper — a compact horizontal progress strip for the review
// drawer header. One node per pipeline stage showing, at a glance, what is done
// (✓ / ✕), which stage is current (highlighted), and what is still locked ahead.
// Purely presentational: it renders the `StageView[]` the drawer computes. Scrolls
// horizontally (no wrap) when a pipeline has many stages.
import Icon, { type IconName } from '../../ui/primitives/Icon.vue';
import type { StageView } from './stageView';

defineProps<{
  items: StageView[];
  ariaLabel?: string;
}>();

function nodeClass(view: StageView): string {
  if (view.state === 'current') return 'bg-next-primary text-next-primary-foreground';
  if (view.outcome === 'rejected') return 'bg-next-danger text-next-danger-foreground';
  if (view.state === 'done' || view.outcome === 'approved') {
    return 'bg-next-success text-next-success-foreground';
  }
  return 'bg-next-muted text-next-muted-foreground';
}

function nodeIcon(view: StageView): IconName {
  if (view.state === 'current') return 'clock';
  if (view.outcome === 'rejected') return 'x';
  if (view.state === 'done' || view.outcome === 'approved') return 'check';
  return 'lock';
}

function labelClass(view: StageView): string {
  if (view.state === 'current') return 'font-next-medium text-next-fg';
  if (view.state === 'upcoming') return 'text-next-muted-foreground';
  return 'text-next-fg';
}
</script>

<template>
  <ol
    class="flex items-center gap-next-1 overflow-x-auto scrollbar-none"
    :aria-label="ariaLabel"
  >
    <li
      v-for="(view, i) in items"
      :key="view.stage.id"
      class="flex shrink-0 items-center gap-next-1"
    >
      <span class="flex items-center gap-next-1_5">
        <span
          :class="[
            'flex h-5 w-5 items-center justify-center rounded-next-full',
            nodeClass(view),
          ]"
          aria-hidden="true"
        >
          <Icon :name="nodeIcon(view)" class="text-[0.7rem]" />
        </span>
        <span :class="['whitespace-nowrap text-next-xs', labelClass(view)]">
          {{ view.stage.name }}
        </span>
      </span>
      <span
        v-if="i < items.length - 1"
        class="h-px w-4 shrink-0 bg-next-border"
        aria-hidden="true"
      />
    </li>
  </ol>
</template>

<style scoped>
/* Hide the horizontal scrollbar while staying scrollable (mirrors Tabs). */
.scrollbar-none {
  scrollbar-width: none;
  -ms-overflow-style: none;
}
.scrollbar-none::-webkit-scrollbar {
  display: none;
}
</style>
