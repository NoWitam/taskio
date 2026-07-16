// @vitest-environment happy-dom
// ModuleAside.spec — the shared TWO-LEVEL module sub-navigation: the module
// block + module nav; the resource section as a muted pick-one placeholder
// (linking to the list, with a decorative disabled nav preview) when nothing is
// open, or as a tinted selected block (name + #resource-meta + description)
// PROMOTED ABOVE the module block when a resource is open. Also pins the active
// pill + aria-current, the "soon" row, and the D2 responsive class contract.
import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import ModuleAside, { type ModuleNavItem } from '../ModuleAside.vue';
import { en } from '../../../app/i18n/en';

const RouterLinkStub = {
  name: 'RouterLink',
  props: { to: { type: [String, Object], required: true } },
  template: '<a><slot /></a>',
};

const MODULE_ITEMS: ModuleNavItem[] = [
  { key: 'list', label: 'All bots', icon: 'sparkles', to: { name: 'x.list' } },
];
const RESOURCE_ITEMS: ModuleNavItem[] = [
  { key: 'inbox', label: 'Inbox', icon: 'inbox', to: { name: 'x.inbox' } },
  { key: 'config', label: 'Config', icon: 'settings', to: { name: 'x.config' } },
];
const PLACEHOLDER = { icon: 'sparkles' as const, label: 'Select a bot', hint: 'Pick from the list', to: { name: 'x.list' } };

function mountAside(props: Record<string, unknown> = {}, slots: Record<string, string> = {}) {
  return mount(ModuleAside, {
    props: {
      moduleIcon: 'sparkles',
      moduleTitle: 'Bots',
      moduleHint: 'What bots are for',
      moduleItems: MODULE_ITEMS,
      activeMatch: (item: ModuleNavItem) => item.key === 'config',
      ...props,
    },
    slots,
    global: { components: { RouterLink: RouterLinkStub } },
  });
}

afterEach(() => {
  document.body.innerHTML = '';
});

describe('ModuleAside — module block', () => {
  it('always renders the module header (icon bubble + title + hint) and the labelled module nav', () => {
    const wrapper = mountAside();
    expect(wrapper.find('h2').text()).toBe('Bots');
    expect(wrapper.text()).toContain('What bots are for');
    expect(wrapper.find(`nav[aria-label="${en.common.moduleNav}"]`).exists()).toBe(true);
  });

  it('marks the active item with the subtle-primary pill and aria-current', () => {
    const wrapper = mountAside({
      resourceItems: RESOURCE_ITEMS,
      resource: { icon: 'sparkles', name: 'Bot Y' },
      resourceNavLabel: 'Bot sections',
    });
    const links = wrapper.findAllComponents(RouterLinkStub);
    const inbox = links.find((l) => (l.props('to') as { name?: string })?.name === 'x.inbox')!;
    const config = links.find((l) => (l.props('to') as { name?: string })?.name === 'x.config')!;
    expect(config.classes()).toContain('bg-next-primary-subtle');
    expect(config.attributes('aria-current')).toBe('page');
    expect(inbox.classes()).not.toContain('bg-next-primary-subtle');
    expect(inbox.attributes('aria-current')).toBeUndefined();
  });

  it('renders a "soon" module item as a disabled row, not a link', () => {
    const wrapper = mountAside({
      moduleItems: [...MODULE_ITEMS, { key: 'soon', label: 'Later', icon: 'clock', soon: true }],
    });
    expect(wrapper.text()).toContain('Later');
    expect(wrapper.text()).toContain(en.nav.comingSoon);
    const soonLink = wrapper.findAllComponents(RouterLinkStub).find((l) => l.text().includes('Later'));
    expect(soonLink).toBeUndefined();
  });

  it('carries the D2 responsive contract: hidden below next-lg, flex above', () => {
    const wrapper = mountAside();
    expect(wrapper.classes()).toContain('hidden');
    expect(wrapper.classes()).toContain('next-lg:flex');
  });
});

describe('ModuleAside — resource section: placeholder state', () => {
  it('with no resource renders the pick-one placeholder LINKING to the list', () => {
    const wrapper = mountAside({ resourceItems: RESOURCE_ITEMS, resourcePlaceholder: PLACEHOLDER });

    expect(wrapper.text()).toContain('Select a bot');
    expect(wrapper.text()).toContain('Pick from the list');

    const placeholderLink = wrapper
      .findAllComponents(RouterLinkStub)
      .find((l) => l.text().includes('Select a bot'));
    expect(placeholderLink).toBeTruthy();
    expect((placeholderLink!.props('to') as { name?: string }).name).toBe('x.list');
    // Muted, dashed empty-slot look.
    expect(placeholderLink!.classes()).toContain('border-dashed');
  });

  it('previews the resource nav as decorative disabled rows (no links, hidden from AT)', () => {
    const wrapper = mountAside({ resourceItems: RESOURCE_ITEMS, resourcePlaceholder: PLACEHOLDER });

    const preview = wrapper.find('div[aria-hidden="true"]');
    expect(preview.exists()).toBe(true);
    expect(preview.text()).toContain('Inbox');
    expect(preview.text()).toContain('Config');
    // No resource links exist in placeholder state.
    const resourceLink = wrapper
      .findAllComponents(RouterLinkStub)
      .find((l) => (l.props('to') as { name?: string })?.name === 'x.inbox');
    expect(resourceLink).toBeUndefined();
  });

  it('renders no resource section at all when the module has no resource pages', () => {
    const wrapper = mountAside(); // no resourceItems
    expect(wrapper.find('.border-dashed').exists()).toBe(false);
    expect(wrapper.findAll('nav')).toHaveLength(1); // just the module nav
  });
});

describe('ModuleAside — resource section: selected state', () => {
  it('promotes the selected block ABOVE the module block, with name, meta slot and clamped description', () => {
    const wrapper = mountAside(
      {
        resourceItems: RESOURCE_ITEMS,
        resource: { icon: 'sparkles', name: 'Bot Y', description: 'Does helpful things' },
        resourceNavLabel: 'Bot sections',
      },
      { 'resource-meta': '<span data-testid="meta-slot">Active</span>' },
    );

    // Selected block content.
    const headings = wrapper.findAll('h2').map((h) => h.text());
    expect(headings).toEqual(['Bot Y', 'Bots']); // resource block FIRST, module block after
    expect(wrapper.find('[data-testid="meta-slot"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Does helpful things');

    // The live resource nav is labelled and linked; no placeholder remains.
    expect(wrapper.find('nav[aria-label="Bot sections"]').exists()).toBe(true);
    expect(wrapper.text()).not.toContain('Select a bot');
    const inboxLink = wrapper
      .findAllComponents(RouterLinkStub)
      .find((l) => (l.props('to') as { name?: string })?.name === 'x.inbox');
    expect(inboxLink).toBeTruthy();
  });

  it('the selected block carries the tinted "selected" background', () => {
    const wrapper = mountAside({
      resourceItems: RESOURCE_ITEMS,
      resource: { icon: 'sparkles', name: 'Bot Y' },
    });
    const selectedBlock = wrapper
      .findAll('div')
      .find((d) => d.classes().includes('bg-next-primary-subtle') && d.text().includes('Bot Y'));
    expect(selectedBlock).toBeTruthy();
  });

  it('renders the back-to-list icon button with its accessible name', () => {
    const wrapper = mountAside({
      resourceItems: RESOURCE_ITEMS,
      resource: { icon: 'sparkles', name: 'Bot Y' },
      resourceBack: { label: 'All bots', to: { name: 'x.list' } },
    });
    const back = wrapper
      .findAllComponents(RouterLinkStub)
      .find((l) => l.attributes('aria-label') === 'All bots');
    expect(back).toBeTruthy();
    expect((back!.props('to') as { name?: string }).name).toBe('x.list');
  });

  it('the two nav landmarks never share one accessible name (even on fallbacks)', () => {
    const wrapper = mountAside({
      resourceItems: RESOURCE_ITEMS,
      resource: { icon: 'sparkles', name: 'Bot Y' },
      // No resourceNavLabel on purpose — the fallback must still be distinct.
    });
    const labels = wrapper.findAll('nav').map((n) => n.attributes('aria-label'));
    expect(labels).toHaveLength(2);
    expect(new Set(labels).size).toBe(2);
    expect(labels).toContain(en.common.resourceNav);
    expect(labels).toContain(en.common.moduleNav);
  });
});
