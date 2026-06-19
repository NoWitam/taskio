// useAnchoredPosition — lightweight anchored floating positioning for the
// isolated "next" frontend.
//
// Given an anchor element (the trigger) and a floating element (the panel), this
// computes fixed-position coordinates for a requested placement
// (top/bottom/left/right + start/center/end alignment), with an optional basic
// viewport flip when the preferred side would overflow. It intentionally avoids
// a heavy dependency (Floating UI etc.) — anchor rect + placement math is enough
// for menus/popovers/tooltips. Coordinates are in viewport space (for
// `position: fixed`), so they ignore page scroll offsets.
//
// Recompute is driven explicitly by the caller via `update()` (e.g. on open, and
// bound to scroll/resize while open) so the composable stays framework-light.
//
// CONSUMER CONTRACT — height: this composable clamps the panel into the viewport
// but does NOT cap its height or add internal scroll. A panel taller than the
// viewport will be clamped to `top = gap` and overflow the bottom. Every consumer
// MUST bound its own panel height (e.g. `max-h-80` + internal `overflow-y-auto`),
// as Select (option list) and CalendarPanel (fixed 6 rows) already do.
import { ref, type Ref } from 'vue';

export type Side = 'top' | 'bottom' | 'left' | 'right';
export type Align = 'start' | 'center' | 'end';
export type Placement =
  | Side
  | `${Side}-start`
  | `${Side}-end`;

export interface AnchoredStyle {
  top: number;
  left: number;
  /** Resolved side after any flip (useful for arrow direction / transforms). */
  side: Side;
}

function parsePlacement(placement: Placement): { side: Side; align: Align } {
  const [side, align] = placement.split('-') as [Side, Align | undefined];
  return { side, align: align ?? 'center' };
}

function opposite(side: Side): Side {
  switch (side) {
    case 'top':
      return 'bottom';
    case 'bottom':
      return 'top';
    case 'left':
      return 'right';
    case 'right':
      return 'left';
  }
}

function compute(
  anchor: DOMRect,
  floating: { width: number; height: number },
  side: Side,
  align: Align,
  gap: number,
): { top: number; left: number } {
  let top = 0;
  let left = 0;

  if (side === 'top' || side === 'bottom') {
    top = side === 'bottom' ? anchor.bottom + gap : anchor.top - floating.height - gap;
    if (align === 'start') left = anchor.left;
    else if (align === 'end') left = anchor.right - floating.width;
    else left = anchor.left + anchor.width / 2 - floating.width / 2;
  } else {
    left = side === 'right' ? anchor.right + gap : anchor.left - floating.width - gap;
    if (align === 'start') top = anchor.top;
    else if (align === 'end') top = anchor.bottom - floating.height;
    else top = anchor.top + anchor.height / 2 - floating.height / 2;
  }

  return { top, left };
}

export function useAnchoredPosition(
  anchorRef: Ref<HTMLElement | null>,
  floatingRef: Ref<HTMLElement | null>,
  options: {
    placement: () => Placement;
    /** Gap between anchor and floating element, in px. */
    gap?: number;
    /** Try flipping to the opposite side when it would overflow the viewport. */
    flip?: boolean;
  },
) {
  const style = ref<AnchoredStyle>({ top: 0, left: 0, side: 'bottom' });
  const gap = options.gap ?? 8;

  function update(): void {
    const anchor = anchorRef.value;
    const floating = floatingRef.value;
    if (!anchor || !floating) return;

    const anchorRect = anchor.getBoundingClientRect();
    const size = { width: floating.offsetWidth, height: floating.offsetHeight };
    const { side: preferredSide, align } = parsePlacement(options.placement());

    let side = preferredSide;
    let pos = compute(anchorRect, size, side, align, gap);

    if (options.flip !== false) {
      const vw = window.innerWidth;
      const vh = window.innerHeight;
      const overflows =
        pos.top < 0 ||
        pos.left < 0 ||
        pos.top + size.height > vh ||
        pos.left + size.width > vw;

      if (overflows) {
        const flippedSide = opposite(preferredSide);
        const flipped = compute(anchorRect, size, flippedSide, align, gap);
        const flippedOverflows =
          flipped.top < 0 ||
          flipped.left < 0 ||
          flipped.top + size.height > vh ||
          flipped.left + size.width > vw;
        // Only adopt the flip if it actually fits better.
        if (!flippedOverflows) {
          side = flippedSide;
          pos = flipped;
        }
      }
    }

    // Final clamp so the panel never leaves the viewport entirely.
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    pos.left = Math.max(gap, Math.min(pos.left, vw - size.width - gap));
    pos.top = Math.max(gap, Math.min(pos.top, vh - size.height - gap));

    style.value = { top: pos.top, left: pos.left, side };
  }

  return { style, update };
}
