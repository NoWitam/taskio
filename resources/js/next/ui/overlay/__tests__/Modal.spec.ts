// @vitest-environment happy-dom
// Modal.spec.ts — opens/teleports to <body>, Esc closes (when enabled) via the
// overlay stack, focus moves into the panel and restores on close, and the body
// scroll lock is applied while open and released on close.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import Modal from '../Modal.vue';
import { installBrowserMocks, restoreBrowserMocks, installSyncRaf } from '../../../__tests__/helpers/dom';

function pressEscape(): void {
  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
}

describe('Modal', () => {
  beforeEach(() => {
    installBrowserMocks();
    installSyncRaf();
    document.body.innerHTML = '';
    // happy-dom defaults; ensure a clean lock state.
    delete document.body.dataset.nextModalLocks;
    delete document.body.dataset.nextPrevOverflow;
    document.body.style.overflow = '';
  });
  afterEach(() => restoreBrowserMocks());

  it('does not throw and renders nothing when closed', () => {
    const wrapper = mount(Modal, {
      props: { open: false },
      slots: { default: 'Body' },
    });
    expect(document.querySelector('[role="dialog"]')).toBeNull();
    wrapper.unmount();
  });

  it('opens, teleports a role=dialog to <body>, and locks body scroll', async () => {
    const wrapper = mount(Modal, {
      props: { open: false, ariaLabel: 'Demo' },
      slots: { default: '<button class="inner">Inner</button>' },
    });
    await wrapper.setProps({ open: true });
    await nextTick();

    const dialog = document.body.querySelector('[role="dialog"]');
    expect(dialog).not.toBeNull();
    expect(dialog?.getAttribute('aria-modal')).toBe('true');
    expect(wrapper.emitted('open')).toBeTruthy();
    // Body scroll lock applied.
    expect(document.body.style.overflow).toBe('hidden');
    expect(document.body.dataset.nextModalLocks).toBe('1');
    wrapper.unmount();
  });

  it('releases the body scroll lock on close', async () => {
    const wrapper = mount(Modal, {
      props: { open: false },
      slots: { default: 'Body' },
    });
    await wrapper.setProps({ open: true });
    await nextTick();
    expect(document.body.style.overflow).toBe('hidden');

    await wrapper.setProps({ open: false });
    await nextTick();
    expect(document.body.style.overflow).toBe('');
    expect(document.body.dataset.nextModalLocks).toBeUndefined();
    expect(wrapper.emitted('close')).toBeTruthy();
    wrapper.unmount();
  });

  it('moves focus into the panel on open and restores it to the trigger on close', async () => {
    const trigger = document.createElement('button');
    document.body.appendChild(trigger);
    trigger.focus();
    expect(document.activeElement).toBe(trigger);

    const wrapper = mount(Modal, {
      props: { open: false },
      slots: { default: '<button class="inner">Inner</button>' },
    });
    await wrapper.setProps({ open: true });
    await nextTick();
    await Promise.resolve(); // flush the focus-trap rAF

    // Contract: focus moves INTO the dialog panel (the first focusable — which is
    // the auto close button when showClose is on). Assert containment, not a
    // specific element, so the close-button affordance doesn't fail this.
    const panel = document.body.querySelector('[role="dialog"]') as HTMLElement;
    expect(panel).toBeTruthy();
    expect(document.activeElement).not.toBe(trigger);
    expect(panel.contains(document.activeElement)).toBe(true);

    await wrapper.setProps({ open: false });
    await nextTick();
    expect(document.activeElement).toBe(trigger); // restored
    wrapper.unmount();
  });

  it('Escape closes when closeOnEsc is enabled (topmost overlay)', async () => {
    const wrapper = mount(Modal, {
      props: {
        open: false,
        closeOnEsc: true,
        'onUpdate:open': (v: boolean) => wrapper.setProps({ open: v }),
      },
      slots: { default: 'Body' },
    });
    await wrapper.setProps({ open: true });
    await nextTick();
    pressEscape();
    await nextTick();
    expect(wrapper.props('open')).toBe(false);
    wrapper.unmount();
  });

  it('Escape does NOT close when closeOnEsc is disabled', async () => {
    const wrapper = mount(Modal, {
      props: {
        open: false,
        closeOnEsc: false,
        'onUpdate:open': (v: boolean) => wrapper.setProps({ open: v }),
      },
      slots: { default: 'Body' },
    });
    await wrapper.setProps({ open: true });
    await nextTick();
    pressEscape();
    await nextTick();
    expect(wrapper.props('open')).toBe(true); // stayed open
    wrapper.unmount();
  });

  it('clicking the scrim closes when closeOnScrim is enabled', async () => {
    const wrapper = mount(Modal, {
      props: {
        open: false,
        closeOnScrim: true,
        'onUpdate:open': (v: boolean) => wrapper.setProps({ open: v }),
      },
      slots: { default: 'Body' },
    });
    await wrapper.setProps({ open: true });
    await nextTick();
    const scrim = document.body.querySelector('[aria-hidden="true"]') as HTMLElement;
    scrim.dispatchEvent(new Event('click', { bubbles: true }));
    await nextTick();
    expect(wrapper.props('open')).toBe(false);
    wrapper.unmount();
  });

  // ── mounted-already-open (the deep-link shape) ───────────────────────────────
  //
  // A watcher does not fire for the initial value, so an overlay whose `open` is TRUE at mount
  // used to skip registration entirely: Escape dead, page behind scrolling, no `open` event. That
  // is exactly how a deep-linked composer or a route-driven preview arrives. Measured by the B8
  // DOM-test batch before the fix; `immediate: true` (with a guarded teardown branch) is the fix.

  it('registers when mounted already open: Escape closes it and the body is locked', async () => {
    const wrapper = mount(Modal, {
      props: { open: true, ariaLabel: 'Deep link' },
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

  it('mounting a CLOSED modal neither emits close nor touches somebody else\'s body lock', async () => {
    // Another overlay's lock is live.
    document.body.dataset.nextModalLocks = '1';
    document.body.style.overflow = 'hidden';

    const wrapper = mount(Modal, {
      props: { open: false },
      slots: { default: 'Body' },
    });
    await nextTick();

    // The guarded teardown branch must be a no-op: without the guard, `immediate` would decrement
    // the SHARED counter this instance never incremented, unfreezing the page behind an open modal.
    expect(document.body.dataset.nextModalLocks).toBe('1');
    expect(document.body.style.overflow).toBe('hidden');
    expect(wrapper.emitted('close')).toBeFalsy();
    wrapper.unmount();
  });
});
