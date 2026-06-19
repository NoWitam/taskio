// useDebounce.spec.ts — debounce timing with fake timers. Covers delayed
// execution, coalescing of rapid calls, cancel(), flush(), and auto-cancel on
// component scope dispose. Runs in node env (no DOM needed) but uses withSetup to
// exercise onScopeDispose. withSetup uses createApp().mount which needs a DOM, so
// this spec opts into happy-dom.
// @vitest-environment happy-dom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { useDebounce } from '../useDebounce';
import { withSetup } from '../../../__tests__/helpers/withSetup';

describe('useDebounce', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('runs only after the delay, with the LAST args', () => {
    const fn = vi.fn();
    const debounced = useDebounce(fn, 300);
    debounced('a');
    debounced('b');
    debounced('c');
    expect(fn).not.toHaveBeenCalled();
    vi.advanceTimersByTime(299);
    expect(fn).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1);
    expect(fn).toHaveBeenCalledTimes(1);
    expect(fn).toHaveBeenCalledWith('c');
  });

  it('cancel() drops a pending call', () => {
    const fn = vi.fn();
    const debounced = useDebounce(fn, 300);
    debounced('x');
    debounced.cancel();
    vi.advanceTimersByTime(500);
    expect(fn).not.toHaveBeenCalled();
  });

  it('flush() runs a pending call immediately', () => {
    const fn = vi.fn();
    const debounced = useDebounce(fn, 300);
    debounced('y');
    debounced.flush();
    expect(fn).toHaveBeenCalledTimes(1);
    expect(fn).toHaveBeenCalledWith('y');
    // No further call once the (now-cleared) timer would have fired.
    vi.advanceTimersByTime(500);
    expect(fn).toHaveBeenCalledTimes(1);
  });

  it('flush() with nothing pending is a no-op', () => {
    const fn = vi.fn();
    const debounced = useDebounce(fn, 300);
    debounced.flush();
    expect(fn).not.toHaveBeenCalled();
  });

  it('auto-cancels the pending timer when the owning scope disposes', () => {
    const fn = vi.fn();
    let debounced!: ReturnType<typeof useDebounce<typeof fn>>;
    const { unmount } = withSetup(() => {
      debounced = useDebounce(fn, 300);
      return {};
    });
    debounced('z');
    unmount(); // triggers onScopeDispose → cancel()
    vi.advanceTimersByTime(500);
    expect(fn).not.toHaveBeenCalled();
  });
});
