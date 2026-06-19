// @vitest-environment happy-dom
// useFocusTrap.spec.ts — focuses the first focusable on activate, cycles
// Tab/Shift+Tab within the container, restores focus to the trigger on deactivate,
// and always focuses with { preventScroll: true }.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { ref, nextTick } from 'vue';
import { useFocusTrap } from '../useFocusTrap';
import { withSetup } from '../../../__tests__/helpers/withSetup';
import { installSyncRaf } from '../../../__tests__/helpers/dom';

describe('useFocusTrap', () => {
  let trigger: HTMLButtonElement;
  let container: HTMLElement;
  let first: HTMLButtonElement;
  let last: HTMLButtonElement;

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
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
  });

  function tab(shift = false): void {
    document.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Tab', shiftKey: shift, bubbles: true }),
    );
  }

  it('focuses the first focusable on activate, with preventScroll', async () => {
    const firstSpy = vi.spyOn(first, 'focus');
    const active = ref(false);
    const { unmount } = withSetup(() => {
      useFocusTrap(ref(container), active);
      return {};
    });
    active.value = true;
    await nextTick();
    await Promise.resolve(); // flush the sync rAF in activate()
    expect(firstSpy).toHaveBeenCalledWith({ preventScroll: true });
    expect(document.activeElement).toBe(first);
    unmount();
  });

  it('cycles forward: Tab on the last element wraps to the first', async () => {
    const active = ref(false);
    const { unmount } = withSetup(() => {
      useFocusTrap(ref(container), active);
      return {};
    });
    active.value = true; // false→true so activate() attaches the keydown handler
    await nextTick();
    await Promise.resolve();
    last.focus();
    const firstSpy = vi.spyOn(first, 'focus');
    tab(false);
    expect(firstSpy).toHaveBeenCalledWith({ preventScroll: true });
    unmount();
  });

  it('cycles backward: Shift+Tab on the first element wraps to the last', async () => {
    const active = ref(false);
    const { unmount } = withSetup(() => {
      useFocusTrap(ref(container), active);
      return {};
    });
    active.value = true;
    await nextTick();
    await Promise.resolve();
    first.focus();
    const lastSpy = vi.spyOn(last, 'focus');
    tab(true);
    expect(lastSpy).toHaveBeenCalledWith({ preventScroll: true });
    unmount();
  });

  it('restores focus to the trigger on deactivate', async () => {
    const active = ref(false);
    const { unmount } = withSetup(() => {
      useFocusTrap(ref(container), active);
      return {};
    });
    trigger.focus(); // trigger is the previously-focused element
    active.value = true;
    await nextTick();
    await Promise.resolve();
    expect(document.activeElement).toBe(first); // moved in

    const triggerSpy = vi.spyOn(trigger, 'focus');
    active.value = false;
    await nextTick();
    expect(triggerSpy).toHaveBeenCalledWith({ preventScroll: true });
    unmount();
  });
});
