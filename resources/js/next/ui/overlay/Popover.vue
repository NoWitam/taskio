<script lang="ts">
// Attributes (incl. `class`) are forwarded to the teleported panel, not the
// inline trigger wrapper.
export default { inheritAttrs: false };
</script>

<script setup lang="ts">
// Popover — an anchored floating panel for the "next" frontend.
//
// A trigger (default slot) toggles a Teleported panel (#content slot) positioned
// relative to the trigger via `useAnchoredPosition` (anchor rect + placement,
// with a basic viewport flip). It is the positioning base that DropdownMenu (and
// other anchored overlays) build on.
//
// Behavior:
//   - Controllable via `v-model:open`; also opens on trigger click (default) or
//     purely programmatically (`trigger="manual"`).
//   - Outside-click and Escape close it, but only when it is the TOPMOST overlay
//     (via the shared overlay stack) so nested overlays dismiss in order.
//   - Focus moves into the panel on open and returns to the trigger on close.
//
// A11y: the trigger gets `aria-haspopup`, `aria-expanded`, and `aria-controls`;
// the panel has a stable id and `role` (default "dialog"; DropdownMenu overrides
// to "menu"). Reduced motion is handled globally in `.next-root`.
import {
  computed,
  nextTick,
  onBeforeUnmount,
  ref,
  shallowRef,
  watch,
} from 'vue';
import { useOutsideClick } from '../../app/composables/useOutsideClick';
import { useOverlayStack, type OverlayHandle } from '../../app/composables/useOverlayStack';
import {
  useAnchoredPosition,
  type Placement,
} from '../../app/composables/useAnchoredPosition';
import { useTheme } from '../../app/lib/theme';

let popoverSeq = 0;

const props = withDefaults(
  defineProps<{
    /** Preferred placement relative to the trigger. */
    placement?: Placement;
    /** How the popover opens: click the trigger, or only programmatically. */
    trigger?: 'click' | 'manual';
    /** Gap between trigger and panel, in px. */
    offset?: number;
    /** Attempt a viewport flip when the preferred side overflows. */
    flip?: boolean;
    /** ARIA role for the panel (DropdownMenu passes "menu"). */
    role?: string;
    /** Value for the trigger's aria-haspopup. */
    haspopup?: 'menu' | 'dialog' | 'listbox' | 'true';
    /** Move focus into the panel on open (off for menus that manage their own). */
    autoFocus?: boolean;
    /** Match the panel min-width to the trigger width. */
    matchWidth?: boolean;
  }>(),
  {
    placement: 'bottom-start',
    trigger: 'click',
    offset: 8,
    flip: true,
    role: 'dialog',
    haspopup: 'dialog',
    autoFocus: true,
    matchWidth: false,
  },
);

const emit = defineEmits<{
  (e: 'open'): void;
  (e: 'close'): void;
}>();

const open = defineModel<boolean>('open', { default: false });

const { isDark } = useTheme();

const panelId = `next-popover-${(popoverSeq += 1)}`;
const triggerRef = ref<HTMLElement | null>(null);
const panelRef = ref<HTMLElement | null>(null);
const triggerWidth = ref<number>(0);

const { style, update } = useAnchoredPosition(triggerRef, panelRef, {
  placement: () => props.placement,
  gap: props.offset,
  flip: props.flip,
});

const overlay = shallowRef<OverlayHandle | null>(null);
const isTop = computed(() => overlay.value?.isTop.value ?? true);

function bindReposition(): void {
  window.addEventListener('scroll', update, true);
  window.addEventListener('resize', update);
}
function unbindReposition(): void {
  window.removeEventListener('scroll', update, true);
  window.removeEventListener('resize', update);
}

function focusPanel(): void {
  if (!props.autoFocus) return;
  const panel = panelRef.value;
  if (!panel) return;
  const focusable = panel.querySelector<HTMLElement>(
    'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])',
  );
  // preventScroll: a body-teleported panel focus must never scroll-jump (Issue 3).
  (focusable ?? panel).focus({ preventScroll: true });
}

watch(open, async (isOpen) => {
  if (isOpen) {
    triggerWidth.value = triggerRef.value?.offsetWidth ?? 0;
    overlay.value = useOverlayStack({
      kind: 'popover',
      close: () => {
        open.value = false;
      },
    });
    bindReposition();
    await nextTick();
    update();
    // Second pass after layout settles (panel size known).
    requestAnimationFrame(() => {
      update();
      focusPanel();
    });
    emit('open');
  } else {
    unbindReposition();
    overlay.value?.release();
    overlay.value = null;
    emit('close');
  }
});

function toggle(): void {
  open.value = !open.value;
}

function onTriggerClick(): void {
  if (props.trigger === 'click') toggle();
}

// Outside-click only closes when this popover is the topmost overlay.
const outsideActive = computed(() => open.value);
useOutsideClick(
  [triggerRef, panelRef],
  () => {
    if (isTop.value) open.value = false;
  },
  outsideActive,
);

function closeAndRefocus(): void {
  open.value = false;
  nextTick(() => triggerRef.value?.focus?.());
}

onBeforeUnmount(() => {
  unbindReposition();
  overlay.value?.release();
});

defineExpose({
  open,
  close: closeAndRefocus,
  triggerRef,
  panelRef,
  panelId,
  reposition: update,
  isTop: computed(() => isTop.value),
});
</script>

<template>
  <div ref="triggerRef" class="inline-flex" @click="onTriggerClick">
    <!-- Trigger slot. Exposes binding helpers for custom triggers. -->
    <slot
      name="trigger"
      :open="open"
      :toggle="toggle"
      :props="{
        'aria-haspopup': haspopup,
        'aria-expanded': open ? 'true' : 'false',
        'aria-controls': open ? panelId : undefined,
      }"
    >
      <slot
        :open="open"
        :toggle="toggle"
        :props="{
          'aria-haspopup': haspopup,
          'aria-expanded': open ? 'true' : 'false',
          'aria-controls': open ? panelId : undefined,
        }"
      />
    </slot>
  </div>

  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-[var(--duration-next-fast)] ease-[var(--ease-next-emphasized)]"
      enter-from-class="opacity-0 scale-[0.97]"
      leave-active-class="transition duration-[var(--duration-next-fast)] ease-[var(--ease-next-exit)]"
      leave-to-class="opacity-0 scale-[0.97]"
    >
      <div
        v-if="open"
        :id="panelId"
        ref="panelRef"
        class="next-root fixed origin-top z-[var(--z-next-popover)] rounded-next-lg border border-next-border bg-next-popover text-next-popover-foreground shadow-next-lg outline-none"
        :class="[isDark ? 'dark' : '', $attrs.class]"
        :role="role"
        tabindex="-1"
        :style="{
          top: `${style.top}px`,
          left: `${style.left}px`,
          minWidth: matchWidth ? `${triggerWidth}px` : undefined,
        }"
      >
        <slot name="content" :close="closeAndRefocus" />
      </div>
    </Transition>
  </Teleport>
</template>
