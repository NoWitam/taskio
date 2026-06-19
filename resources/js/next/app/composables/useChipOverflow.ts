// useChipOverflow — shared "fit as many chips as physically fit, collapse the
// rest into a +N pill" mechanics for the "next" frontend.
//
// Both Select (multiple/chips mode) and PillGroupInput (collapse mode) render a
// single fixed-height row of chips that must NEVER grow the control (no-grow
// rule). When the chips don't fit, the overflow collapses into a single "+N"
// pill. The number of visible chips is DYNAMIC — it depends on the actual pixel
// width available in the row, not a fixed count.
//
// How it works:
//  - The host renders ALL items at natural width into a HIDDEN measuring row and
//    tags each with `[data-measure-chip]`. This composable reads their offset
//    widths once laid out.
//  - It greedily fits chips into the track's `clientWidth`, reserving:
//      • `reserved()`  — trailing controls living INSIDE the track that are not
//        chips (e.g. the typeable text input in PillGroupInput, or nothing in
//        Select where the X/chevron live OUTSIDE the track in FieldShell#trailing),
//      • the measured "+N" pill width (only when at least one chip is hidden),
//      • the inter-chip gap.
//  - Recompute is wired to a ResizeObserver on the track plus an explicit
//    `recompute()` the host calls on selection / option / option-width changes.
//
// Outputs are refs: `visibleCount` (how many leading chips to render) and
// `hiddenCount`. The host slices its own item array with `visibleCount` and shows
// the "+N" pill when `hiddenCount > 0`.
import {
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
  type Ref,
} from 'vue';

export interface ChipOverflowOptions {
  /**
   * The single-row track the chips render into. Its `clientWidth` is the budget.
   */
  trackRef: Ref<HTMLElement | null>;
  /**
   * The hidden measuring row holding every item at natural width. Each item must
   * carry the `[data-measure-chip]` attribute so widths can be read in order.
   */
  measureRef: Ref<HTMLElement | null>;
  /**
   * Total number of items currently selected/added. Used to decide whether a
   * "+N" pill is needed at all and to bound the count.
   */
  total: () => number;
  /**
   * Pixels to reserve inside the track for non-chip content that always shares
   * the row (e.g. a text input). Defaults to 0 (Select's X/chevron live OUTSIDE
   * the track, in FieldShell's trailing slot, so nothing is reserved there).
   */
  reserved?: () => number;
  /** Inter-item gap in px (matches the row's `gap-*`). Default 6 (`gap-next-1_5`). */
  gap?: number;
  /**
   * Estimated width (px) of the "+N" pill, reserved whenever 1+ chips overflow.
   * Default 44. Kept generous so a two-digit "+N" never clips.
   */
  plusReserve?: number;
  /** CSS selector for the measured items inside `measureRef`. */
  itemSelector?: string;
}

export interface ChipOverflow {
  /** How many leading chips physically fit (host slices `items.slice(0, n)`). */
  visibleCount: Ref<number>;
  /** How many items are collapsed behind the "+N" pill. */
  hiddenCount: Ref<number>;
  /** Force a re-measure (host calls after selection / option-width changes). */
  recompute: () => void;
}

export function useChipOverflow(options: ChipOverflowOptions): ChipOverflow {
  const gap = options.gap ?? 6;
  const plusReserve = options.plusReserve ?? 44;
  const itemSelector = options.itemSelector ?? '[data-measure-chip]';

  const visibleCount = ref<number>(Number.POSITIVE_INFINITY);
  const hiddenCount = ref<number>(0);

  function measure(): void {
    const track = options.trackRef.value;
    const measureRow = options.measureRef.value;
    const total = options.total();

    if (total === 0) {
      visibleCount.value = 0;
      hiddenCount.value = 0;
      return;
    }
    if (!track || !measureRow) return;

    const available = track.clientWidth - (options.reserved?.() ?? 0);
    const items = Array.from(
      measureRow.querySelectorAll<HTMLElement>(itemSelector),
    );
    if (!items.length) {
      // Measuring row not laid out yet — assume all fit; a later recompute fixes it.
      visibleCount.value = total;
      hiddenCount.value = 0;
      return;
    }

    let used = 0;
    let count = 0;
    for (let i = 0; i < items.length; i += 1) {
      const w = items[i].offsetWidth + (i > 0 ? gap : 0);
      // If this is NOT the last item, fitting it still leaves later items hidden,
      // so we must also reserve room for the "+N" pill (+ its leading gap).
      const moreAfter = i < items.length - 1;
      const reserve = moreAfter ? plusReserve + gap : 0;
      if (used + w + reserve > available) break;
      used += w;
      count += 1;
    }

    visibleCount.value = count;
    hiddenCount.value = Math.max(0, total - count);
  }

  function recompute(): void {
    // Defer to the next frame so the measuring row reflects the latest items.
    requestAnimationFrame(measure);
  }

  let ro: ResizeObserver | null = null;
  onMounted(() => {
    if (typeof ResizeObserver !== 'undefined') {
      ro = new ResizeObserver(() => measure());
      if (options.trackRef.value) ro.observe(options.trackRef.value);
    }
    recompute();
  });
  // Re-attach the observer if the track element changes (e.g. v-if remount).
  watch(options.trackRef, (el, prev) => {
    if (ro && prev) ro.unobserve(prev);
    if (ro && el) ro.observe(el);
    recompute();
  });

  onBeforeUnmount(() => {
    ro?.disconnect();
    ro = null;
  });

  return { visibleCount, hiddenCount, recompute };
}
