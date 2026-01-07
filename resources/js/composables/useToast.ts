import { reactive, readonly } from "vue";

export type ToastTone = "neutral" | "success" | "warning" | "danger";
export type ToastItem = {
  id: string;
  title: string;
  message?: string;
  tone: ToastTone;
  timeoutMs: number;
};

const state = reactive<{ items: ToastItem[] }>({ items: [] });

function uid() {
  return `t_${Math.random().toString(16).slice(2)}_${Date.now()}`;
}

export function useToast() {
  const push = (toast: Omit<ToastItem, "id">) => {
    const item: ToastItem = { ...toast, id: uid() };
    state.items.unshift(item);

    window.setTimeout(() => {
      const idx = state.items.findIndex((x) => x.id === item.id);
      if (idx >= 0) state.items.splice(idx, 1);
    }, item.timeoutMs);
  };

  const remove = (id: string) => {
    const idx = state.items.findIndex((x) => x.id === id);
    if (idx >= 0) state.items.splice(idx, 1);
  };

  return { toasts: readonly(state).items, push, remove };
}
