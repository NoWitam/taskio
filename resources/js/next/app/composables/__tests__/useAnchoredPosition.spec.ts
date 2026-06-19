// @vitest-environment happy-dom
// useAnchoredPosition.spec.ts — placement math, viewport flip, and the final
// in-viewport clamp. We stub the anchor's getBoundingClientRect, the floating
// element's offsetWidth/Height, and window.innerWidth/Height so the geometry is
// fully controlled.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { ref } from 'vue';
import { useAnchoredPosition, type Placement } from '../useAnchoredPosition';
import { stubRect, stubOffsetSize, setViewport } from '../../../__tests__/helpers/dom';

function setup(
  anchorRect: Partial<DOMRect>,
  floatSize: { width: number; height: number },
  placement: Placement,
  opts: { gap?: number; flip?: boolean } = {},
) {
  const anchorEl = document.createElement('div');
  const floatEl = document.createElement('div');
  document.body.append(anchorEl, floatEl);
  stubRect(anchorEl, anchorRect);
  stubOffsetSize(floatEl, floatSize.width, floatSize.height);
  const { style, update } = useAnchoredPosition(ref(anchorEl), ref(floatEl), {
    placement: () => placement,
    gap: opts.gap ?? 8,
    flip: opts.flip,
  });
  return { style, update, anchorEl, floatEl };
}

describe('useAnchoredPosition', () => {
  beforeEach(() => setViewport(1000, 800));
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('positions bottom-start directly below the anchor, left-aligned', () => {
    const { style, update } = setup(
      { top: 100, left: 200, width: 120, height: 40, bottom: 140, right: 320 },
      { width: 200, height: 150 },
      'bottom-start',
      { gap: 8 },
    );
    update();
    expect(style.value.side).toBe('bottom');
    expect(style.value.top).toBe(148); // anchor.bottom(140) + gap(8)
    expect(style.value.left).toBe(200); // anchor.left
  });

  it('positions bottom-end so the panel right edge aligns with the anchor right', () => {
    const { style, update } = setup(
      { top: 100, left: 200, width: 120, height: 40, bottom: 140, right: 320 },
      { width: 200, height: 150 },
      'bottom-end',
      { gap: 8 },
    );
    update();
    expect(style.value.left).toBe(120); // anchor.right(320) - floating.width(200)
  });

  it('flips bottom→top when the panel would overflow the viewport bottom', () => {
    // Anchor near the bottom: bottom-start would push the 150-tall panel past vh=800.
    const { style, update } = setup(
      { top: 720, left: 100, width: 120, height: 40, bottom: 760, right: 220 },
      { width: 200, height: 150 },
      'bottom-start',
      { gap: 8, flip: true },
    );
    update();
    expect(style.value.side).toBe('top');
    // top placement: anchor.top(720) - height(150) - gap(8) = 562
    expect(style.value.top).toBe(562);
  });

  it('does NOT flip when flip:false even if it overflows (only clamps)', () => {
    const { style, update } = setup(
      { top: 720, left: 100, width: 120, height: 40, bottom: 760, right: 220 },
      { width: 200, height: 150 },
      'bottom-start',
      { gap: 8, flip: false },
    );
    update();
    expect(style.value.side).toBe('bottom');
    // Clamp keeps it inside: max top = vh(800) - height(150) - gap(8) = 642
    expect(style.value.top).toBe(642);
  });

  it('clamps left into the viewport when bottom-end would push it off-screen left', () => {
    // Anchor at far left, end-alignment would give a negative left → clamp to gap.
    const { style, update } = setup(
      { top: 100, left: 0, width: 40, height: 40, bottom: 140, right: 40 },
      { width: 200, height: 150 },
      'bottom-end',
      { gap: 8 },
    );
    update();
    expect(style.value.left).toBe(8); // clamped to gap
  });

  it('clamps right edge into the viewport', () => {
    // Anchor near the right edge, start-aligned → would overflow vw=1000.
    const { style, update } = setup(
      { top: 100, left: 950, width: 40, height: 40, bottom: 140, right: 990 },
      { width: 200, height: 150 },
      'bottom-start',
      { gap: 8 },
    );
    update();
    // max left = vw(1000) - width(200) - gap(8) = 792
    expect(style.value.left).toBe(792);
  });
});
