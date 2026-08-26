// @vitest-environment happy-dom
// useFocusTrap.spec.ts — focuses the first focusable on activate, cycles
// Tab/Shift+Tab within the container, restores focus to the trigger on deactivate,
// always focuses with { preventScroll: true }, and — second describe — lets ONLY the
// topmost of several stacked traps answer Tab.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { ref, nextTick, type Ref } from 'vue';
import { useFocusTrap, focusTrapStackSize } from '../useFocusTrap';
import { withSetup } from '../../../__tests__/helpers/withSetup';
import { installSyncRaf } from '../../../__tests__/helpers/dom';

describe('useFocusTrap', () => {
  let trigger: HTMLButtonElement;
  let container: HTMLElement;
  let first: HTMLButtonElement;
  let last: HTMLButtonElement;
  const mounted: Array<() => void> = [];

  beforeEach(() => {
    installSyncRaf();
    trigger = document.createElement('button');
    trigger.textContent = 'trigger';
    container = document.createElement('div');
    first = document.createElement('button');
    first.textContent = 'first';
    last = document.createElement('button');
    last.textContent = 'last';
    container.append(first, last);
    document.body.append(trigger, container);
    // happy-dom: offsetParent is null for detached/unstyled nodes; the trap filters
    // on offsetParent !== null. Force them visible.
    for (const el of [first, last]) {
      Object.defineProperty(el, 'offsetParent', { configurable: true, value: container });
    }
    trigger.focus();
  });
  afterEach(() => {
    // Teardown lives HERE, not at the end of each body: the trap registry is
    // module-level now, so a trap left behind by a failing assertion would leak into
    // the next test (and the next describe) and turn one signal into a cascade.
    for (const unmount of mounted.splice(0)) unmount();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
    expect(focusTrapStackSize()).toBe(0);
  });

  function tab(shift = false): void {
    document.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Tab', shiftKey: shift, bubbles: true }),
    );
  }

  it('focuses the first focusable on activate, with preventScroll', async () => {
    const firstSpy = vi.spyOn(first, 'focus');
    const active = ref(false);
    mounted.push(
      withSetup(() => {
        useFocusTrap(ref(container), active);
        return {};
      }).unmount,
    );
    active.value = true;
    await nextTick();
    await Promise.resolve(); // flush the sync rAF in activate()
    expect(firstSpy).toHaveBeenCalledWith({ preventScroll: true });
    expect(document.activeElement).toBe(first);
  });

  it('cycles forward: Tab on the last element wraps to the first', async () => {
    const active = ref(false);
    mounted.push(
      withSetup(() => {
        useFocusTrap(ref(container), active);
        return {};
      }).unmount,
    );
    active.value = true; // false→true so activate() attaches the keydown handler
    await nextTick();
    await Promise.resolve();
    last.focus();
    const firstSpy = vi.spyOn(first, 'focus');
    tab(false);
    expect(firstSpy).toHaveBeenCalledWith({ preventScroll: true });
  });

  it('cycles backward: Shift+Tab on the first element wraps to the last', async () => {
    const active = ref(false);
    mounted.push(
      withSetup(() => {
        useFocusTrap(ref(container), active);
        return {};
      }).unmount,
    );
    active.value = true;
    await nextTick();
    await Promise.resolve();
    first.focus();
    const lastSpy = vi.spyOn(last, 'focus');
    tab(true);
    expect(lastSpy).toHaveBeenCalledWith({ preventScroll: true });
  });

  it('restores focus to the trigger on deactivate', async () => {
    const active = ref(false);
    mounted.push(
      withSetup(() => {
        useFocusTrap(ref(container), active);
        return {};
      }).unmount,
    );
    trigger.focus(); // trigger is the previously-focused element
    active.value = true;
    await nextTick();
    await Promise.resolve();
    expect(document.activeElement).toBe(first); // moved in

    const triggerSpy = vi.spyOn(trigger, 'focus');
    active.value = false;
    await nextTick();
    expect(triggerSpy).toHaveBeenCalledWith({ preventScroll: true });
  });
});

// --- Layering: only the TOPMOST trap answers Tab ------------------------------
// Regression: every trap used to register its OWN capture-phase document handler and
// never checked whether it was on top. With a dialog open over a drawer, the drawer's
// handler ran first (registered first), saw focus inside the dialog, judged it "outside
// my container" and pulled focus back down behind the scrim — the first Tab in the top
// layer escaped it (calendar: the scope dialog over the event drawer).
describe('useFocusTrap — stacked traps', () => {
  let outerTrigger: HTMLButtonElement;
  let outer: HTMLElement;
  let outerFirst: HTMLButtonElement;
  let outerLast: HTMLButtonElement;
  let inner: HTMLElement;
  let innerFirst: HTMLButtonElement;
  let innerMiddle: HTMLButtonElement;
  let innerLast: HTMLButtonElement;
  const mounted: Array<() => void> = [];

  function visible(el: HTMLElement, parent: HTMLElement): void {
    Object.defineProperty(el, 'offsetParent', { configurable: true, value: parent });
  }

  beforeEach(() => {
    installSyncRaf();
    outerTrigger = document.createElement('button');
    outer = document.createElement('div');
    outerFirst = document.createElement('button');
    outerLast = document.createElement('button');
    outer.append(outerFirst, outerLast);

    inner = document.createElement('div');
    innerFirst = document.createElement('button');
    innerMiddle = document.createElement('button');
    innerLast = document.createElement('button');
    inner.append(innerFirst, innerMiddle, innerLast);

    document.body.append(outerTrigger, outer, inner);
    for (const el of [outerFirst, outerLast]) visible(el, outer);
    for (const el of [innerFirst, innerMiddle, innerLast]) visible(el, inner);
    outerTrigger.focus();
  });

  afterEach(() => {
    // Teardown lives HERE, not at the end of each body: a failing assertion must not
    // leak a trap into the next test and turn one real signal into a cascade.
    for (const unmount of mounted.splice(0)) unmount();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
    expect(focusTrapStackSize()).toBe(0); // no trap may outlive its component
  });

  /** Mount a trap and flush its activation (watch + the deferred initial focus). */
  async function mountTrap(
    container: HTMLElement,
  ): Promise<{ active: Ref<boolean>; unmount: () => void }> {
    const active = ref(false);
    const { unmount } = withSetup(() => {
      useFocusTrap(ref(container), active);
      return {};
    });
    let disposed = false;
    const unmountOnce = (): void => {
      if (disposed) return;
      disposed = true;
      unmount();
    };
    mounted.push(unmountOnce);
    active.value = true;
    await nextTick();
    await Promise.resolve();
    return { active, unmount: unmountOnce };
  }

  it('leaves the Tab in the top layer: the lower trap does not pull focus out', async () => {
    await mountTrap(outer);
    await mountTrap(inner);

    // Both traps stay ACTIVE — the lower one must not be deactivated, which would
    // restore focus to its own trigger and yank it out of the top layer.
    expect(focusTrapStackSize()).toBe(2);
    expect(document.activeElement).toBe(innerFirst);

    // Focus in the MIDDLE of the top layer: the top trap has nothing to do (no wrap),
    // so nothing at all may move. Before the fix the lower trap moved it to `outerFirst`.
    innerMiddle.focus();
    const outerFirstSpy = vi.spyOn(outerFirst, 'focus');
    const outerLastSpy = vi.spyOn(outerLast, 'focus');
    tab();

    expect(outerFirstSpy).not.toHaveBeenCalled();
    expect(outerLastSpy).not.toHaveBeenCalled();
    expect(document.activeElement).toBe(innerMiddle);
    expect(inner.contains(document.activeElement)).toBe(true);
  });

  it('still cycles within the top layer while a lower trap is active', async () => {
    await mountTrap(outer);
    await mountTrap(inner);

    innerLast.focus();
    const innerFirstSpy = vi.spyOn(innerFirst, 'focus');
    tab();
    expect(innerFirstSpy).toHaveBeenCalledWith({ preventScroll: true });

    innerFirst.focus();
    const innerLastSpy = vi.spyOn(innerLast, 'focus');
    tab(true);
    expect(innerLastSpy).toHaveBeenCalledWith({ preventScroll: true });
  });

  it('hands Tab back to the layer beneath when the top trap closes', async () => {
    await mountTrap(outer);
    const upper = await mountTrap(inner);

    upper.active.value = false;
    await nextTick();
    expect(focusTrapStackSize()).toBe(1);

    // Focus parked outside both containers: the (now topmost) lower trap must pull it
    // back in. If closing the top layer left the lower one muted, nothing would happen.
    outerTrigger.focus();
    const outerFirstSpy = vi.spyOn(outerFirst, 'focus');
    tab();
    expect(outerFirstSpy).toHaveBeenCalledWith({ preventScroll: true });
  });

  it('hands Tab back when the top trap unmounts while still active', async () => {
    await mountTrap(outer);
    const upper = await mountTrap(inner);

    upper.unmount(); // never set active=false — a dead entry must not stay on top
    await nextTick();
    expect(focusTrapStackSize()).toBe(1);

    outerTrigger.focus();
    const outerFirstSpy = vi.spyOn(outerFirst, 'focus');
    tab();
    expect(outerFirstSpy).toHaveBeenCalledWith({ preventScroll: true });
  });

  // Over-eager guard: "only the topmost handles it" must not degrade into "nobody
  // handles it". A lone trap IS the topmost and keeps working exactly as before.
  it('a single trap is itself the topmost and still answers Tab (over-eager guard)', async () => {
    await mountTrap(outer);

    outerTrigger.focus();
    const outerFirstSpy = vi.spyOn(outerFirst, 'focus');
    tab();
    expect(outerFirstSpy).toHaveBeenCalledWith({ preventScroll: true });
  });

  function tab(shift = false): void {
    document.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Tab', shiftKey: shift, bubbles: true }),
    );
  }
});
