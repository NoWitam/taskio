<script setup lang="ts">
// Card primitive for the "next" frontend.
//
// A themed content container built on Surface, with optional header / body /
// footer regions (named slots) and a small variant + state matrix. All color is
// token-driven; dark mode is automatic.
//
// Interactive cards (variant `interactive`, or any card given `href`/`as` +
// click intent) expose a SINGLE accessible action via the "stretched link"
// pattern: one real <a>/<button> sits in the header region and a pseudo-element
// stretches its hit target over the whole card. This keeps exactly one tab stop
// and one accessible name for the whole-card action — nested interactive
// controls (which would compete for the click and add tab stops) are an
// anti-pattern here and should live OUTSIDE the card or use a non-interactive
// card.
//
// States: default · hover (interactive) · focus-visible (interactive, ring on
// the card via :focus-within) · selected (accent ring + tint) · disabled
// (dimmed + inert) · loading (skeleton body, aria-busy) · empty (slot for an
// EmptyState placeholder; a simple inline placeholder ships here until the real
// Tier 5 EmptyState exists).
import { computed, useSlots } from 'vue';
import Surface from './Surface.vue';

type CardVariant = 'default' | 'elevated' | 'interactive' | 'muted' | 'inset';

const props = withDefaults(
  defineProps<{
    variant?: CardVariant;
    /** Visually + behaviorally selected (accent ring + tint). */
    selected?: boolean;
    /** Dim + make the card inert (also disables the interactive action). */
    disabled?: boolean;
    /** Show a skeleton in the body region; sets aria-busy. */
    loading?: boolean;
    /** Show the empty-state placeholder slot instead of the body. */
    empty?: boolean;
    /**
     * The host has COLLAPSED the body itself (typically `v-show` on its own wrapper, so the body
     * children stay MOUNTED and any `aria-controls` target keeps resolving). The body region still
     * renders — it just drops its padding, and the header drops its divider, so a collapsed card is a
     * clean header (+ footer) instead of a header over an empty padded band. Purely presentational:
     * Card never hides anything itself.
     */
    bodyCollapsed?: boolean;

    // --- Interactive (whole-card action) ---
    /** Element for the whole-card action when interactive: anchor or button. */
    as?: 'a' | 'button';
    /** Href for the whole-card link (forces `as="a"`). */
    href?: string;
    /** Anchor target; `_blank` adds rel="noopener noreferrer". */
    target?: string;
    /**
     * Accessible name for the whole-card action. Required when the action's
     * visible label isn't obvious from the header text.
     */
    actionLabel?: string;
  }>(),
  {
    variant: 'default',
    selected: false,
    disabled: false,
    loading: false,
    empty: false,
    bodyCollapsed: false,
  },
);

const emit = defineEmits<{ (e: 'activate', event: MouseEvent): void }>();

const slots = useSlots();

const isInteractive = computed(
  () => props.variant === 'interactive' || props.href !== undefined || props.as !== undefined,
);
const actionTag = computed<'a' | 'button'>(() =>
  props.href !== undefined ? 'a' : props.as ?? 'button',
);
const isInert = computed(() => props.disabled);

// Surface props per variant.
const surfaceBg = computed(() => (props.variant === 'muted' ? 'muted' : 'card'));
const surfaceElevation = computed(() => {
  if (props.variant === 'elevated') return 'md';
  if (props.variant === 'inset') return 'none';
  return 'sm';
});
const surfaceBorder = computed(() => props.variant !== 'elevated');

const surfaceClasses = computed(() => [
  'next-card relative flex flex-col',
  // Interactive affordance: an OBVIOUS clickable treatment — pointer cursor on the
  // whole card, a clearly stronger hover (primary-tinted border + shadow lift + a
  // subtle upward translate), and a focus-visible ring via :focus-within so keyboard
  // focus on the inner action highlights the whole card. Transitions cover the
  // colors/shadow/transform; reduced motion is handled globally in `.next-root`.
  isInteractive.value && !isInert.value
    ? 'cursor-pointer transition-[transform,box-shadow,border-color] ' +
      'duration-[var(--duration-next-fast)] ease-[var(--ease-next-standard)] ' +
      'hover:-translate-y-0.5 hover:shadow-next-md hover:border-next-primary ' +
      'focus-within:outline focus-within:outline-2 ' +
      'focus-within:outline-offset-2 focus-within:[outline-color:var(--color-next-ring)]'
    : '',
  // Selected: accent ring + faint tint (ring is shape + color, not color alone).
  props.selected
    ? 'outline outline-2 [outline-color:var(--color-next-primary)] bg-next-primary-subtle'
    : '',
  isInert.value ? 'opacity-60' : '',
]);

// `inset`/`flush` cards drop their own padding so they can host full-bleed media
// or a nested list; default cards pad their regions.
const padded = computed(() => props.variant !== 'inset');

function onActivate(event: MouseEvent): void {
  if (isInert.value) {
    event.preventDefault();
    event.stopPropagation();
    return;
  }
  emit('activate', event);
}

const hasFooter = computed(() => !!slots.footer);
const hasHeader = computed(() => !!slots.header);
</script>

<template>
  <Surface
    :bg="surfaceBg"
    :border="surfaceBorder"
    :elevation="surfaceElevation"
    radius="lg"
    :class="surfaceClasses"
    :aria-busy="loading ? 'true' : undefined"
    :aria-disabled="isInert ? 'true' : undefined"
  >
    <!-- Header region. For interactive cards this hosts the single stretched
         action; the ::after pseudo-element (see <style>) covers the whole card. -->
    <header
      v-if="hasHeader"
      :class="[
        'next-card__header flex items-start justify-between gap-next-3',
        padded ? 'p-next-4' : '',
        (loading || empty || $slots.default) && !bodyCollapsed ? 'border-b border-next-border' : '',
      ]"
    >
      <component
        :is="actionTag"
        v-if="isInteractive"
        class="next-card__action min-w-0 flex-1 text-left rounded-next-sm"
        :href="actionTag === 'a' && !isInert ? href : undefined"
        :target="actionTag === 'a' ? target : undefined"
        :rel="actionTag === 'a' && target === '_blank' ? 'noopener noreferrer' : undefined"
        :type="actionTag === 'button' ? 'button' : undefined"
        :disabled="actionTag === 'button' && isInert ? true : undefined"
        :aria-disabled="isInert ? 'true' : undefined"
        :aria-label="actionLabel"
        @click="onActivate"
      >
        <slot name="header" />
      </component>
      <div v-else class="min-w-0 flex-1">
        <slot name="header" />
      </div>

      <!-- Optional non-stretched header actions (kept above the stretched link
           via z-index so they remain independently clickable). -->
      <div v-if="$slots.headerActions" class="next-card__header-actions relative shrink-0">
        <slot name="headerActions" />
      </div>
    </header>

    <!-- Body region: loading skeleton, empty placeholder, or default content.
         Skipped entirely when there is no body content (so header-only / footer-only
         cards don't render an empty padded band). `bodyCollapsed` keeps the region
         (and its mounted children) but drops the padding for the same reason. -->
    <div
      v-if="loading || empty || $slots.default"
      :class="['next-card__body min-w-0', padded && !bodyCollapsed ? 'p-next-4' : '']"
    >
      <template v-if="loading">
        <div class="flex flex-col gap-next-3" aria-hidden="true">
          <div class="h-4 w-2/3 animate-pulse rounded-next-sm bg-next-muted" />
          <div class="h-3 w-full animate-pulse rounded-next-sm bg-next-muted" />
          <div class="h-3 w-5/6 animate-pulse rounded-next-sm bg-next-muted" />
          <div class="h-3 w-1/2 animate-pulse rounded-next-sm bg-next-muted" />
        </div>
        <span class="sr-only">Loading…</span>
      </template>

      <template v-else-if="empty">
        <!-- Placeholder until the real Tier 5 EmptyState exists. -->
        <slot name="empty">
          <div
            class="flex flex-col items-center gap-next-1 py-next-8 text-center text-next-muted-foreground"
          >
            <p class="text-next-sm font-next-medium">Nothing here yet</p>
            <p class="text-next-xs">Items will appear in this card once added.</p>
          </div>
        </slot>
      </template>

      <slot v-else />
    </div>

    <!-- Footer / metadata region (consistent placement per UX rules). -->
    <footer
      v-if="hasFooter"
      :class="[
        'next-card__footer flex items-center gap-next-3 border-t border-next-border text-next-sm text-next-muted-foreground',
        padded ? 'p-next-4' : '',
      ]"
    >
      <slot name="footer" />
    </footer>
  </Surface>
</template>

<style scoped>
/* Stretched-link: the single interactive action's hit target covers the whole
   card, so the entire card is clickable with exactly one tab stop / accessible
   name. Header actions (.next-card__header-actions) sit above it via z-index so
   they stay independently usable. */
.next-card__action::after {
  content: '';
  position: absolute;
  inset: 0;
  z-index: 1;
}

.next-card__header-actions {
  z-index: 2;
}

/* The native focus ring on the inner action is redundant (we ring the whole
   card via :focus-within), so suppress it to avoid a double ring. */
.next-card__action:focus-visible {
  outline: none;
}

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
