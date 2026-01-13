export type DebouncedFn<T extends (...args: any[]) => any> = ((...args: Parameters<T>) => void) & {
  cancel: () => void;
  flush: () => void;
};

/**
 * Debounce helper for event handlers / watchers.
 * Default delay: 250ms.
 */
export function useDebounceFn<T extends (...args: any[]) => any>(fn: T, delay = 450): DebouncedFn<T> {
  let timer: ReturnType<typeof setTimeout> | null = null;
  let lastArgs: Parameters<T> | null = null;

  const debounced = ((...args: Parameters<T>) => {
    lastArgs = args;

    if (timer) {
      clearTimeout(timer);
    }

    timer = setTimeout(() => {
      timer = null;
      const toCall = lastArgs;
      lastArgs = null;
      if (toCall) fn(...toCall);
    }, delay);
  }) as DebouncedFn<T>;

  debounced.cancel = () => {
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }
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

  return debounced;
}
