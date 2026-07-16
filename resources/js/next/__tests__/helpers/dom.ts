// Shared DOM test helpers for the isolated "next" frontend specs.
//
// happy-dom (the env used by the DOM specs below) implements most of the DOM but
// is missing/limited on a few layout + observer APIs the next composables and
// components rely on (ResizeObserver, IntersectionObserver, matchMedia, real
// geometry via getBoundingClientRect/offset* and rAF). These helpers install
// controllable stand-ins so the geometry-driven logic (chip overflow, anchored
// positioning, edge fades) can be asserted deterministically.
//
// Import + call `installBrowserMocks()` from a `beforeEach` (or rely on the per
// helper installers below). Everything is restorable so specs stay isolated.
import { vi } from 'vitest';

/** A controllable ResizeObserver: tests can fire callbacks on demand. */
export class MockResizeObserver {
  static instances: MockResizeObserver[] = [];
  callback: ResizeObserverCallback;
  observed = new Set<Element>();

  constructor(cb: ResizeObserverCallback) {
    this.callback = cb;
    MockResizeObserver.instances.push(this);
  }

  observe(el: Element): void {
    this.observed.add(el);
  }
  unobserve(el: Element): void {
    this.observed.delete(el);
  }
  disconnect(): void {
    this.observed.clear();
  }

  /** Fire this observer's callback (optionally for a specific target). */
  trigger(target?: Element): void {
    const entries = Array.from(this.observed)
      .filter((el) => !target || el === target)
      .map((el) => ({ target: el }) as ResizeObserverEntry);
    this.callback(entries, this as unknown as ResizeObserver);
  }

  /** Fire the callback of every live observer. */
  static triggerAll(): void {
    for (const i of MockResizeObserver.instances) i.trigger();
  }

  static reset(): void {
    MockResizeObserver.instances = [];
  }
}

/** A controllable IntersectionObserver. */
export class MockIntersectionObserver {
  static instances: MockIntersectionObserver[] = [];
  callback: IntersectionObserverCallback;
  observed = new Set<Element>();
  root: Element | Document | null;

  constructor(cb: IntersectionObserverCallback, options?: IntersectionObserverInit) {
    this.callback = cb;
    this.root = (options?.root as Element | Document | null) ?? null;
    MockIntersectionObserver.instances.push(this);
  }

  observe(el: Element): void {
    this.observed.add(el);
  }
  unobserve(el: Element): void {
    this.observed.delete(el);
  }
  disconnect(): void {
    this.observed.clear();
  }
  takeRecords(): IntersectionObserverEntry[] {
    return [];
  }

  /** Fire intersection for the observed targets. */
  trigger(isIntersecting = true): void {
    const entries = Array.from(this.observed).map(
      (el) => ({ target: el, isIntersecting }) as IntersectionObserverEntry,
    );
    this.callback(entries, this as unknown as IntersectionObserver);
  }

  static reset(): void {
    MockIntersectionObserver.instances = [];
  }
}

/** matchMedia stub; `matches` is configurable per query via the map. */
export function installMatchMedia(matches = false): void {
  vi.stubGlobal(
    'matchMedia',
    vi.fn().mockImplementation((query: string) => ({
      matches,
      media: query,
      onchange: null,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      addListener: vi.fn(),
      removeListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })),
  );
}

/**
 * Make `requestAnimationFrame`/`cancelAnimationFrame` run synchronously on a
 * microtask so deferred measuring (`recompute`, focus-trap activate) resolves
 * predictably with `await nextTick()` / `await flushPromises()`.
 */
export function installSyncRaf(): void {
  vi.stubGlobal(
    'requestAnimationFrame',
    (cb: FrameRequestCallback): number => {
      Promise.resolve().then(() => cb(performance.now?.() ?? Date.now()));
      return 0;
    },
  );
  vi.stubGlobal('cancelAnimationFrame', (): void => {});
}

/** Install all the observer/media/raf globals. Call from `beforeEach`. */
export function installBrowserMocks(): void {
  MockResizeObserver.reset();
  MockIntersectionObserver.reset();
  vi.stubGlobal('ResizeObserver', MockResizeObserver);
  vi.stubGlobal('IntersectionObserver', MockIntersectionObserver);
  installMatchMedia(false);
  installSyncRaf();
}

/** Restore everything stubbed by `installBrowserMocks`. */
export function restoreBrowserMocks(): void {
  vi.unstubAllGlobals();
  MockResizeObserver.reset();
  MockIntersectionObserver.reset();
}

/**
 * Force `getBoundingClientRect` to return a fixed rect for an element. Returns a
 * setter so a test can mutate the rect and re-measure.
 */
export function stubRect(el: Element, rect: Partial<DOMRect>): (next: Partial<DOMRect>) => void {
  const apply = (r: Partial<DOMRect>): void => {
    const full: DOMRect = {
      x: r.x ?? r.left ?? 0,
      y: r.y ?? r.top ?? 0,
      top: r.top ?? 0,
      left: r.left ?? 0,
      right: r.right ?? (r.left ?? 0) + (r.width ?? 0),
      bottom: r.bottom ?? (r.top ?? 0) + (r.height ?? 0),
      width: r.width ?? 0,
      height: r.height ?? 0,
      toJSON: () => ({}),
    };
    Object.defineProperty(el, 'getBoundingClientRect', {
      configurable: true,
      value: () => full,
    });
  };
  apply(rect);
  return apply;
}

/** Set controllable viewport dimensions on `window`. */
export function setViewport(width: number, height: number): void {
  Object.defineProperty(window, 'innerWidth', { configurable: true, value: width });
  Object.defineProperty(window, 'innerHeight', { configurable: true, value: height });
}

/** Stub `offsetWidth`/`offsetHeight` for an element (happy-dom returns 0). */
export function stubOffsetSize(el: HTMLElement, width: number, height: number): void {
  Object.defineProperty(el, 'offsetWidth', { configurable: true, value: width });
  Object.defineProperty(el, 'offsetHeight', { configurable: true, value: height });
}
