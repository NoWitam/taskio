import { computed, onBeforeUnmount, watch, type ComputedRef, type Ref } from 'vue';

// Global overlay stack to manage z-index ordering for teleported UI layers
// (dialogs, dropdowns, popovers). This prevents issues where a dropdown sits
// above a modal, and supports future scenarios like dialog-in-dialog.

const OVERLAY_Z_BASE = 1000;
const OVERLAY_Z_STEP = 10;

const overlayStack: Ref<string[]> = { value: [] } as any;
let uid = 0;

function push(id: string) {
  // Move to top if already present
  overlayStack.value = overlayStack.value.filter((x) => x !== id).concat(id);
}

function remove(id: string) {
  if (!overlayStack.value.includes(id)) return;
  overlayStack.value = overlayStack.value.filter((x) => x !== id);
}

export type OverlayKind = 'dialog' | 'menu' | 'popover';

export function useOverlayStack(open: Ref<boolean> | ComputedRef<boolean>, kind: OverlayKind = 'popover') {
  const id = `ov_${++uid}`;

  const zIndex = computed(() => {
    const idx = overlayStack.value.indexOf(id);
    const pos = idx >= 0 ? idx : overlayStack.value.length;
    return OVERLAY_Z_BASE + pos * OVERLAY_Z_STEP;
  });

  const bringToFront = () => push(id);

  watch(
    () => open.value,
    (v) => {
      if (v) {
        push(id);
        if (kind === 'dialog' && typeof window !== 'undefined') {
          window.dispatchEvent(new CustomEvent('overlay:dialog-opened'));
        }
      } else {
        remove(id);
      }
    },
    { immediate: true }
  );

  onBeforeUnmount(() => remove(id));

  return { zIndex, bringToFront };
}
