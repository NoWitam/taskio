// @vitest-environment happy-dom
// Button.spec.ts — the split-button („przybornik") extension, and a REGRESSION PIN that a
// menu-less Button still renders its original single root (Button has ~114 consumers; the
// group wrapper may exist ONLY when menuItems is passed).
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Button from '../Button.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const MENU = [
  { value: 'save-as', label: 'Save as…' },
  { value: 'export', label: 'Export', disabled: true },
];

function chevronOf(wrapper: { element: Element }): HTMLButtonElement | null {
  return (
    Array.from(wrapper.element.querySelectorAll<HTMLButtonElement>('button')).find(
      (b) => b.getAttribute('aria-haspopup') === 'menu',
    ) ?? null
  );
}

describe('Button', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => {
    restoreBrowserMocks();
    document.body.innerHTML = '';
  });

  it('renders a single <button> root when no menuItems are passed (regression pin)', () => {
    const wrapper = mount(Button, { slots: { default: () => 'Save' } });

    expect(wrapper.element.tagName).toBe('BUTTON');
    expect(wrapper.element.classList.contains('next-button-group')).toBe(false);
  });

  it('renders a group with a main segment and an aria-labelled chevron when menuItems are passed', () => {
    const wrapper = mount(Button, {
      props: { menuItems: MENU, menuAriaLabel: 'More save options' },
      slots: { default: () => 'Save' },
    });

    expect(wrapper.element.tagName).toBe('DIV');
    expect(wrapper.element.classList.contains('next-button-group')).toBe(true);

    const chevron = chevronOf(wrapper);
    expect(chevron).not.toBeNull();
    expect(chevron!.getAttribute('aria-label')).toBe('More save options');
  });

  it('clicking the main segment emits click only', async () => {
    const wrapper = mount(Button, {
      props: { menuItems: MENU, menuAriaLabel: 'More' },
      slots: { default: () => 'Save' },
    });

    await wrapper.find('button').trigger('click');

    expect(wrapper.emitted('click')).toHaveLength(1);
    expect(wrapper.emitted('menu-select')).toBeUndefined();
  });

  it('selecting a menu item emits menu-select with its value', async () => {
    const wrapper = mount(Button, {
      props: { menuItems: MENU, menuAriaLabel: 'More' },
      slots: { default: () => 'Save' },
      attachTo: document.body,
    });

    chevronOf(wrapper)!.click();
    await wrapper.vm.$nextTick();

    // The menu panel is teleported to body.
    const item = Array.from(document.body.querySelectorAll('[role="menuitem"]')).find(
      (el) => (el.textContent ?? '').includes('Save as…'),
    ) as HTMLElement | undefined;
    expect(item).toBeTruthy();

    item!.click();
    await wrapper.vm.$nextTick();

    expect(wrapper.emitted('menu-select')).toEqual([['save-as']]);
    wrapper.unmount();
  });

  it('disabled makes BOTH segments inert', async () => {
    const wrapper = mount(Button, {
      props: { menuItems: MENU, menuAriaLabel: 'More', disabled: true },
      slots: { default: () => 'Save' },
    });

    const buttons = wrapper.element.querySelectorAll('button');
    expect((buttons[0] as HTMLButtonElement).disabled).toBe(true);
    expect(chevronOf(wrapper)!.disabled).toBe(true);

    await wrapper.find('button').trigger('click');
    expect(wrapper.emitted('click')).toBeUndefined();
  });

  it('menuItems with href warns and falls back to the single anchor root', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const wrapper = mount(Button, {
      props: { href: '/somewhere', menuItems: MENU, menuAriaLabel: 'More' },
      slots: { default: () => 'Open' },
    });

    expect(wrapper.element.tagName).toBe('A');
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('not supported with `href`'));
    warn.mockRestore();
  });
});
