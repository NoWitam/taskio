<script setup lang="ts">
// SidebarSection — a labelled, optionally collapsible group of SidebarItems.
//
// Renders a section heading; when `collapsible`, the heading becomes a button
// that toggles the item list with `aria-expanded` + a rotating chevron. The
// label is rendered as a real heading-level button so the section is reachable
// and announced. Non-collapsible sections render a static caption.
import { ref } from 'vue';
import Icon from '../primitives/Icon.vue';

const props = withDefaults(
  defineProps<{
    label: string;
    collapsible?: boolean;
    /** Initial open state when collapsible. */
    defaultOpen?: boolean;
  }>(),
  {
    collapsible: false,
    defaultOpen: true,
  },
);

const open = ref(props.defaultOpen);
const contentId = `next-sidebar-section-${Math.random().toString(36).slice(2, 8)}`;
</script>

<template>
  <div class="flex flex-col gap-next-1">
    <button
      v-if="collapsible"
      type="button"
      class="flex w-full items-center justify-between rounded-next-md px-next-2_5 py-next-1 text-next-2xs font-next-semibold uppercase tracking-next-wide text-next-muted-foreground hover:text-next-fg"
      :aria-expanded="open"
      :aria-controls="contentId"
      @click="open = !open"
    >
      <span>{{ label }}</span>
      <Icon
        name="chevron-down"
        class="text-next-sm transition-transform duration-[var(--duration-next-fast)]"
        :class="open ? '' : '-rotate-90'"
      />
    </button>
    <p
      v-else
      class="px-next-2_5 py-next-1 text-next-2xs font-next-semibold uppercase tracking-next-wide text-next-muted-foreground"
    >
      {{ label }}
    </p>

    <div v-show="!collapsible || open" :id="contentId" class="flex flex-col gap-next-px">
      <slot />
    </div>
  </div>
</template>
