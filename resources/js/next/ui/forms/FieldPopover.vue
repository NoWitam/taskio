<script setup lang="ts">
// FieldPopover — a small anchored dropdown panel for the "next" form controls.
//
// The TRIGGER is whatever the caller renders in the default `#trigger` slot
// (typically a FieldShell-wrapped input). The PANEL (the `#default` slot) is
// TELEPORTED to <body> (the `.next-overlay-root` convention) and positioned with
// `useAnchoredPosition` against the trigger, on the `--z-next-popover` layer, with
// token-driven enter/leave motion.
//
// Teleporting is deliberate (Issue 2c): an absolutely-positioned panel was clipped
// by any ancestor with `overflow: hidden`/`clip` (resizable boxes, scroll areas).
// Anchoring to <body> floats it above the page; EVERY consumer (Date/Time/
// DateTime/DateRange/Month pickers, ColorInput, IconInput, PillGroupInput
// suggestions) is fixed at once by this single change.
//
// It owns ONLY the open/close mechanics shared by every picker:
//   • outside-click + Esc close (Esc returns focus to the trigger). Outside-click
//     treats clicks inside the TELEPORTED panel as "inside" (both refs are passed
//     to useOutsideClick), so interacting with the panel never closes it.
//   • focus management (focus the panel on open when `autofocus`; restore the
//     trigger on close). All focus moves use `{ preventScroll: true }` so moving
//     focus into a body-teleported panel never scroll-jumps the page (Issue 3).
//   • anchored positioning kept in sync on scroll/resize while open,
//   • a token-driven enter/leave transition,
//   • two-way `v-model:open` so the host component drives/observes open state.
//
// It is intentionally self-contained and reusable (no picker-specific logic).
// Keyboard navigation WITHIN the panel is the picker's responsibility; this just
// guarantees Esc/outside-click/return-focus behave consistently everywhere.
import { nextTick, ref, watch } from 'vue';
import { useOutsideClick } from '../../app/composables/useOutsideClick';
import { useAnchoredPosition } from '../../app/composables/useAnchoredPosition';
import { useTheme } from '../../app/lib/theme';

const props = withDefaults(
  defineProps<{
    /** Disable opening entirely (mirrors the field's disabled/readonly). */
    disabled?: boolean;
    /** Horizontal alignment of the panel against the trigger. */
    align?: 'start' | 'end';
    /** Accessible label for the panel dialog. */
    ariaLabel?: string;
    /** id used to wire the trigger's aria-controls to the panel. */
    panelId?: string;
    /** Move focus into the panel on open (false for typeable pickers). */
    autofocus?: boolean;
    /**
     * Match the panel's min-width to the trigger's width (menus/listboxes that
     * should be at least as wide as the field). The panel still grows past it via
     * its own `w-max`/`max-w` so long content isn't clipped.
     */
    matchTriggerWidth?: boolean;
  }>(),
  { disabled: false, align: 'start', autofocus: false, matchTriggerWidth: false },
);

const open = defineModel<boolean>('open', { default: false });

const { isDark } = useTheme();

const wrapperRef = ref<HTMLElement | null>(null);
const triggerRef = ref<HTMLElement | null>(null);
const panelRef = ref<HTMLElement | null>(null);
const triggerWidth = ref(0);

// Anchored positioning: panel floats below the trigger (flips up near the bottom).
const { style: anchorStyle, update: updatePosition } = useAnchoredPosition(
  triggerRef,
  panelRef,
  {
    placement: () => (props.align === 'end' ? 'bottom-end' : 'bottom-start'),
    gap: 4,
    flip: true,
  },
);

function reposition(): void {
  if (triggerRef.value) triggerWidth.value = triggerRef.value.offsetWidth;
  updatePosition();
}

function focusTrigger(): void {
  const trigger = triggerRef.value?.querySelector<HTMLElement>(
    'input, button, [tabindex]',
  );
  (trigger ?? triggerRef.value)?.focus({ preventScroll: true });
}

function openPanel(): void {
  if (props.disabled || open.value) return;
  open.value = true;
}
function closePanel(returnFocus = true): void {
  if (!open.value) return;
  open.value = false;
  if (returnFocus) nextTick(focusTrigger);
}
function toggle(): void {
  open.value ? closePanel() : openPanel();
}

// Esc closes + returns focus; outside-click closes without stealing focus back.
//
// DELIBERATELY NOT in `useOverlayStack` (unlike Modal/Drawer/Popover/Select): this handler is bound
// to the panel, so it only ever fires while DOM focus is INSIDE the panel. A control that IS in the
// stack and can be open here (a `Select` list) takes focus into its own body-teleported list, so the
// two are never the target of the same press — the stack closes the list first and returns focus
// here, and the NEXT Escape reaches this handler. Registering would add an entry to the stack's
// ordering/z-index semantics that nothing needs.
function onPanelKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.preventDefault();
    event.stopPropagation();
    closePanel(true);
  }
}

// Outside-click: clicks inside the trigger wrapper OR the teleported panel count
// as "inside" so interacting with the floating panel never closes it.
useOutsideClick([wrapperRef, panelRef], () => closePanel(false), open);

// Keep the panel anchored while open; position BEFORE moving focus so a
// body-teleported panel never scroll-jumps, then focus with preventScroll.
watch(open, async (isOpen) => {
  if (isOpen) {
    window.addEventListener('scroll', updatePosition, true);
    window.addEventListener('resize', updatePosition);
    await nextTick();
    reposition();
    // A second pass after layout settles (fonts/content) keeps it pixel-accurate.
    requestAnimationFrame(reposition);
    if (props.autofocus) panelRef.value?.focus({ preventScroll: true });
  } else {
    window.removeEventListener('scroll', updatePosition, true);
    window.removeEventListener('resize', updatePosition);
  }
});

// Expose imperative controls so a host (e.g. a typeable input) can open/close.
defineExpose({ openPanel, closePanel, toggle });
</script>

<template>
  <div ref="wrapperRef" class="relative w-full">
    <!-- Trigger: rendered by the caller; gets open + the open/close handlers. -->
    <div ref="triggerRef">
      <slot
        name="trigger"
        :open="open"
        :toggle="toggle"
        :open-panel="openPanel"
        :close-panel="closePanel"
      />
    </div>

    <!-- Panel: teleported to <body> so overflow-clipping ancestors can't trap it.
         Positioned via useAnchoredPosition against the trigger. -->
    <Teleport to="body">
      <!-- Opacity-only: a transform on this wrapper would become the containing
           block for the `fixed` panel (CSS spec) and pin it to the bottom of
           <body> mid-animation, scroll-jumping the page. Never add translate. -->
      <Transition
        enter-active-class="transition-opacity duration-[var(--duration-next-fast)] ease-[var(--ease-next-emphasized)]"
        enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-[var(--duration-next-fast)] ease-[var(--ease-next-exit)]"
        leave-to-class="opacity-0"
      >
        <div
          v-if="open"
          class="next-root next-overlay-root"
          :class="isDark ? 'dark' : ''"
        >
          <div
            :id="panelId"
            ref="panelRef"
            role="dialog"
            :aria-label="ariaLabel"
            tabindex="-1"
            class="fixed z-[var(--z-next-popover)] w-max max-w-[min(92vw,40rem)] rounded-next-lg border border-next-border bg-next-popover text-next-popover-foreground shadow-next-lg outline-none"
            :style="{
              top: `${anchorStyle.top}px`,
              left: `${anchorStyle.left}px`,
              minWidth: matchTriggerWidth ? `${triggerWidth}px` : undefined,
            }"
            @keydown="onPanelKeydown"
          >
            <slot :close-panel="closePanel" />
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
