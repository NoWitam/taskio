// @vitest-environment happy-dom
// BotsModuleLayout.spec — the two-level module aside: the pick-a-bot
// placeholder (linking to the list) + decorative section preview on the list
// route, the SELECTED bot block (identity + status) promoted above the module
// block on a detail child route, the active-tab derivation from the route-name
// suffix, and sectionLink() targeting the named child routes while PRESERVING
// the query minus any legacy `section` key. The store + vue-router are mocked;
// RouterLink is a props-capturing stub so the link targets can be asserted; the
// REAL i18n renders the copy.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { ref } from 'vue';
import BotsModuleLayout from '../BotsModuleLayout.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { en } from '../../../app/i18n/en';
import type { BotDetail } from '../types';

// --- Store + router mocks ----------------------------------------------------
const detailRef = ref<Partial<BotDetail> | null>(null);
const fetchBot = vi.fn();
vi.mock('../../../app/stores/bots', () => ({
  useBotsStore: () => ({
    get detail() {
      return detailRef.value;
    },
    fetchBot,
  }),
}));

const routeName = ref<string>('next.bots');
const routeParams = ref<Record<string, unknown>>({});
const routeQuery = ref<Record<string, unknown>>({});
vi.mock('vue-router', () => ({
  useRoute: () => ({
    get name() {
      return routeName.value;
    },
    get params() {
      return routeParams.value;
    },
    get query() {
      return routeQuery.value;
    },
  }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}));

interface LinkTarget {
  name?: string;
  params?: Record<string, unknown>;
  query?: Record<string, unknown>;
}
const RouterLinkStub = {
  name: 'RouterLink',
  props: { to: { type: [String, Object], required: true } },
  template: '<a><slot /></a>',
};

function mountLayout() {
  return mount(BotsModuleLayout, {
    attachTo: document.body,
    global: {
      components: { RouterLink: RouterLinkStub, RouterView: { template: '<div />' } },
      stubs: { Drawer: true, BotEditorDrawer: true },
    },
  });
}

beforeEach(() => {
  installBrowserMocks();
  routeName.value = 'next.bots';
  routeParams.value = {};
  routeQuery.value = {};
  detailRef.value = null;
  fetchBot.mockReset();
});

afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('BotsModuleLayout — two-level aside', () => {
  it('on the list route renders the module block + the pick-a-bot placeholder (no section links)', () => {
    const wrapper = mountLayout();
    expect(wrapper.text()).toContain(en.bots.title);
    expect(wrapper.text()).toContain(en.bots.module.selectHint);
    // The resource slot is the muted placeholder linking to the list…
    expect(wrapper.text()).toContain(en.bots.module.placeholderLabel);
    const placeholderLink = wrapper
      .findAllComponents(RouterLinkStub)
      .find((l) => l.text().includes(en.bots.module.placeholderLabel));
    expect(placeholderLink).toBeTruthy();
    expect((placeholderLink!.props('to') as LinkTarget).name).toBe('next.bots');
    // …and the section labels appear only as a decorative disabled preview.
    const sectionLink = wrapper
      .findAllComponents(RouterLinkStub)
      .find((l) => (l.props('to') as LinkTarget)?.name === 'next.bots.detail.activity');
    expect(sectionLink).toBeUndefined();
    expect(wrapper.find('div[aria-hidden="true"]').text()).toContain(en.bots.detail.tabActivity);
  });

  it('on a detail child route promotes the SELECTED bot block (identity + status) above the module block', () => {
    routeName.value = 'next.bots.detail.activity';
    routeParams.value = { id: 'b-1' };
    detailRef.value = { id: 'b-1', name: 'BotXyz', status: 'active', icon: null };
    const wrapper = mountLayout();

    // The bot's identity lives HERE now (selected block), above the module block,
    // with a back-to-list icon button.
    expect(wrapper.text()).toContain('BotXyz');
    expect(wrapper.findComponent({ name: 'StatusBadge' }).exists()).toBe(true);
    const back = wrapper
      .findAllComponents(RouterLinkStub)
      .find((l) => l.attributes('aria-label') === en.bots.module.allBots);
    expect(back).toBeTruthy();
    expect((back!.props('to') as LinkTarget).name).toBe('next.bots');
    const headings = wrapper.findAll('h2').map((h) => h.text());
    expect(headings.indexOf('BotXyz')).toBeLessThan(headings.indexOf(en.bots.title));
    // No placeholder in selected state; no fetch — the detail is cached.
    expect(wrapper.text()).not.toContain(en.bots.module.placeholderLabel);
    expect(fetchBot).not.toHaveBeenCalled();

    const navLinks = wrapper
      .findAllComponents(RouterLinkStub)
      .filter((l) => typeof l.props('to') === 'object');
    const byName = (suffix: string) =>
      navLinks.find((l) => (l.props('to') as LinkTarget).name === `next.bots.detail.${suffix}`);

    // All three section links target the named child routes.
    expect(byName('inbox')).toBeTruthy();
    expect(byName('activity')).toBeTruthy();
    expect(byName('config')).toBeTruthy();

    // The active tab is derived from the route-name suffix.
    expect(byName('activity')!.classes()).toContain('bg-next-primary-subtle');
    expect(byName('inbox')!.classes()).not.toContain('bg-next-primary-subtle');
  });

  it('sectionLink preserves the query but strips a legacy section key', () => {
    routeName.value = 'next.bots.detail.inbox';
    routeParams.value = { id: 'b-1' };
    routeQuery.value = { section: 'inbox', bot: 'b-2' };
    detailRef.value = { id: 'b-1', name: 'BotXyz', status: 'active', icon: null };
    const wrapper = mountLayout();

    const target = wrapper
      .findAllComponents(RouterLinkStub)
      .map((l) => l.props('to') as LinkTarget)
      .find((to) => to?.name === 'next.bots.detail.config');

    expect(target).toBeTruthy();
    expect(target!.params).toEqual({ id: 'b-1' });
    expect(target!.query).toEqual({ bot: 'b-2' }); // legacy `section` stripped
  });
});
