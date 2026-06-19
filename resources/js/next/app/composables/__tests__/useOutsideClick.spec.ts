// @vitest-environment happy-dom
// useOutsideClick.spec.ts — fires on outside pointerdown/focusin, treats events
// inside ANY passed ref (including a "teleported" panel ref) as inside, only binds
// while active, and cleans up on deactivate/unmount.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { ref, nextTick } from 'vue';
import { useOutsideClick } from '../useOutsideClick';
import { withSetup } from '../../../__tests__/helpers/withSetup';

describe('useOutsideClick', () => {
  let trigger: HTMLElement;
  let panel: HTMLElement; // simulates a teleported popover, NOT a child of trigger
  let outside: HTMLElement;

  beforeEach(() => {
    trigger = document.createElement('button');
    panel = document.createElement('div');
    const child = document.createElement('span');
    panel.appendChild(child);
    outside = document.createElement('div');
    document.body.append(trigger, panel, outside);
  });
  afterEach(() => {
    document.body.innerHTML = '';
  });

  function fire(target: HTMLElement, type: 'pointerdown' | 'focusin'): void {
    target.dispatchEvent(new Event(type, { bubbles: true }));
  }

  // NOTE: the composable binds via watch(active) WITHOUT { immediate: true }, so
  // listeners attach on the false→true transition (matching its real consumers,
  // whose `open`/`active` ref starts false). Tests activate explicitly.
  it('fires on an outside pointerdown', async () => {
    const handler = vi.fn();
    const active = ref(false);
    const { unmount } = withSetup(() => {
      useOutsideClick([ref(trigger), ref(panel)], handler, active);
      return {};
    });
    active.value = true;
    await nextTick();
    fire(outside, 'pointerdown');
    expect(handler).toHaveBeenCalledTimes(1);
    unmount();
  });

  it('fires on an outside focusin', async () => {
    const handler = vi.fn();
    const active = ref(false);
    const { unmount } = withSetup(() => {
      useOutsideClick([ref(trigger), ref(panel)], handler, active);
      return {};
    });
    active.value = true;
    await nextTick();
    fire(outside, 'focusin');
    expect(handler).toHaveBeenCalledTimes(1);
    unmount();
  });

  it('ignores clicks inside ANY ref, including a teleported panel descendant', async () => {
    const handler = vi.fn();
    const active = ref(false);
    const { unmount } = withSetup(() => {
      useOutsideClick([ref(trigger), ref(panel)], handler, active);
      return {};
    });
    active.value = true;
    await nextTick();
    fire(trigger, 'pointerdown');
    fire(panel.firstElementChild as HTMLElement, 'pointerdown'); // deep inside the panel
    expect(handler).not.toHaveBeenCalled();
    unmount();
  });

  it('only binds while active and rebinds when reactivated', async () => {
    const handler = vi.fn();
    const active = ref(false);
    const { unmount } = withSetup(() => {
      useOutsideClick(ref(trigger), handler, active);
      return {};
    });
    await nextTick();
    fire(outside, 'pointerdown');
    expect(handler).not.toHaveBeenCalled(); // not bound yet

    active.value = true;
    await nextTick();
    fire(outside, 'pointerdown');
    expect(handler).toHaveBeenCalledTimes(1);

    active.value = false;
    await nextTick();
    fire(outside, 'pointerdown');
    expect(handler).toHaveBeenCalledTimes(1); // unbound again
    unmount();
  });

  it('cleans up listeners on unmount', async () => {
    const handler = vi.fn();
    const active = ref(false);
    const { unmount } = withSetup(() => {
      useOutsideClick(ref(trigger), handler, active);
      return {};
    });
    active.value = true;
    await nextTick();
    unmount();
    fire(outside, 'pointerdown');
    expect(handler).not.toHaveBeenCalled();
  });
});
