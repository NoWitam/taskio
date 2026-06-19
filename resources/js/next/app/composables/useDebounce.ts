// useDebounce — a tiny debounce helper for the "next" frontend.
//
// Wraps a function so it only runs `delay` ms after the LAST call. Used by
// FilterBar's debounced search (and available to any list-screen control that
// shouldn't fire on every keystroke). Returns the debounced function with
// `cancel()` (drop a pending call) and `flush()` (run a pending call now), and
// cancels any pending timer on unmount when called inside a component scope.
import { getCurrentScope, onScopeDispose } from 'vue';

export type DebouncedFn<T extends (...args: any[]) => any> = ((
  ...args: Parameters<T>
) => void) & {
  /** Drop a pending call without running it. */
  cancel: () => void;
  /** Run a pending call immediately (if any). */
  flush: () => void;
};

/**
 * Debounce `fn` by `delay` ms (default 300). The wrapped function returns void;
 * use `flush()` to force a pending call (e.g. on submit) or `cancel()` to drop it.
 */
export function useDebounce<T extends (...args: any[]) => any>(
  fn: T,
  delay = 300,
): DebouncedFn<T> {
  let timer: ReturnType<typeof setTimeout> | null = null;
  let lastArgs: Parameters<T> | null = null;

  const debounced = ((...args: Parameters<T>) => {
    lastArgs = args;
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
      timer = null;
      const toCall = lastArgs;
      lastArgs = null;
      if (toCall) fn(...toCall);
    }, delay);
  }) as DebouncedFn<T>;

  debounced.cancel = () => {
    if (timer) clearTimeout(timer);
    timer = null;
    lastArgs = null;
  };

  debounced.flush = () => {
    if (!timer) return;
    clearTimeout(timer);
    timer = null;
    const toCall = lastArgs;
    lastArgs = null;
    if (toCall) fn(...toCall);
  };

  // Auto-cleanup when created inside a component/effect scope.
  if (getCurrentScope()) onScopeDispose(() => debounced.cancel());

  return debounced;
}
