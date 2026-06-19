// useToast — transient notification API for the "next" frontend.
//
//   const toast = useToast();
//   toast.success('Saved', { description: 'Your form was updated.' });
//   toast.danger('Delete failed', { action: { label: 'Retry', onClick: retry } });
//
// Backed by a module-level reactive store shared by every caller and a single
// `<ToastViewport>` (mounted once, e.g. in App / the gallery shell). Toasts have
// a variant (success/info/warning/danger), a title, optional description, an
// optional action button, auto-dismiss with pause-on-hover, and manual dismiss.
// The viewport caps how many are visible at once and stacks the rest.
//
// Self-contained: no imports from the legacy `resources/js/`.
import { ref, type Ref } from 'vue';

export type ToastVariant = 'success' | 'info' | 'warning' | 'danger';

export interface ToastAction {
  label: string;
  onClick: () => void;
}

export interface ToastOptions {
  title: string;
  description?: string;
  variant?: ToastVariant;
  /** Auto-dismiss after N ms. 0 / null disables auto-dismiss (sticky). */
  duration?: number | null;
  action?: ToastAction;
}

export interface ToastRecord extends Required<Pick<ToastOptions, 'title' | 'variant'>> {
  id: number;
  description?: string;
  duration: number | null;
  action?: ToastAction;
  /** Internal timer + pause bookkeeping (managed by the viewport). */
  remaining: number;
}

const DEFAULT_DURATION = 5000;

let seq = 0;
const toasts: Ref<ToastRecord[]> = ref([]);

/** Read-only access for the viewport component. */
export function useToastStore() {
  return toasts;
}

function push(options: ToastOptions): number {
  const id = (seq += 1);
  const duration =
    options.duration === undefined ? DEFAULT_DURATION : options.duration;
  toasts.value.push({
    id,
    title: options.title,
    description: options.description,
    variant: options.variant ?? 'info',
    duration,
    action: options.action,
    remaining: duration ?? 0,
  });
  return id;
}

export function dismissToast(id: number): void {
  const i = toasts.value.findIndex((t) => t.id === id);
  if (i >= 0) toasts.value.splice(i, 1);
}

export function clearToasts(): void {
  toasts.value.splice(0, toasts.value.length);
}

export interface UseToastReturn {
  /** Low-level: push any toast. Returns its id. */
  show: (options: ToastOptions) => number;
  success: (title: string, options?: Omit<ToastOptions, 'title' | 'variant'>) => number;
  info: (title: string, options?: Omit<ToastOptions, 'title' | 'variant'>) => number;
  warning: (title: string, options?: Omit<ToastOptions, 'title' | 'variant'>) => number;
  danger: (title: string, options?: Omit<ToastOptions, 'title' | 'variant'>) => number;
  dismiss: (id: number) => void;
  clear: () => void;
}

export function useToast(): UseToastReturn {
  const variantShortcut =
    (variant: ToastVariant) =>
    (title: string, options: Omit<ToastOptions, 'title' | 'variant'> = {}) =>
      push({ ...options, title, variant });

  return {
    show: push,
    success: variantShortcut('success'),
    info: variantShortcut('info'),
    warning: variantShortcut('warning'),
    danger: variantShortcut('danger'),
    dismiss: dismissToast,
    clear: clearToasts,
  };
}
