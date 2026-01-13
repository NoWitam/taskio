import { onBeforeUnmount, onMounted, Ref } from "vue";

type MaybeRefEl = Ref<HTMLElement | null>;

export function useClickOutside(
  target: MaybeRefEl | MaybeRefEl[],
  onOutside: (ev: MouseEvent) => void
) {
  const getTargets = () => (Array.isArray(target) ? target : [target]);

  const handler = (ev: MouseEvent) => {
    const targets = getTargets()
      .map((t) => t.value)
      .filter(Boolean) as HTMLElement[];
    if (!targets.length) return;

    if (!(ev.target instanceof Node)) return;
    const clickedInside = targets.some((el) => el.contains(ev.target as Node));
    if (!clickedInside) onOutside(ev);
  };

  // Use capture so events stopped inside dropdown panels still trigger outside detection.
  // This is important for nested/teleported dropdowns (e.g. DateRangeSelect -> DateInput).
  onMounted(() => document.addEventListener("mousedown", handler, true));
  onBeforeUnmount(() => document.removeEventListener("mousedown", handler, true));
}
