// useFocusTrap — minimal focus trap for the isolated "next" frontend.
//
// Self-contained (no dependency on the legacy composable). When `active` is
// truthy it: remembers the previously focused element, moves focus into the
// container, keeps Tab/Shift+Tab cycling within the container's focusable
// elements, and restores focus to the trigger when deactivated. Used by the
// AppShell mobile drawer (and reusable by future overlays).
import { watch, type Ref } from 'vue';

const FOCUSABLE_SELECTOR = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',');

function getFocusable(container: HTMLElement): HTMLElement[] {
  return Array.from(
    container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR),
  ).filter((el) => el.offsetParent !== null || el === document.activeElement);
}

export function useFocusTrap(
  containerRef: Ref<HTMLElement | null>,
  active: Ref<boolean>,
): void {
  let previouslyFocused: HTMLElement | null = null;

  function onKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Tab') return;
    const container = containerRef.value;
    if (!container) return;

    const focusable = getFocusable(container);
    if (focusable.length === 0) {
      event.preventDefault();
      return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const activeEl = document.activeElement as HTMLElement | null;

    // All focus moves use { preventScroll: true } so trapping focus inside a
    // body-teleported overlay (e.g. a centered Modal) never scroll-jumps the page
    // (Issue 3).
    if (event.shiftKey) {
      if (activeEl === first || !container.contains(activeEl)) {
        event.preventDefault();
        last.focus({ preventScroll: true });
      }
    } else if (activeEl === last || !container.contains(activeEl)) {
      event.preventDefault();
      first.focus({ preventScroll: true });
    }
  }

  function activate(): void {
    previouslyFocused = document.activeElement as HTMLElement | null;
    document.addEventListener('keydown', onKeydown, true);
    // Defer so the container has rendered its focusable children.
    requestAnimationFrame(() => {
      const container = containerRef.value;
      if (!container) return;
      const focusable = getFocusable(container);
      (focusable[0] ?? container).focus({ preventScroll: true });
    });
  }

  function deactivate(): void {
    document.removeEventListener('keydown', onKeydown, true);
    previouslyFocused?.focus?.({ preventScroll: true });
    previouslyFocused = null;
  }

  watch(
    active,
    (isActive) => {
      if (isActive) activate();
      else deactivate();
    },
    { flush: 'post' },
  );
}
