// useOutsideClick — fire a callback when a pointer/focus event lands outside an
// element, for the isolated "next" frontend.
//
// Self-contained (no legacy dependency). Used by the custom Select popover to
// close on outside interaction. Listens on `pointerdown` (so the close happens
// before a click on another control steals focus) and also on `focusin` so
// keyboard focus leaving the widget closes it too. The listeners are only bound
// while `active` is truthy, and are always cleaned up on unmount.
import { onBeforeUnmount, watch, type Ref } from 'vue';

export function useOutsideClick(
  /** Element(s) considered "inside". Events within any are ignored. */
  refs: Ref<HTMLElement | null> | Ref<HTMLElement | null>[],
  /** Called when an interaction happens outside every ref. */
  handler: (event: Event) => void,
  /** Only listen while this is true. */
  active: Ref<boolean>,
): void {
  const list = Array.isArray(refs) ? refs : [refs];

  function isInside(target: Node | null): boolean {
    if (!target) return false;
    return list.some((r) => r.value?.contains(target));
  }

  function onEvent(event: Event): void {
    if (!isInside(event.target as Node | null)) {
      handler(event);
    }
  }

  function bind(): void {
    document.addEventListener('pointerdown', onEvent, true);
    document.addEventListener('focusin', onEvent, true);
  }

  function unbind(): void {
    document.removeEventListener('pointerdown', onEvent, true);
    document.removeEventListener('focusin', onEvent, true);
  }

  watch(
    active,
    (isActive) => {
      if (isActive) bind();
      else unbind();
    },
    { flush: 'post' },
  );

  onBeforeUnmount(unbind);
}
