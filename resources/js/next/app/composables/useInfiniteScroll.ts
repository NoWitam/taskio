// useInfiniteScroll — IntersectionObserver-driven "load more" for the "next"
// frontend's cursor-paginated lists (Tasks board columns + list view).
//
// Watches a sentinel element near the bottom of a scroll container; when it
// scrolls into view AND there is more to load AND nothing is in flight, it calls
// `onLoadMore`. The observer's `root` is optional — pass the scroll container ref
// for a scoped column (board), or omit it to observe against the viewport (page
// scroll, list view).
//
// Returns the `sentinelRef` to place at the end of the list, and `pause` /
// `resume` so callers can stop observing (e.g. while an initial load runs).
import {
  onBeforeUnmount,
  ref,
  watch,
  type Ref,
} from 'vue';

export interface InfiniteScrollOptions {
  /** Called when the sentinel is near-visible and `canLoadMore()` is true. */
  onLoadMore: () => void;
  /** Gate: only load more when this returns true (more pages, not loading). */
  canLoadMore: () => boolean;
  /** Optional scroll container; omit to observe against the viewport. */
  root?: Ref<HTMLElement | null>;
  /** Pre-trigger margin so the next page loads before the user hits the end. */
  rootMargin?: string;
}

export function useInfiniteScroll(options: InfiniteScrollOptions): {
  sentinelRef: Ref<HTMLElement | null>;
  pause: () => void;
  resume: () => void;
} {
  const sentinelRef = ref<HTMLElement | null>(null);
  let io: IntersectionObserver | null = null;
  let paused = false;

  function disconnect(): void {
    io?.disconnect();
    io = null;
  }

  function observe(): void {
    disconnect();
    if (paused || typeof IntersectionObserver === 'undefined') return;
    const sentinel = sentinelRef.value;
    if (!sentinel) return;

    io = new IntersectionObserver(
      (entries) => {
        if (entries.some((e) => e.isIntersecting) && options.canLoadMore()) {
          options.onLoadMore();
        }
      },
      {
        root: options.root?.value ?? null,
        rootMargin: options.rootMargin ?? '200px',
      },
    );
    io.observe(sentinel);
  }

  function pause(): void {
    paused = true;
    disconnect();
  }
  function resume(): void {
    paused = false;
    observe();
  }

  // Re-observe whenever the sentinel or the (optional) root element appears.
  watch(
    [sentinelRef, () => options.root?.value],
    () => observe(),
    { flush: 'post' },
  );

  onBeforeUnmount(disconnect);

  return { sentinelRef, pause, resume };
}
