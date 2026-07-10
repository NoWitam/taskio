// @vitest-environment happy-dom
// PageHeader.spec.ts — the additive title-row contract: exactly one heading at
// the requested level, the `size` scale (md default vs tighter sm), and the
// `#meta` chip rendering BESIDE — never inside — the heading (so the heading
// stays plain text for truncation/screen readers). `description` still renders.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import PageHeader from '../PageHeader.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

describe('PageHeader', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('renders a single h1 at the md scale with an h-11 icon bubble by default', () => {
    const wrapper = mount(PageHeader, { props: { title: 'Dashboard', icon: 'settings' } });
    const headings = wrapper.findAll('h1');
    expect(headings).toHaveLength(1);
    expect(headings[0].text()).toBe('Dashboard');
    expect(headings[0].classes()).toContain('text-next-2xl');
    expect(headings[0].classes()).toContain('next-md:text-next-3xl');
    // Leading bubble is the full-size h-11 w-11.
    const bubble = wrapper.get('[aria-hidden="true"]');
    expect(bubble.classes()).toContain('h-11');
    expect(bubble.classes()).toContain('w-11');
  });

  it('size="sm" shrinks the title (no responsive jump) and the icon bubble', () => {
    const wrapper = mount(PageHeader, { props: { title: 'Billing', icon: 'settings', size: 'sm' } });
    const h1 = wrapper.get('h1');
    expect(h1.classes()).toContain('text-next-xl');
    expect(h1.classes()).not.toContain('text-next-2xl');
    expect(h1.classes()).not.toContain('next-md:text-next-3xl');
    const bubble = wrapper.get('[aria-hidden="true"]');
    expect(bubble.classes()).toContain('h-9');
    expect(bubble.classes()).toContain('w-9');
  });

  it('renders the #meta slot beside the title but NOT inside the heading', () => {
    const wrapper = mount(PageHeader, {
      props: { title: 'Onboarding survey' },
      slots: { meta: '<span data-test="meta">Active</span>' },
    });
    const meta = wrapper.get('[data-test="meta"]');
    expect(meta.text()).toBe('Active');
    // Heading stays plain text — the meta chip is a sibling, not a descendant.
    expect(wrapper.get('h1').find('[data-test="meta"]').exists()).toBe(false);
  });

  it('renders a description paragraph from the description prop', () => {
    const wrapper = mount(PageHeader, {
      props: { title: 'Billing', description: 'Manage your plan.' },
    });
    const p = wrapper.get('p');
    expect(p.text()).toBe('Manage your plan.');
  });

  it('renders an h2 when level=2 (existing API regression)', () => {
    const wrapper = mount(PageHeader, { props: { title: 'Billing', level: 2 } });
    expect(wrapper.findAll('h1')).toHaveLength(0);
    expect(wrapper.findAll('h2')).toHaveLength(1);
    expect(wrapper.get('h2').text()).toBe('Billing');
  });
});
