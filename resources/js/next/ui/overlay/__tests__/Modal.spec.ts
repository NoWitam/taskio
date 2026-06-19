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
});
