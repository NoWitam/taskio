// @vitest-environment happy-dom
// useChipOverflow.spec.ts — the greedy "fit as many whole chips as fit + collapse
// to +N" math. We mock each measure-chip's offsetWidth and the track's clientWidth
// so the fit is deterministic, and assert visibleCount / hiddenCount, the +N pill
// reservation rule, the total()===0 short-circuit, and recompute on ResizeObserver.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { ref, nextTick } from 'vue';
import { useChipOverflow } from '../useChipOverflow';
import { withSetup } from '../../../__tests__/helpers/withSetup';
import {
  installBrowserMocks,
  restoreBrowserMocks,
  MockResizeObserver,
} from '../../../__tests__/helpers/dom';

/** Build a track + measure row, with each chip's offsetWidth forced. */
function makeRefs(chipWidths: number[], trackWidth: number) {
  const track = document.createElement('div');
  Object.defineProperty(track, 'clientWidth', { configurable: true, value: trackWidth });

  const measure = document.createElement('div');
  for (const w of chipWidths) {
    const chip = document.createElement('span');
    chip.setAttribute('data-measure-chip', '');
    Object.defineProperty(chip, 'offsetWidth', { configurable: true, value: w });
    measure.appendChild(chip);
  }
  document.body.append(track, measure);
  return { trackRef: ref<HTMLElement | null>(track), measureRef: ref<HTMLElement | null>(measure) };
}

describe('useChipOverflow', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => {
    restoreBrowserMocks();
    document.body.innerHTML = '';
  });

  it('shows all chips when they all fit (no +N reservation needed)', async () => {
    // 3 chips of 50 + 2 gaps of 6 = 162 ≤ 200. All fit, nothing hidden.
    const { trackRef, measureRef } = makeRefs([50, 50, 50], 200);
    const { result, unmount } = withSetup(() =>
      useChipOverflow({ trackRef, measureRef, total: () => 3, gap: 6 }),
    );
    await nextTick();
    await Promise.resolve(); // flush the synchronous rAF in recompute()
    expect(result.visibleCount.value).toBe(3);
    expect(result.hiddenCount.value).toBe(0);
    unmount();
  });

  it('reserves the +N pill width once anything overflows', async () => {
    // track 150, gap 6, plusReserve 44. Chips of 50 each.
    // chip0: used 50 (moreAfter → reserve 50). 50+50=100 ≤150 ✓ → count 1, used 50
    // chip1: w=50+gap6=56; reserve 50 → 50+56+50=156 >150 ✗ → break.
    // So only 1 visible, 2 hidden.
    const { trackRef, measureRef } = makeRefs([50, 50, 50], 150);
    const { result, unmount } = withSetup(() =>
      useChipOverflow({ trackRef, measureRef, total: () => 3, gap: 6, plusReserve: 44 }),
    );
    await nextTick();
    await Promise.resolve();
    expect(result.visibleCount.value).toBe(1);
    expect(result.hiddenCount.value).toBe(2);
    unmount();
  });

  it('does not reserve +N for the LAST chip (it fits exactly with no overflow)', async () => {
    // Two chips of 90, track 200, gap 6.
    // chip0: w=90, moreAfter → reserve 44+6=50 → 0+90+50=140 ≤200 ✓ count1 used90
    // chip1 (last): w=90+6=96, reserve 0 → 90+96=186 ≤200 ✓ count2.
    const { trackRef, measureRef } = makeRefs([90, 90], 200);
    const { result, unmount } = withSetup(() =>
      useChipOverflow({ trackRef, measureRef, total: () => 2, gap: 6, plusReserve: 44 }),
    );
    await nextTick();
    await Promise.resolve();
    expect(result.visibleCount.value).toBe(2);
    expect(result.hiddenCount.value).toBe(0);
    unmount();
  });

  it('total() === 0 short-circuits to visibleCount 0', async () => {
    const { trackRef, measureRef } = makeRefs([], 200);
    const { result, unmount } = withSetup(() =>
      useChipOverflow({ trackRef, measureRef, total: () => 0 }),
    );
    await nextTick();
    await Promise.resolve();
    expect(result.visibleCount.value).toBe(0);
    expect(result.hiddenCount.value).toBe(0);
    unmount();
  });

  it('honors reserved() space inside the track', async () => {
    // track 200 minus reserved 120 = 80 budget. Chips of 50.
    // chip0: moreAfter reserve 50 → 50+50=100 >80 ✗ → break. 0 visible, 2 hidden.
    const { trackRef, measureRef } = makeRefs([50, 50], 200);
    const { result, unmount } = withSetup(() =>
      useChipOverflow({
        trackRef,
        measureRef,
        total: () => 2,
        gap: 6,
        plusReserve: 44,
        reserved: () => 120,
      }),
    );
    await nextTick();
    await Promise.resolve();
    expect(result.visibleCount.value).toBe(0);
    expect(result.hiddenCount.value).toBe(2);
    unmount();
  });

  it('recomputes when the ResizeObserver fires after the track shrinks', async () => {
    const { trackRef, measureRef } = makeRefs([50, 50, 50], 300);
    const { result, unmount } = withSetup(() =>
      useChipOverflow({ trackRef, measureRef, total: () => 3, gap: 6, plusReserve: 44 }),
    );
    await nextTick();
    await Promise.resolve();
    expect(result.visibleCount.value).toBe(3); // all fit at 300

    // Shrink the track and fire the observer (the composable observes on mount).
    Object.defineProperty(trackRef.value!, 'clientWidth', { configurable: true, value: 150 });
    MockResizeObserver.triggerAll();
    await nextTick();
    expect(result.visibleCount.value).toBe(1);
    expect(result.hiddenCount.value).toBe(2);
    unmount();
  });
});
