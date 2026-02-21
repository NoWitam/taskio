import { reactive, readonly } from "vue";

export type ToastTone = "neutral" | "success" | "warning" | "danger";
export type ToastItem = {
  id: string;
  title: string;
  message?: string;
  tone: ToastTone;
  timeoutMs: number | null;
};

const state = reactive<{ items: ToastItem[] }>({ items: [] });

const timers = new Map<string, number>();

function uid() {
  return `t_${Math.random().toString(16).slice(2)}_${Date.now()}`;
}

export function useToast() {
  const clearTimer = (id: string) => {
    const t = timers.get(id);
    if (t) {
      window.clearTimeout(t);
      timers.delete(id);
    }
  };

  const armTimer = (item: ToastItem) => {
    clearTimer(item.id);
    if (item.timeoutMs == null) return;

    const t = window.setTimeout(() => {
      remove(item.id);
    }, item.timeoutMs);
    timers.set(item.id, t);
  };

  const push = (toast: Omit<ToastItem, "id">) => {
    const item: ToastItem = { ...toast, id: uid() };
    state.items.unshift(item);
    armTimer(item);
    return item.id;
  };

  const update = (id: string, patch: Partial<Omit<ToastItem, "id">>) => {
    const idx = state.items.findIndex((x) => x.id === id);
    if (idx < 0) return;

    state.items[idx] = { ...state.items[idx], ...patch };
    armTimer(state.items[idx]);
  };

  const remove = (id: string) => {
    clearTimer(id);
    const idx = state.items.findIndex((x) => x.id === id);
    if (idx >= 0) state.items.splice(idx, 1);
  };

  return { toasts: readonly(state).items, push, update, remove };
}

