<script lang="ts">
export default { inheritAttrs: false };
</script>

<script setup lang="ts">
// Tooltip — a hover/focus label for the "next" frontend.
//
// Opens on pointer hover AND keyboard focus of the trigger (so keyboard users
// get the same hint), after a configurable delay; closes on leave/blur or
// Escape. It is purely descriptive: it NEVER traps focus and must not contain
// interactive content. The label is Teleported to the body and positioned with
// `useAnchoredPosition`.
//
// A11y: the floating element has `role="tooltip"` and a stable id; the trigger
// is wired via `aria-describedby` so screen readers announce the hint with the
// control. It rides at `--z-next-tooltip` (above everything). Touch: a tap holds
// the tooltip briefly then dismisses, since there is no hover on touch.
import {
  nextTick,
  onBeforeUnmount,
  ref,
  watch,
} from 'vue';
import {
  useAnchoredPosition,
  type Placement,
} from '../../app/composables/useAnchoredPosition';
import { useTheme } from '../../app/lib/theme';

let tooltipSeq = 0;

const props = withDefaults(
  defineProps<{
    /** Tooltip text (use the #content slot for richer, still non-interactive, content). */
    label?: string;
    placement?: Placement;
    /** Show delay in ms (hover/focus). */
    openDelay?: number;
    /** Hide delay in ms. */
    closeDelay?: number;
    /** Gap between trigger and tooltip, in px. */
    offset?: number;
    /** Disable the tooltip entirely (renders the trigger only). */
    disabled?: boolean;
  }>(),
  {
    placement: 'top',
    openDelay: 150,
    closeDelay: 0,
    offset: 6,
    disabled: false,
  },
);

const { isDark } = useTheme();

const tooltipId = `next-tooltip-${(tooltipSeq += 1)}`;
const open = ref(false);
const triggerRef = ref<HTMLElement | null>(null);
const tipRef = ref<HTMLElement | null>(null);

const { style, update } = useAnchoredPosition(triggerRef, tipRef, {
  placement: () => props.placement,
  gap: props.offset,
  flip: true,
});

let openTimer: ReturnType<typeof setTimeout> | undefined;
let closeTimer: ReturnType<typeof setTimeout> | undefined;

function clearTimers(): void {
  if (openTimer) clearTimeout(openTimer);
  if (closeTimer) clearTimeout(closeTimer);
}

function show(): void {
  if (props.disabled || !props.label) return;
  clearTimers();
  openTimer = setTimeout(() => {
    open.value = true;
  }, props.openDelay);
}

function hide(): void {
  clearTimers();
  closeTimer = setTimeout(() => {
    open.value = false;
  }, props.closeDelay);
}

function hideNow(): void {
  clearTimers();
  open.value = false;
}

function bindReposition(): void {
  window.addEventListener('scroll', update, true);
  window.addEventListener('resize', update);
}
function unbindReposition(): void {
  window.removeEventListener('scroll', update, true);
  window.removeEventListener('resize', update);
}

watch(open, async (isOpen) => {
  if (isOpen) {
    bindReposition();
    await nextTick();
    update();
    requestAnimationFrame(update);
  } else {
    unbindReposition();
  }
});

function onKeydown(event: KeyboardEvent): void {
  // Escape dismisses an open tooltip without affecting other overlays.
  if (event.key === 'Escape' && open.value) {
    hideNow();
  }
}

onBeforeUnmount(() => {
  clearTimers();
  unbindReposition();
});
</script>

<template>
  <span
    ref="triggerRef"
    class="inline-flex"
    :aria-describedby="open ? tooltipId : undefined"
    @pointerenter="show"
    @pointerleave="hide"
    @focusin="show"
    @focusout="hide"
    @keydown="onKeydown"
  >
    <slot />
  </span>

  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-[var(--duration-next-fast)] ease-[var(--ease-next-emphasized)]"
      enter-from-class="opacity-0 scale-[0.96]"
      leave-active-class="transition duration-[var(--duration-next-fast)] ease-[var(--ease-next-exit)]"
      leave-to-class="opacity-0 scale-[0.96]"
    >
      <div
        v-if="open"
        :id="tooltipId"
        ref="tipRef"
        role="tooltip"
        class="next-root pointer-events-none fixed z-[var(--z-next-tooltip)] max-w-xs rounded-next-md bg-next-fg px-next-2 py-next-1 text-next-xs font-next-medium text-next-bg shadow-next-md"
        :class="[isDark ? 'dark' : '', $attrs.class]"
        :style="{ top: `${style.top}px`, left: `${style.left}px` }"
      >
        <slot name="content">{{ label }}</slot>
      </div>
    </Transition>
  </Teleport>
</template>
