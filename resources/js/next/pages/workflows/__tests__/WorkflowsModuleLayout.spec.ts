// @vitest-environment happy-dom
// WorkflowsModuleLayout.spec — the two-level module aside, mirroring
// BotsModuleLayout.spec: the pick-a-workflow placeholder on the list route, the
// SELECTED workflow block (identity + status) promoted above the module block
// on a detail child route, the active-tab derivation from the route-name
// suffix, and sectionLink() targeting the named child routes while PRESERVING
// the query minus any legacy `section` key. The store + vue-router are mocked;
// RouterLink is a props-capturing stub; the REAL i18n renders the copy.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { ref } from 'vue';
import WorkflowsModuleLayout from '../WorkflowsModuleLayout.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { en } from '../../../app/i18n/en';
import type { WorkflowDetail } from '../types';

// --- Store + router mocks ----------------------------------------------------
const detailRef = ref<Partial<WorkflowDetail> | null>(null);
const fetchWorkflow = vi.fn();
vi.mock('../../../app/stores/workflows', () => ({
  useWorkflowsStore: () => ({
    get detail() {
      return detailRef.value;
    },
    fetchWorkflow,
  }),
}));

const routeName = ref<string>('next.workflows');
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
  return mount(WorkflowsModuleLayout, {
    attachTo: document.body,
    global: {
      components: { RouterLink: RouterLinkStub, RouterView: { template: '<div />' } },
      stubs: { Drawer: true, WorkflowEditorDrawer: true, TargetPickerModal: true },
    },
  });
}

beforeEach(() => {
  installBrowserMocks();
  routeName.value = 'next.workflows';
  routeParams.value = {};
  routeQuery.value = {};
  detailRef.value = null;
  fetchWorkflow.mockReset();
});

afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('WorkflowsModuleLayout — two-level aside', () => {
  it('on the list route renders the module block + the pick-a-workflow placeholder (no section links)', () => {
    const wrapper = mountLayout();
    expect(wrapper.text()).toContain(en.workflows.title);
    expect(wrapper.text()).toContain(en.workflows.module.selectHint);
    expect(wrapper.text()).toContain(en.workflows.module.placeholderLabel);
    const placeholderLink = wrapper
      .findAllComponents(RouterLinkStub)
      .find((l) => l.text().includes(en.workflows.module.placeholderLabel));
    expect(placeholderLink).toBeTruthy();
    expect((placeholderLink!.props('to') as LinkTarget).name).toBe('next.workflows');
    // Section labels appear only as a decorative disabled preview.
    const sectionLink = wrapper
      .findAllComponents(RouterLinkStub)
      .find((l) => (l.props('to') as LinkTarget)?.name === 'next.workflows.detail.runs');
    expect(sectionLink).toBeUndefined();
    expect(wrapper.find('div[aria-hidden="true"]').text()).toContain(en.workflows.detail.tabRuns);
  });

  it('on a detail child route promotes the SELECTED workflow block (identity + status) above the module block', () => {
    routeName.value = 'next.workflows.detail.runs';
    routeParams.value = { id: 'wf-1' };
    detailRef.value = { id: 'wf-1', name: 'My workflow', status: 'active', icon: null };
    const wrapper = mountLayout();

    // The workflow's identity lives HERE now (selected block), above the module block.
    expect(wrapper.text()).toContain('My workflow');
    expect(wrapper.findComponent({ name: 'StatusBadge' }).exists()).toBe(true);
    const headings = wrapper.findAll('h2').map((h) => h.text());
    expect(headings.indexOf('My workflow')).toBeLessThan(headings.indexOf(en.workflows.title));
    expect(wrapper.text()).not.toContain(en.workflows.module.placeholderLabel);
    expect(fetchWorkflow).not.toHaveBeenCalled();

    const navLinks = wrapper
      .findAllComponents(RouterLinkStub)
      .filter((l) => typeof l.props('to') === 'object');
    const byName = (suffix: string) =>
      navLinks.find((l) => (l.props('to') as LinkTarget).name === `next.workflows.detail.${suffix}`);

    expect(byName('overview')).toBeTruthy();
    expect(byName('runs')).toBeTruthy();

    // The active tab is derived from the route-name suffix.
    expect(byName('runs')!.classes()).toContain('bg-next-primary-subtle');
    expect(byName('overview')!.classes()).not.toContain('bg-next-primary-subtle');
  });

  it('sectionLink preserves the query but strips a legacy section key', () => {
    routeName.value = 'next.workflows.detail.overview';
    routeParams.value = { id: 'wf-1' };
    routeQuery.value = { section: 'overview', workflow: 'wf-2', state: 'failed' };
    detailRef.value = { id: 'wf-1', name: 'My workflow', status: 'active', icon: null };
    const wrapper = mountLayout();

    const target = wrapper
      .findAllComponents(RouterLinkStub)
      .map((l) => l.props('to') as LinkTarget)
      .find((to) => to?.name === 'next.workflows.detail.runs');

    expect(target).toBeTruthy();
    expect(target!.params).toEqual({ id: 'wf-1' });
    expect(target!.query).toEqual({ workflow: 'wf-2', state: 'failed' }); // legacy `section` stripped
  });
});
