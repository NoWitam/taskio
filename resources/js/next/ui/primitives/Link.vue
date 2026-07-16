<script setup lang="ts">
// Link primitive for the "next" frontend.
//
// A real <a href> for navigation. Never use this for an action that mutates
// state — use a Button for that. Variants change tone/emphasis only; dark mode
// is token-driven.
//
// External links (`external` or a target of `_blank`) get `rel="noopener
// noreferrer"`, a trailing external-link icon, and a visually-hidden
// "(opens in new tab)" hint so the new-tab behavior is announced.
//
// Disabled: anchors can't be natively disabled, so a disabled link drops its
// `href`, sets `aria-disabled`, removes it from the tab order, and prevents
// activation.
import { computed } from 'vue';
import Icon from './Icon.vue';

type LinkVariant = 'default' | 'muted' | 'standalone';

const props = withDefaults(
  defineProps<{
    href: string;
    variant?: LinkVariant;
    /** Marks the link as external: adds icon + rel + new-tab announcement. */
    external?: boolean;
    target?: string;
    disabled?: boolean;
  }>(),
  {
    variant: 'default',
    external: false,
    disabled: false,
  },
);

const emit = defineEmits<{ (e: 'click', event: MouseEvent): void }>();

const isExternal = computed(() => props.external || props.target === '_blank');
const resolvedTarget = computed(() =>
  props.target ?? (props.external ? '_blank' : undefined),
);

const VARIANT_CLASS: Record<LinkVariant, string> = {
  default:
    'text-next-primary underline-offset-4 hover:underline focus-visible:underline',
  muted:
    'text-next-muted-foreground underline-offset-4 hover:text-next-fg hover:underline',
  // `standalone` is a self-contained link (often a row/CTA), medium weight.
  standalone:
    'inline-flex items-center gap-next-1 font-next-medium text-next-primary underline-offset-4 hover:underline',
};

const classes = computed(() => [
  'next-link rounded-next-sm transition-colors duration-[var(--duration-next-fast)]',
  VARIANT_CLASS[props.variant],
  props.disabled
    ? 'pointer-events-none cursor-not-allowed text-next-muted-foreground opacity-60 no-underline'
    : 'cursor-pointer',
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
  <a
    :class="classes"
    :href="disabled ? undefined : href"
    :target="disabled ? undefined : resolvedTarget"
    :rel="isExternal ? 'noopener noreferrer' : undefined"
    :aria-disabled="disabled ? 'true' : undefined"
    :tabindex="disabled ? -1 : undefined"
    @click="onClick"
  >
    <slot />
    <Icon
      v-if="isExternal && !disabled"
      name="external-link"
      class="text-[0.85em]"
    />
    <span v-if="isExternal && !disabled" class="sr-only"> (opens in new tab)</span>
  </a>
</template>

<style scoped>
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
