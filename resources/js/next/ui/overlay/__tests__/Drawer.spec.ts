// @vitest-environment happy-dom
// Drawer.spec.ts — ONLY the mounted-already-open contract, deliberately. The drawer shares
// Modal's overlay machinery line for line, and Modal.spec.ts owns the full surface; what this
// file pins is the one repair whose highest-stakes call site is a DRAWER: the composer reached
// by deep link (`?new=1` / `?edit=<id>`) is open at first render, and before the
// `immediate: true` fix it never registered — Escape dead, page behind scrolling. Measured by
// the B8 DOM-test batch; kept red-able here so the two components cannot drift apart.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import Drawer from '../Drawer.vue';
import { installBrowserMocks, restoreBrowserMocks, installSyncRaf } from '../../../__tests__/helpers/dom';

function pressEscape(): void {
  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
}

describe('Drawer (mounted already open — the deep-link shape)', () => {
  beforeEach(() => {
    installBrowserMocks();
    installSyncRaf();
    document.body.innerHTML = '';
    delete document.body.dataset.nextModalLocks;
    delete document.body.dataset.nextPrevOverflow;
    document.body.style.overflow = '';
  });
  afterEach(() => restoreBrowserMocks());

  it('registers when mounted already open: Escape closes it and the body is locked', async () => {
    const wrapper = mount(Drawer, {
      props: { open: true, ariaLabel: 'Deep-linked composer' },
      slots: { default: 'Body' },
    });
    await nextTick();

    expect(document.body.style.overflow).toBe('hidden');
    expect(wrapper.emitted('open')).toBeTruthy();

    pressEscape();
    await nextTick();

    expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
    wrapper.unmount();
  });

  it('mounting a CLOSED drawer neither emits close nor touches somebody else\'s body lock', async () => {
    document.body.dataset.nextModalLocks = '1';
    document.body.style.overflow = 'hidden';

    const wrapper = mount(Drawer, {
      props: { open: false },
      slots: { default: 'Body' },
    });
    await nextTick();

    expect(document.body.dataset.nextModalLocks).toBe('1');
    expect(document.body.style.overflow).toBe('hidden');
    expect(wrapper.emitted('close')).toBeFalsy();
    wrapper.unmount();
  });
});
