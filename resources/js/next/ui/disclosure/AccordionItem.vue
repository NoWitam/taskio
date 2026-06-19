<script setup lang="ts">
// AccordionItem — one collapsible section inside an <Accordion>.
//
// Header: a real <button aria-expanded aria-controls> with a chevron that rotates
// when open. Region: `role="region" aria-labelledby` with a smooth height
// transition (grid-rows trick so content height is auto; collapses cleanly and
// respects reduced motion via the global rule).
//
// States: default / hover / focus-visible / open / disabled. A disabled item is
// non-toggleable and dimmed but stays in the DOM. Keyboard: Up/Down move between
// headers, Home/End jump (delegated to the parent's header registry), Enter/Space
// toggle (native to the <button>).
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Icon, { type IconName } from '../primitives/Icon.vue';
import { useAccordion } from './accordionContext';

const props = withDefaults(
  defineProps<{
    /** Unique value identifying this item within the accordion. */
    value: string;
    /** Header text (use the #header slot for richer content). */
    title?: string;
    /** Optional leading icon in the header. */
    icon?: IconName;
    disabled?: boolean;
  }>(),
  { disabled: false },
);

const ctx = useAccordion();

const uid = `next-accordion-${Math.random().toString(36).slice(2, 8)}`;
const headerId = `${uid}-header`;
const regionId = `${uid}-region`;

const headerRef = ref<HTMLButtonElement | null>(null);

const open = computed(() => ctx?.isOpen(props.value) ?? false);

function onToggle(): void {
  if (props.disabled) return;
  ctx?.toggle(props.value);
}

function onKeydown(event: KeyboardEvent): void {
  if (!ctx) return;
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault();
      ctx.focusMove(props.value, 'next');
      break;
    case 'ArrowUp':
      event.preventDefault();
      ctx.focusMove(props.value, 'prev');
      break;
    case 'Home':
      event.preventDefault();
      ctx.focusMove(props.value, 'first');
      break;
    case 'End':
      event.preventDefault();
      ctx.focusMove(props.value, 'last');
      break;
  }
}

let unregister: (() => void) | undefined;
onMounted(() => {
  unregister = ctx?.register(props.value, headerRef);
});
onBeforeUnmount(() => unregister?.());
</script>

<template>
  <div class="next-accordion-item bg-next-card">
    <h3 class="m-0">
      <button
        :id="headerId"
        ref="headerRef"
        type="button"
        class="next-accordion-item__header flex w-full items-center gap-next-3 px-next-4 py-next-3 text-left text-next-sm font-next-medium text-next-fg transition-colors duration-[var(--duration-next-fast)] outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-next-ring"
        :class="
          disabled
            ? 'cursor-not-allowed text-next-muted-foreground/60'
            : 'cursor-pointer hover:bg-next-accent hover:text-next-accent-foreground'
        "
        :aria-expanded="open"
        :aria-controls="regionId"
        :aria-disabled="disabled || undefined"
        :disabled="disabled"
        @click="onToggle"
        @keydown="onKeydown"
      >
        <Icon v-if="icon" :name="icon" class="shrink-0 text-next-muted-foreground" />
        <span class="min-w-0 flex-1">
          <slot name="header">{{ title }}</slot>
        </span>
        <Icon
          name="chevron-down"
          class="shrink-0 text-next-muted-foreground transition-transform duration-[var(--duration-next-fast)] ease-[var(--ease-next-standard)]"
          :class="open ? 'rotate-180' : ''"
        />
      </button>
    </h3>

    <!-- Collapsible region: grid-rows 0fr→1fr animates height with auto content.
         We use `inert` (not `hidden`) when closed so the height transition still
         runs while collapsed content stays out of the tab order + a11y tree. -->
    <div
      :id="regionId"
      role="region"
      :aria-labelledby="headerId"
      :inert="!open || undefined"
      class="next-accordion-item__region grid transition-[grid-template-rows] duration-[var(--duration-next-normal)] ease-[var(--ease-next-standard)]"
      :class="open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
    >
      <div class="overflow-hidden">
        <div class="px-next-4 pb-next-4 pt-next-1 text-next-sm text-next-muted-foreground">
          <slot />
        </div>
      </div>
    </div>
  </div>
</template>
