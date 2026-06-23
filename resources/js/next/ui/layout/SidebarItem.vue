<script setup lang="ts">
// SidebarItem — a single navigation row inside Sidebar.
//
// Renders a <router-link> when `to` is set (real app nav), a plain <a> when
// `href` is set (self-contained demos / external), or a <button> otherwise.
// Shows an icon + label and an optional trailing badge/count. Active state is
// either driven by router-link's matching (when `to` is used) or forced via the
// `active` prop; either way it sets `aria-current="page"`.
//
// States: default · hover · focus-visible (token ring) · active (accent tint +
// left accent bar, not color alone) · disabled (dimmed + inert).
import { computed, useSlots } from 'vue';
import Icon, { type IconName } from '../primitives/Icon.vue';

const props = withDefaults(
  defineProps<{
    label: string;
    icon?: IconName;
    /** router-link target (real app navigation). */
    to?: string | Record<string, unknown>;
    /** Plain anchor href (demos / external). Ignored when `to` is set. */
    href?: string;
    /** Force active styling + aria-current (e.g. for demos without a router). */
    active?: boolean;
    /** Numeric/string badge shown at the trailing edge. */
    badge?: string | number;
    disabled?: boolean;
  }>(),
  {
    active: false,
    disabled: false,
  },
);

const emit = defineEmits<{ (e: 'click', event: MouseEvent): void }>();

const slots = useSlots();

const tag = computed(() => {
  if (props.to !== undefined) return 'router-link';
  if (props.href !== undefined) return 'a';
  return 'button';
});

const classes = computed(() => [
  'next-sidebar-item group relative flex w-full items-center gap-next-2 rounded-next-md ' +
    'px-next-2_5 py-next-2 text-left text-next-sm font-next-medium ' +
    'transition-colors duration-[var(--duration-next-fast)] ease-[var(--ease-next-standard)]',
  props.active
    ? 'bg-next-primary-subtle text-next-primary-subtle-foreground'
    : 'text-next-fg hover:bg-next-accent hover:text-next-accent-foreground',
  props.disabled ? 'pointer-events-none opacity-60' : 'cursor-pointer',
]);

function onClick(event: MouseEvent): void {
  if (props.disabled) {
    event.preventDefault();
    return;
  }
  emit('click', event);
}
</script>

<template>
  <component
    :is="tag"
    :to="tag === 'router-link' && !disabled ? to : undefined"
    :href="tag === 'a' && !disabled ? href : undefined"
    :type="tag === 'button' ? 'button' : undefined"
    :disabled="tag === 'button' && disabled ? true : undefined"
    :class="classes"
    :aria-current="active ? 'page' : undefined"
    :aria-disabled="disabled ? 'true' : undefined"
    @click="onClick"
  >
    <!-- Active accent bar: a shape cue so active isn't conveyed by color alone. -->
    <span
      v-if="active"
      class="absolute left-next-0 top-next-1_5 bottom-next-1_5 w-next-0_5 rounded-next-full bg-next-primary"
      aria-hidden="true"
    />
    <Icon v-if="icon" :name="icon" class="shrink-0 text-next-lg" />
    <span class="min-w-0 flex-1 truncate">{{ label }}</span>
    <!-- A `#badge` slot is rendered RAW (the consumer owns its chrome, e.g. a
         Badge primitive); the `badge` PROP keeps the built-in muted count pill. -->
    <span v-if="slots.badge" class="ml-auto shrink-0">
      <slot name="badge" />
    </span>
    <span
      v-else-if="badge !== undefined"
      class="ml-auto inline-flex min-w-5 shrink-0 items-center justify-center rounded-next-full bg-next-muted px-next-1_5 text-next-2xs font-next-semibold text-next-muted-foreground group-hover:bg-next-card"
    >
      {{ badge }}
    </span>
  </component>
</template>
