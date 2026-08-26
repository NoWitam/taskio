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

  // --- Inert controls stay hit-testable so they can explain themselves ---------
  // Regression: the inert state used to include `pointer-events-none`. A browser only
  // paints the native `title` tooltip for an element it HIT-TESTS, so every caller that
  // followed the house rule ("a disabled control explains itself") had its explanation
  // silently cancelled by the primitive — e.g. the calendar's Save button, whose title
  // carries the only reason the save is impossible, and the "Today" nav button.
  describe('inert (disabled / loading) controls', () => {
    it('is hit-testable while disabled, so its `title` tooltip can appear', () => {
      const wrapper = mount(Button, {
        props: { disabled: true },
        attrs: { title: 'Fill in a title first' },
        slots: { default: () => 'Save' },
      });

      const cls = wrapper.classes();
      expect(cls).not.toContain('pointer-events-none');
      expect(cls).toContain('cursor-not-allowed');
      // The reason must ride on the element the pointer actually hits.
      expect(wrapper.attributes('title')).toBe('Fill in a title first');
    });

    it('is hit-testable while loading too', () => {
      const wrapper = mount(Button, {
        props: { loading: true },
        slots: { default: () => 'Save' },
      });

      expect(wrapper.classes()).not.toContain('pointer-events-none');
    });

    it('drops hover/active utilities while inert so a dead control never lights up', () => {
      const wrapper = mount(Button, {
        props: { variant: 'primary', disabled: true },
        slots: { default: () => 'Save' },
      });

      const cls = wrapper.classes();
      expect(cls.some((c) => c.startsWith('hover:'))).toBe(false);
      expect(cls.some((c) => c.startsWith('active:'))).toBe(false);
      expect(cls).toContain('bg-next-primary'); // the resting look is untouched
    });

    // Over-eager guard: stripping hover/active must apply ONLY while inert.
    it('keeps hover/active utilities when enabled', () => {
      const wrapper = mount(Button, {
        props: { variant: 'primary' },
        slots: { default: () => 'Save' },
      });

      const cls = wrapper.classes();
      expect(cls).toContain('hover:bg-next-primary-hover');
      expect(cls).toContain('active:bg-next-primary-active');
      expect(cls).toContain('cursor-pointer');
      expect(cls).not.toContain('cursor-not-allowed');
    });

    it('a disabled button swallows the click: it neither emits nor reaches an ancestor', async () => {
      const onAncestor = vi.fn();
      const wrapper = mount(
        {
          components: { Button },
          setup: () => ({ onAncestor }),
          template:
            '<div @click="onAncestor"><Button disabled title="why">Save</Button></div>',
        },
        { attachTo: document.body },
      );

      await wrapper.find('button').trigger('click');

      expect(wrapper.findComponent(Button).emitted('click')).toBeUndefined();
      // Under `pointer-events: none` the click fell THROUGH to whatever sat behind.
      expect(onAncestor).not.toHaveBeenCalled();
      wrapper.unmount();
    });

    it('a loading button swallows the click as well, and keeps its tab order + aria-busy', async () => {
      const onAncestor = vi.fn();
      const wrapper = mount(
        {
          components: { Button },
          setup: () => ({ onAncestor }),
          template: '<div @click="onAncestor"><Button loading>Save</Button></div>',
        },
        { attachTo: document.body },
      );

      const button = wrapper.find('button');
      await button.trigger('click');

      expect(wrapper.findComponent(Button).emitted('click')).toBeUndefined();
      expect(onAncestor).not.toHaveBeenCalled();
      // Unchanged from before: loading is NOT natively disabled, so the control keeps
      // its place in the tab order (and Enter/Space stay blocked by the same guard).
      expect((button.element as HTMLButtonElement).disabled).toBe(false);
      expect(button.attributes('aria-busy')).toBe('true');
      expect(button.attributes('aria-disabled')).toBe('true');
      wrapper.unmount();
    });

    it('a disabled button stays out of the tab order via the native attribute', () => {
      const wrapper = mount(Button, {
        props: { disabled: true },
        slots: { default: () => 'Save' },
      });

      expect((wrapper.element as HTMLButtonElement).disabled).toBe(true);
      expect(wrapper.attributes('aria-disabled')).toBe('true');
    });

    it('a disabled split button keeps both segments hit-testable under the titled root', () => {
      const wrapper = mount(Button, {
        props: { menuItems: MENU, menuAriaLabel: 'More', disabled: true },
        attrs: { title: 'Nothing to save yet' },
        slots: { default: () => 'Save' },
      });

      // The group wrapper is the single root, so the fallthrough `title` lands there;
      // a native tooltip is inherited by the segments the pointer actually hits — but
      // only because those segments are hit-tested at all.
      expect(wrapper.attributes('title')).toBe('Nothing to save yet');
      for (const segment of wrapper.element.querySelectorAll('button')) {
        expect(segment.className).not.toContain('pointer-events-none');
      }
    });

    it('an inert anchor loses its href (not focusable, not navigable) and emits nothing', async () => {
      const wrapper = mount(Button, {
        props: { href: '/somewhere', disabled: true },
        slots: { default: () => 'Open' },
      });

      expect(wrapper.element.tagName).toBe('A');
      expect(wrapper.attributes('href')).toBeUndefined();
      expect(wrapper.attributes('aria-disabled')).toBe('true');
      expect(wrapper.classes()).not.toContain('pointer-events-none');

      await wrapper.trigger('click');
      expect(wrapper.emitted('click')).toBeUndefined();
    });
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
