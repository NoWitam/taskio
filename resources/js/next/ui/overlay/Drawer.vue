<script lang="ts">
export default { inheritAttrs: false };
</script>

<script setup lang="ts">
// Drawer (a.k.a. Sheet) — a Teleported, focus-trapped side panel for the "next"
// frontend. It MIRRORS Modal.vue's overlay mechanics exactly:
//
//   - scrim (`--color-next-overlay`, `--z-next-overlay`) + panel (`--z-next-modal`)
//   - `useFocusTrap` for focus containment + restore-on-close
//   - the shared `useOverlayStack` so Esc / scrim dismiss the TOPMOST overlay only
//     (a Drawer opened above a Modal stacks + dismisses in order, z-index bumped
//      by `depth`)
//   - reference-counted body-scroll lock (shared counter with Modal)
//
// Difference from Modal: the panel slides in from a `side` (left|right|top|bottom)
// and is pinned to that edge instead of centered. Sizes set the cross-axis extent.
//
// CRITICAL TELEPORT RULE: the teleported wrapper holds a `position: fixed` child,
// so we NEVER put a transform-based transition on that wrapper. The scrim animates
// opacity ONLY. The slide transform is applied to the PANEL itself (which is not a
// teleport wrapper containing a fixed child), so it is safe.
//
// A11y: `role="dialog"`, `aria-modal="true"`, labelled by the title id and
// described by the description id when those slots are present. Enter/leave
// motion uses the slow motion token; reduced motion is handled globally.
import {
  computed,
  nextTick,
  onBeforeUnmount,
  ref,
  shallowRef,
  watch,
} from 'vue';
import { useFocusTrap } from '../../app/composables/useFocusTrap';
import { useOverlayStack, type OverlayHandle } from '../../app/composables/useOverlayStack';
import { useTheme } from '../../app/lib/theme';
import { useI18n } from '../../app/i18n';
import Icon from '../primitives/Icon.vue';
import Button from '../primitives/Button.vue';

type DrawerSide = 'left' | 'right' | 'top' | 'bottom';
type DrawerSize = 'sm' | 'md' | 'lg' | 'xl' | '2xl' | 'cover' | 'full';

const props = withDefaults(
  defineProps<{
    /** Edge the panel slides in from. */
    side?: DrawerSide;
    /** Cross-axis extent (width for left/right, height for top/bottom). */
    size?: DrawerSize;
    /** Close when Escape is pressed (topmost only). */
    closeOnEsc?: boolean;
    /** Close when the scrim is clicked (topmost only). */
    closeOnScrim?: boolean;
    /** Show the built-in close (✕) button in the header. */
    showClose?: boolean;
    /** Accessible label when no visible #title slot is provided. */
    ariaLabel?: string;
    /**
     * Float the panel with a comfortable gap from ALL viewport edges (inset
     * margin + rounded corners on every corner) instead of sitting flush against
     * its edge. Defaults to false (edge-pinned, inner-corner rounding only).
     */
    floating?: boolean;
    /**
     * Whether the body owns a single vertical scroll region (default). Set to
     * false when the slotted content manages its OWN scroll areas (e.g. a
     * multi-column workspace with independently scrolling panes) so the body
     * stays a fixed-height, non-scrolling flex container.
     */
    scrollBody?: boolean;
  }>(),
  {
    side: 'right',
    size: 'md',
    closeOnEsc: true,
    closeOnScrim: true,
    showClose: true,
    // Always detached from the screen edges (a consistent gap) by default, like
    // the Tasks detail drawer; pass `:floating="false"` for an edge-pinned sheet.
    floating: true,
    scrollBody: true,
  },
);

const emit = defineEmits<{
  (e: 'open'): void;
  (e: 'close'): void;
}>();

const { t } = useI18n();

const open = defineModel<boolean>('open', { default: false });

const { isDark } = useTheme();

let drawerSeq = 0;
const uid = `next-drawer-${(drawerSeq += 1)}-${Math.random().toString(36).slice(2, 6)}`;
const titleId = `${uid}-title`;
const descId = `${uid}-desc`;

const panelRef = ref<HTMLElement | null>(null);
const hasTitle = ref(false);
const hasDesc = ref(false);

const overlay = shallowRef<OverlayHandle | null>(null);
const isTop = computed(() => overlay.value?.isTop.value ?? true);
const depth = computed(() => overlay.value?.depth.value ?? 0);

// Focus trap reuse: active while open.
const trapActive = computed(() => open.value);
useFocusTrap(panelRef, trapActive);

// Cross-axis size. For left/right it's a width; for top/bottom it's a height.
const SIZE_W: Record<DrawerSize, string> = {
  sm: 'w-[18rem]',
  md: 'w-[24rem]',
  lg: 'w-[32rem]',
  xl: 'w-[42rem]',
  '2xl': 'w-[50rem]',
  // Cover most of the screen but keep the app navigation visible on the side.
  cover: 'w-[calc(100vw-2rem)] next-md:w-[calc(100vw-18rem)]',
  full: 'w-screen',
};
const SIZE_H: Record<DrawerSize, string> = {
  sm: 'h-[14rem]',
  md: 'h-[20rem]',
  lg: 'h-[28rem]',
  xl: 'h-[36rem]',
  '2xl': 'h-[44rem]',
  cover: 'h-[calc(100vh-2rem)]',
  full: 'h-screen',
};

// Pin the panel to its edge + cap its cross-axis so it never exceeds the viewport.
// When `floating`, leave a gap from every edge (margin) so the panel never touches
// the screen edges; the main-axis size becomes `auto` (inset on both ends).
const panelLayout = computed(() => {
  if (props.floating) {
    // A consistent gap from all edges; the panel still hugs its `side` for the
    // slide-in direction but is detached from every edge by the inset margin.
    const gap = 'inset-next-4';
    switch (props.side) {
      case 'left':
        return [`${gap} right-auto max-w-[calc(100vw-2rem)]`, SIZE_W[props.size]];
      case 'right':
        return [`${gap} left-auto max-w-[calc(100vw-2rem)]`, SIZE_W[props.size]];
      case 'top':
        return [`${gap} bottom-auto max-h-[calc(100vh-2rem)]`, SIZE_H[props.size]];
      case 'bottom':
        return [`${gap} top-auto max-h-[calc(100vh-2rem)]`, SIZE_H[props.size]];
      default:
        return [''];
    }
  }
  switch (props.side) {
    case 'left':
      return ['inset-y-0 left-0 h-full max-w-[calc(100vw-2rem)]', SIZE_W[props.size]];
    case 'right':
      return ['inset-y-0 right-0 h-full max-w-[calc(100vw-2rem)]', SIZE_W[props.size]];
    case 'top':
      return ['inset-x-0 top-0 w-full max-h-[calc(100vh-2rem)]', SIZE_H[props.size]];
    case 'bottom':
      return ['inset-x-0 bottom-0 w-full max-h-[calc(100vh-2rem)]', SIZE_H[props.size]];
    default:
      return [''];
  }
});

// Edge-pinned: round only the inner corners (the edge it sits against stays
// flush). Floating: round every corner since it's detached from all edges.
const ROUNDING: Record<DrawerSide, string> = {
  left: 'rounded-r-next-xl',
  right: 'rounded-l-next-xl',
  top: 'rounded-b-next-xl',
  bottom: 'rounded-t-next-xl',
};
const rounding = computed(() =>
  props.floating ? 'rounded-next-xl' : ROUNDING[props.side],
);

// Slide transform applied to the PANEL (safe — not a teleport wrapper).
const TRANSFORM_HIDDEN: Record<DrawerSide, string> = {
  left: '-translate-x-full',
  right: 'translate-x-full',
  top: '-translate-y-full',
  bottom: 'translate-y-full',
};

// --- Body scroll lock (shares Modal's reference-counted counter) -------------
function lockBody(): void {
  const count = Number(document.body.dataset.nextModalLocks ?? '0') + 1;
  document.body.dataset.nextModalLocks = String(count);
  if (count === 1) {
    document.body.dataset.nextPrevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
  }
}
function unlockBody(): void {
  const count = Math.max(0, Number(document.body.dataset.nextModalLocks ?? '1') - 1);
  document.body.dataset.nextModalLocks = String(count);
  if (count === 0) {
    document.body.style.overflow = document.body.dataset.nextPrevOverflow ?? '';
    delete document.body.dataset.nextPrevOverflow;
    delete document.body.dataset.nextModalLocks;
  }
}

watch(open, (isOpen) => {
  if (isOpen) {
    overlay.value = useOverlayStack({
      kind: 'modal',
      close: () => {
        if (props.closeOnEsc) open.value = false;
      },
      dismissable: () => props.closeOnEsc,
    });
    lockBody();
    nextTick(() => {
      hasTitle.value = !!panelRef.value?.querySelector('[data-drawer-title]');
      hasDesc.value = !!panelRef.value?.querySelector('[data-drawer-desc]');
    });
    emit('open');
  } else {
    overlay.value?.release();
    overlay.value = null;
    unlockBody();
    emit('close');
  }
});

function requestClose(): void {
  open.value = false;
}

function onScrimClick(): void {
  if (props.closeOnScrim && isTop.value) requestClose();
}

const labelledBy = computed(() => (hasTitle.value ? titleId : undefined));
const describedBy = computed(() => (hasDesc.value ? descId : undefined));

onBeforeUnmount(() => {
  if (open.value) {
    overlay.value?.release();
    unlockBody();
  }
});
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="next-root next-overlay-root fixed inset-0"
      :class="isDark ? 'dark' : ''"
      :style="{ zIndex: `calc(var(--z-next-overlay) + ${depth * 10})` }"
    >
      <!-- Scrim — opacity-only transition (CRITICAL: never transform this
           teleported wrapper / a fixed child). -->
      <Transition
        appear
        enter-active-class="transition-opacity duration-[var(--duration-next-normal)] ease-[var(--ease-next-standard)]"
        enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-[var(--duration-next-fast)] ease-[var(--ease-next-exit)]"
        leave-to-class="opacity-0"
      >
        <div
          class="absolute inset-0 bg-[var(--color-next-overlay)]"
          aria-hidden="true"
          @click="onScrimClick"
        />
      </Transition>

      <!-- Panel — slide transform lives HERE (a real panel, not a teleport
           wrapper), so the transform is allowed. -->
      <Transition
        appear
        :enter-active-class="`transition-transform duration-[var(--duration-next-slow)] ease-[var(--ease-next-emphasized)]`"
        :enter-from-class="TRANSFORM_HIDDEN[side]"
        :leave-active-class="`transition-transform duration-[var(--duration-next-normal)] ease-[var(--ease-next-exit)]`"
        :leave-to-class="TRANSFORM_HIDDEN[side]"
      >
        <div
          v-if="open"
          ref="panelRef"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="labelledBy"
          :aria-describedby="describedBy"
          :aria-label="!hasTitle ? ariaLabel : undefined"
          tabindex="-1"
          class="next-drawer absolute z-[var(--z-next-modal)] flex flex-col border-next-border bg-next-card text-next-card-foreground shadow-next-xl outline-none"
          :class="[
            panelLayout,
            rounding,
            // Floating: full border on all sides; pinned: only the inner edge.
            floating ? 'border' : '',
            !floating && side === 'left' ? 'border-r' : '',
            !floating && side === 'right' ? 'border-l' : '',
            !floating && side === 'top' ? 'border-b' : '',
            !floating && side === 'bottom' ? 'border-t' : '',
            $attrs.class,
          ]"
        >
          <!-- Header -->
          <header
            v-if="$slots.title || showClose"
            class="flex items-start justify-between gap-next-3 border-b border-next-border p-next-4"
          >
            <div v-if="$slots.title" :id="titleId" data-drawer-title class="min-w-0 flex-1 text-next-lg font-next-semibold">
              <slot name="title" />
            </div>
            <div v-else class="flex-1" />
            <Button
              v-if="showClose"
              variant="outline"
              size="icon-sm"
              class="-mr-next-1 -mt-next-1 shrink-0"
              :aria-label="t('drawer.close', 'Close panel')"
              @click="requestClose"
            >
              <Icon name="x" class="text-next-xl" />
            </Button>
          </header>

          <!-- Body -->
          <div
            class="min-w-0 flex-1 p-next-4"
            :class="scrollBody ? 'overflow-y-auto' : 'flex min-h-0 flex-col overflow-hidden'"
          >
            <div v-if="$slots.description" :id="descId" data-drawer-desc class="mb-next-3 text-next-sm text-next-muted-foreground">
              <slot name="description" />
            </div>
            <slot />
          </div>

          <!-- Footer -->
          <footer
            v-if="$slots.footer"
            class="flex items-center justify-end gap-next-2 border-t border-next-border p-next-4"
          >
            <slot name="footer" :close="requestClose" />
          </footer>
        </div>
      </Transition>
    </div>
  </Teleport>
</template>
