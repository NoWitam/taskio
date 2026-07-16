// @vitest-environment happy-dom
// pageContext.spec — the Navbar breadcrumb label contract (Batch 4+6). Pins the
// lifecycle-ordering bug the review gate caught: on a cross-module layout swap
// the OUTGOING layout must clear the label in onBeforeUnmount (synchronous,
// before the incoming layout's setup) — with onUnmounted (post-flush) the clear
// would land AFTER the incoming layout's immediate watch set the label for an
// already-cached detail, leaving it stuck at null. The swap test below mounts
// the REAL module layouts side by side with cached store details, so a revert
// to onUnmounted fails here.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';
import { pageContextLabel, setPageContextLabel } from '../pageContext';
import WorkflowsModuleLayout from '../../../pages/workflows/WorkflowsModuleLayout.vue';
import BotsModuleLayout from '../../../pages/bots/BotsModuleLayout.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

// --- Store + router mocks: both details are CACHED (the race precondition). ---
vi.mock('../../stores/workflows', () => ({
  useWorkflowsStore: () => ({
    detail: { id: 'wf-1', name: 'Workflow X', status: 'active', icon: null },
    fetchWorkflow: vi.fn(),
  }),
}));
vi.mock('../../stores/bots', () => ({
  useBotsStore: () => ({
    detail: { id: 'b-1', name: 'Bot Y', status: 'active', icon: null },
    fetchBot: vi.fn(),
  }),
}));

const routeName = ref<string>('next.workflows.detail.overview');
const routeParams = ref<Record<string, unknown>>({ id: 'wf-1' });
vi.mock('vue-router', () => ({
  useRoute: () => ({
    get name() {
      return routeName.value;
    },
    get params() {
      return routeParams.value;
    },
    query: {},
  }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}));

const RouterLinkStub = {
  name: 'RouterLink',
  props: { to: { type: [String, Object], required: true } },
  template: '<a><slot /></a>',
};

beforeEach(() => {
  installBrowserMocks();
  setPageContextLabel(null);
  routeName.value = 'next.workflows.detail.overview';
  routeParams.value = { id: 'wf-1' };
});

afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('pageContext', () => {
  it('set/clear round-trips through the shared ref', () => {
    setPageContextLabel('Something');
    expect(pageContextLabel.value).toBe('Something');
    setPageContextLabel(null);
    expect(pageContextLabel.value).toBeNull();
  });

  it('a cross-module layout swap onto a CACHED detail keeps the incoming label', async () => {
    const active = ref<'workflows' | 'bots'>('workflows');
    const Host = defineComponent({
      setup: () => () => h(active.value === 'workflows' ? WorkflowsModuleLayout : BotsModuleLayout),
    });
    const wrapper = mount(Host, {
      attachTo: document.body,
      global: {
        components: { RouterLink: RouterLinkStub, RouterView: { template: '<div />' } },
        stubs: {
          Drawer: true,
          WorkflowEditorDrawer: true,
          BotEditorDrawer: true,
          TargetPickerModal: true,
        },
      },
    });
    await flushPromises();
    expect(pageContextLabel.value).toBe('Workflow X');

    // Navigate: the route flips to the bots detail and the layout swaps. The
    // outgoing layout's clear must NOT wipe the label the incoming layout set
    // synchronously in setup (its watch source — the cached name — never
    // changes again, so a post-flush wipe would stick).
    routeName.value = 'next.bots.detail.inbox';
    routeParams.value = { id: 'b-1' };
    active.value = 'bots';
    await flushPromises();

    expect(pageContextLabel.value).toBe('Bot Y');
    wrapper.unmount();
  });

  it('leaving the detail for the module list clears the label', async () => {
    const wrapper = mount(BotsModuleLayout, {
      attachTo: document.body,
      global: {
        components: { RouterLink: RouterLinkStub, RouterView: { template: '<div />' } },
        stubs: { Drawer: true, BotEditorDrawer: true },
      },
    });
    routeName.value = 'next.bots.detail.inbox';
    routeParams.value = { id: 'b-1' };
    await flushPromises();
    expect(pageContextLabel.value).toBe('Bot Y');

    routeName.value = 'next.bots';
    routeParams.value = {};
    await flushPromises();
    expect(pageContextLabel.value).toBeNull();

    wrapper.unmount();
    expect(pageContextLabel.value).toBeNull();
  });
});
