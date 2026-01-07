import { Ref, onBeforeUnmount, onMounted } from "vue";

function getFocusable(root: HTMLElement) {
  return Array.from(
    root.querySelectorAll<HTMLElement>(
      [
        "a[href]",
        "button:not([disabled])",
        "textarea:not([disabled])",
        "input:not([disabled])",
        "select:not([disabled])",
        "[tabindex]:not([tabindex='-1'])",
      ].join(",")
    )
  ).filter((el) => !el.hasAttribute("disabled") && !el.getAttribute("aria-hidden"));
}

export function useFocusTrap(container: Ref<HTMLElement | null>, enabled: Ref<boolean>) {
  let lastActive: HTMLElement | null = null;

  const onKeyDown = (e: KeyboardEvent) => {
    if (!enabled.value) return;
    if (e.key !== "Tab") return;

    const root = container.value;
    if (!root) return;

    const focusables = getFocusable(root);
    if (focusables.length === 0) return;

    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    const active = document.activeElement as HTMLElement | null;

    if (e.shiftKey) {
      if (!active || active === first) {
        e.preventDefault();
        last.focus();
      }
    } else {
      if (!active || active === last) {
        e.preventDefault();
        first.focus();
      }
    }
  };

  onMounted(() => document.addEventListener("keydown", onKeyDown));
  onBeforeUnmount(() => document.removeEventListener("keydown", onKeyDown));

  const activate = () => {
    lastActive = document.activeElement as HTMLElement | null;
    queueMicrotask(() => {
      const root = container.value;
      if (!root) return;
      const focusables = getFocusable(root);
      (focusables[0] ?? root).focus();
    });
  };

  const restore = () => {
    lastActive?.focus?.();
    lastActive = null;
  };

  return { activate, restore };
}
