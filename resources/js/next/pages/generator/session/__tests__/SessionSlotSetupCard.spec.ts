// @vitest-environment happy-dom
// SessionSlotSetupCard.spec — the opening "Inputs" turn of the session chat.
//
// What matters here:
//   1. The COLLAPSED summary is a definition LIST, not chips. Real slot values are whole paragraphs; the
//      old pill treatment (`rounded-next-full` + a muted background) wrapped onto several lines with a
//      huge radius and read as broken layout. Each row must be a `dt` (slot name) + a `dd` clamped to two
//      lines with `break-words`, whatever the value's length — this is the regression guard on that fix.
//   2. `summarize()` semantics are UNCHANGED: an empty value renders the em-dash placeholder, a list its
//      item count, a file object its name.
//   3. The toggle carries the shared chevron + aria contract, and the form body is hidden with `v-show`
//      (never unmounted), so nothing typed / picked is lost by collapsing.
//   4. A `draft` has NO toggle: its body holds the only Generate button, so collapsing there would bury
//      the screen's critical action.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setLocale } from '../../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../../__tests__/helpers/dom';

import SessionSlotSetupCard from '../SessionSlotSetupCard.vue';
import type { TemplateSlot } from '../../types';

/** A 260-character paragraph — the shape of a real brief, and what broke the old chip layout. */
const LONG_VALUE =
  'A warm, practical spring post about starting a balcony vegetable garden with one tool and a single ' +
  'afternoon of work, aimed at first-time gardeners who think they have neither the space nor the time ' +
  'for it, ending on a gentle call to pre-order the kit.';

const slots: TemplateSlot[] = [
  { name: 'brief', descriptor: { base: 'text' } as TemplateSlot['descriptor'] },
  { name: 'audience', descriptor: { base: 'text' } as TemplateSlot['descriptor'] },
];

function mountCard(props: Record<string, unknown> = {}) {
  return mount(SessionSlotSetupCard, {
    props: {
      slots,
      status: 'ready',
      canEdit: true,
      modelValue: { brief: LONG_VALUE, audience: 'First-time balcony gardeners' },
      ...props,
    },
    attachTo: document.body,
  });
}

/** The header's collapse/expand control. */
function toggleOf(wrapper: ReturnType<typeof mountCard>) {
  return wrapper.get('.next-card__header-actions button');
}

describe('SessionSlotSetupCard', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  describe('collapsed summary (owner note #1: long values must not look broken)', () => {
    it('renders a definition list — a dt per slot name and a clamped dd per value', () => {
      const wrapper = mountCard();
      const rows = wrapper.findAll('dl > div');
      expect(rows).toHaveLength(2);

      expect(rows[0].get('dt').text()).toBe('brief');
      const value = rows[0].get('dd');
      expect(value.text()).toBe(LONG_VALUE);
      // Two-line clamp + word breaking: a 260-character paragraph and a one-word value have the SAME
      // row shape, and an unbroken string can never widen the card.
      expect(value.classes()).toContain('line-clamp-2');
      expect(value.classes()).toContain('break-words');
      wrapper.unmount();
    });

    it('uses NO pill treatment on the values (the thing that looked broken)', () => {
      const wrapper = mountCard();
      const html = wrapper.html();
      expect(html).not.toContain('rounded-next-full');
      // No background on the value itself — a paragraph-sized filled shape is the whole problem.
      for (const dd of wrapper.findAll('dd')) {
        expect(dd.classes().some((c) => c.startsWith('bg-'))).toBe(false);
      }
      wrapper.unmount();
    });

    it('keeps the counter line unchanged', async () => {
      const wrapper = mountCard();
      expect(wrapper.text()).toContain('2 inputs');
      setLocale('pl');
      await wrapper.vm.$nextTick();
      expect(wrapper.text()).toContain('Dane wejściowe: 2');
      setLocale('en');
      wrapper.unmount();
    });

    it('preserves summarize(): empty → em dash, list → item count, file object → its name', () => {
      const wrapper = mountCard({
        slots: [
          { name: 'brief', descriptor: { base: 'text' } as TemplateSlot['descriptor'] },
          { name: 'tags', descriptor: { base: 'text' } as TemplateSlot['descriptor'] },
          { name: 'hero', descriptor: { base: 'file' } as TemplateSlot['descriptor'] },
        ],
        modelValue: { brief: '', tags: ['a', 'b', 'c'], hero: { id: 'f1', name: 'poster.png' } },
      });
      const values = wrapper.findAll('dd').map((d) => d.text());
      expect(values).toEqual(['—', '3 items', 'poster.png']);
      wrapper.unmount();
    });

    it('renders a value containing markup as TEXT, never as elements', () => {
      const wrapper = mountCard({ modelValue: { brief: '<img src=x onerror="alert(1)">', audience: 'x' } });
      expect(wrapper.find('dd img').exists()).toBe(false);
      expect(wrapper.get('dd').text()).toContain('<img src=x onerror="alert(1)">');
      wrapper.unmount();
    });
  });

  describe('toggle', () => {
    it('collapsed after a generate: the toggle exposes aria-expanded + a resolving aria-controls', () => {
      const wrapper = mountCard();
      const toggle = toggleOf(wrapper);
      expect(toggle.attributes('aria-expanded')).toBe('false');

      const body = wrapper.get(`#${toggle.attributes('aria-controls')}`);
      expect((body.element as HTMLElement).style.display).toBe('none');
      wrapper.unmount();
    });

    it('hides the form with v-show — the inputs stay MOUNTED across a collapse', async () => {
      const wrapper = mountCard();
      const toggle = toggleOf(wrapper);
      const selector = `#${toggle.attributes('aria-controls')}`;

      // Collapsed: present in the DOM, just not displayed.
      expect(wrapper.find(selector).exists()).toBe(true);

      await toggle.trigger('click');
      expect(toggleOf(wrapper).attributes('aria-expanded')).toBe('true');
      expect((wrapper.get(selector).element as HTMLElement).style.display).not.toBe('none');

      await toggleOf(wrapper).trigger('click');
      expect((wrapper.get(selector).element as HTMLElement).style.display).toBe('none');
      // Still mounted — a v-if regression would drop the element entirely.
      expect(wrapper.find(selector).exists()).toBe(true);
      wrapper.unmount();
    });

    it('labels the expand as "Edit inputs" while editing is possible, and "Show" when it is not', async () => {
      const editable = mountCard();
      expect(toggleOf(editable).text()).toBe('Edit inputs');
      await toggleOf(editable).trigger('click');
      expect(toggleOf(editable).text()).toBe('Hide');
      editable.unmount();

      const readOnly = mountCard({ canEdit: false });
      expect(toggleOf(readOnly).text()).toBe('Show');
      readOnly.unmount();
    });

    it('a draft has NO toggle (its body holds the only Generate button)', () => {
      const wrapper = mountCard({ status: 'draft' });
      expect(wrapper.find('.next-card__header-actions button').exists()).toBe(false);
      expect(wrapper.findAll('button').some((b) => b.text() === 'Generate')).toBe(true);
      wrapper.unmount();
    });
  });
});
