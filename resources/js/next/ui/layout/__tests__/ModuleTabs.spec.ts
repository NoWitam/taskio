// @vitest-environment happy-dom
// ModuleTabs.spec — the small-screen module section tabs (Batch 4+6): hidden at
// ≥ next-lg (class contract), active tab derived from activeMatch, selecting a
// tab pushes its route, the single-item guard renders nothing, and the tablist
// carries the common.moduleNav label.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import ModuleTabs from '../ModuleTabs.vue';
import type { ModuleNavItem } from '../ModuleAside.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { en } from '../../../app/i18n/en';

const routerPush = vi.fn();
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: routerPush }),
}));

const ITEMS: ModuleNavItem[] = [
  { key: 'overview', label: 'Overview', icon: 'layout-dashboard', to: { name: 'x.overview' } },
  { key: 'runs', label: 'Runs', icon: 'clock', to: { name: 'x.runs' } },
];

function mountTabs(props: Record<string, unknown> = {}) {
  return mount(ModuleTabs, {
    attachTo: document.body,
    props: {
      items: ITEMS,
      activeMatch: (item: ModuleNavItem) => item.key === 'overview',
      ...props,
    },
  });
}

beforeEach(() => {
  installBrowserMocks();
  routerPush.mockReset();
});

afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('ModuleTabs', () => {
  it('is hidden at ≥ next-lg (aside takes over) and labels the tablist', () => {
    const wrapper = mountTabs();
    expect(wrapper.find('div').classes()).toContain('next-lg:hidden');
    expect(wrapper.find('[role="tablist"]').attributes('aria-label')).toBe(en.common.moduleNav);
  });

  it('derives the active tab from activeMatch', () => {
    const wrapper = mountTabs({ activeMatch: (i: ModuleNavItem) => i.key === 'runs' });
    const tabs = wrapper.findAll('[role="tab"]');
    const runs = tabs.find((t) => t.text().includes('Runs'))!;
    const overview = tabs.find((t) => t.text().includes('Overview'))!;
    expect(runs.attributes('aria-selected')).toBe('true');
    expect(overview.attributes('aria-selected')).toBe('false');
  });

  it('selecting a tab pushes its route', async () => {
    const wrapper = mountTabs();
    const runsTab = wrapper.findAll('[role="tab"]').find((t) => t.text().includes('Runs'))!;
    await runsTab.trigger('click');
    expect(routerPush).toHaveBeenCalledWith({ name: 'x.runs' });
  });

  it('renders nothing for a single-item nav', () => {
    const wrapper = mountTabs({ items: [ITEMS[0]] });
    expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
  });
});
