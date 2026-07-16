// useConfirm — imperative confirm-dialog API for the "next" frontend.
//
//   const confirm = useConfirm();
//   if (await confirm({ title: 'Delete form?', variant: 'danger' })) { … }
//
// Backed by a single shared `<ConfirmHost>` mounted once (e.g. in App / the
// gallery shell). `confirm()` pushes a request onto a module-level reactive
// queue; the host renders the current request as a ConfirmDialog and resolves
// the returned promise with `true` (confirmed) or `false` (cancelled/dismissed).
//
// Async confirm: pass an `onConfirm` that returns a promise — the dialog shows
// its loading state until it settles, then closes and resolves `true`. If
// `onConfirm` throws, the dialog stays open and the error propagates so the
// caller can surface it (e.g. via a toast).
import { ref, type Ref } from 'vue';

export interface ConfirmOptions {
  title?: string;
  message?: string;
  confirmLabel?: string;
  cancelLabel?: string;
  variant?: 'default' | 'danger';
  /**
   * Optional async action run when confirmed. While it is pending the dialog
   * shows its loading state; on success it closes and the promise resolves true.
   * If it rejects, the dialog stays open and the rejection propagates.
   */
  onConfirm?: () => void | Promise<void>;
}

export interface ConfirmRequest extends ConfirmOptions {
  id: number;
  loading: boolean;
  resolve: (value: boolean) => void;
  reject: (reason?: unknown) => void;
}

let seq = 0;

// Module-level reactive queue shared by every caller + the single host.
const queue: Ref<ConfirmRequest[]> = ref([]);

/** Read-only access for the host component. */
export function useConfirmQueue() {
  return queue;
}

export interface UseConfirmReturn {
  (options: ConfirmOptions): Promise<boolean>;
}

export function useConfirm(): UseConfirmReturn {
  return (options: ConfirmOptions): Promise<boolean> =>
    new Promise<boolean>((resolve, reject) => {
      queue.value.push({
        id: (seq += 1),
        loading: false,
        ...options,
        resolve,
        reject,
      });
    });
}

/** Host helper: confirm the current request, awaiting any async `onConfirm`. */
export async function settleConfirm(request: ConfirmRequest): Promise<void> {
  if (request.onConfirm) {
    request.loading = true;
    try {
      await request.onConfirm();
    } catch (error) {
      request.loading = false;
      request.reject(error);
      removeRequest(request.id);
      throw error;
    }
    request.loading = false;
  }
  request.resolve(true);
  removeRequest(request.id);
}

/** Host helper: cancel/dismiss the current request. */
export function dismissConfirm(request: ConfirmRequest): void {
  if (request.loading) return;
  request.resolve(false);
  removeRequest(request.id);
}

function removeRequest(id: number): void {
  const i = queue.value.findIndex((r) => r.id === id);
  if (i >= 0) queue.value.splice(i, 1);
}
