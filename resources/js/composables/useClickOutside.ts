import { onBeforeUnmount, onMounted, Ref } from "vue";

export function useClickOutside(target: Ref<HTMLElement | null>, onOutside: (ev: MouseEvent) => void) {
  const handler = (ev: MouseEvent) => {
    const el = target.value;
    if (!el) return;
    if (ev.target instanceof Node && !el.contains(ev.target)) onOutside(ev);
  };

  onMounted(() => document.addEventListener("mousedown", handler));
  onBeforeUnmount(() => document.removeEventListener("mousedown", handler));
}
