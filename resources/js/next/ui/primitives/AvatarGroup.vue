<script setup lang="ts">
// AvatarGroup primitive for the "next" frontend.
//
// Stacks Avatar children with a slight negative overlap and an optional `+N`
// overflow chip when the collection exceeds `max`. The group is exposed as a
// labelled list so the count is meaningful to assistive tech; the `+N` chip
// carries an aria-label describing how many more people are hidden.
//
// Avatars are passed via the default slot; pass `total` (or rely on the slotted
// count) and `max` to control overflow. For overflow to render you must tell the
// group the full `total`, since slot content is what's rendered (already capped
// by the caller) — see the gallery usage example.
import { computed } from 'vue';

type AvatarGroupSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl';

const props = withDefaults(
  defineProps<{
    /** Max avatars to show before collapsing into a `+N` chip. */
    max?: number;
    /** Total number of people represented (for the `+N` overflow count). */
    total?: number;
    /** Number of avatars actually placed in the default slot. */
    shown?: number;
    size?: AvatarGroupSize;
    /** Accessible label for the group, e.g. "Project members". */
    label?: string;
  }>(),
  {
    size: 'md',
    label: 'People',
  },
);

const SIZE_CLASS: Record<AvatarGroupSize, string> = {
  xs: 'h-6 w-6 text-next-2xs',
  sm: 'h-8 w-8 text-next-xs',
  md: 'h-10 w-10 text-next-sm',
  lg: 'h-12 w-12 text-next-base',
  xl: 'h-16 w-16 text-next-xl',
};

const overflow = computed(() => {
  if (props.total == null || props.shown == null) return 0;
  return Math.max(0, props.total - props.shown);
});
</script>

<template>
  <div
    class="next-avatar-group flex items-center"
    role="group"
    :aria-label="label"
  >
    <div class="flex -space-x-2 next-avatar-group__items">
      <slot />
      <span
        v-if="overflow > 0"
        class="relative inline-flex shrink-0 items-center justify-center rounded-next-full bg-next-accent font-next-medium text-next-accent-foreground ring-2 ring-next-card"
        :class="SIZE_CLASS[size]"
        role="img"
        :aria-label="`${overflow} more`"
      >
        +{{ overflow }}
      </span>
    </div>
  </div>
</template>

<style scoped>
/* Each stacked avatar gets a card-colored ring so overlaps read cleanly in both
   themes (the ring is the surface behind, defined via the token). */
.next-avatar-group__items :deep(.next-avatar > span:first-child) {
  box-shadow: 0 0 0 2px var(--color-next-card);
}
</style>
