// @vitest-environment happy-dom
// WorkflowDetailView.spec — the detail SHELL (§3, REWRITTEN for Batch 3). The
// Overview panel assertions moved to WorkflowOverviewView.spec when the sections
// became child routes; what is left HERE is the shell's own job: the deep-link
// fetch (loading skeletons / error + retry), the capability-gated action bar, and
// rendering the active section through its <RouterView> once the workflow loads.
// The store + toast + vue-router are mocked (the RouterView outlet is stubbed);
// the REAL i18n renders the copy the test asserts on.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import WorkflowDetailView from '../WorkflowDetailView.vue';
import Skeleton from '../../../ui/data/Skeleton.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { en } from '../../../app/i18n/en';
import type { WorkflowDetail } from '../types';

// --- Store + toast + router mocks -------------------------------------------
const detailRef = ref<WorkflowDetail | null>(null);
const fetchWorkflow = vi.fn();
// fetchWorkflowCatalog serves the real WorkflowOverviewView child in the
// composition test below (null form_id → it is never called there).
const fetchWorkflowCatalog = vi.fn();

vi.mock('../../../app/stores/workflows', () => ({
  useWorkflowsStore: () => ({
    get detail() {
      return detailRef.value;
    },
    fetchWorkflow,
    fetchWorkflowCatalog,
    setStatus: vi.fn(),
  }),
}));

vi.mock('../../../app/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() }),
}));

const routeQuery = ref<Record<string, unknown>>({});
const routerPush = vi.fn();
vi.mock('vue-router', () => ({
  useRoute: () => ({
    name: 'next.workflows.detail.overview',
    params: { id: 'wf-1' },
    get query() {
      return routeQuery.value;
    },
  }),
  useRouter: () => ({ push: routerPush }),
}));

// The section outlet — the shell renders `<RouterView />`; no real router here.
const RouterViewStub = { name: 'RouterView', template: '<div data-testid="section-outlet" />' };

function makeWorkflow(overrides: Partial<WorkflowDetail> = {}): WorkflowDetail {
  return {
    id: 'wf-1',
    name: 'WF',
    status: 'active',
    description: null,
    icon: null,
    trigger_type: 'form_submitted',
    trigger_config: { form_id: null },
    conditions: [],
    steps: [],
    last_scheduled_run_at: null,
    next_due_at: null,
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    can_change_status: true,
    can_run: true,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function mountShell() {
  return mount(WorkflowDetailView, {
    attachTo: document.body,
    global: { components: { RouterView: RouterViewStub } },
  });
}

beforeEach(() => {
  installBrowserMocks();
  routeQuery.value = {};
  detailRef.value = null;
  fetchWorkflow.mockReset();
  routerPush.mockReset();
});

afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('WorkflowDetailView shell — deep-link fetch states', () => {
  it('shows geometry-mimicking skeletons while the deep-link fetch is pending', async () => {
    fetchWorkflow.mockReturnValue(new Promise(() => {})); // never settles
    const wrapper = mountShell();
    await flushPromises();

    expect(fetchWorkflow).toHaveBeenCalledWith('wf-1');
    expect(wrapper.findComponent(Skeleton).exists()).toBe(true);
    expect(wrapper.find('[data-testid="section-outlet"]').exists()).toBe(false);
  });

  it('a failed fetch → the error state; retry re-fetches', async () => {
    fetchWorkflow.mockResolvedValue(null);
    const wrapper = mountShell();
    await flushPromises();

    expect(wrapper.text()).toContain(en.workflows.detail.errorTitle);
    expect(wrapper.find('[data-testid="section-outlet"]').exists()).toBe(false);

    const retry = wrapper
      .findAll('button')
      .find((b) => b.text().includes(en.workflows.errors.retry));
    expect(retry).toBeTruthy();
    await retry!.trigger('click');
    expect(fetchWorkflow).toHaveBeenCalledTimes(2);
  });

  it('a cached (prefetched) workflow renders immediately without fetching', async () => {
    detailRef.value = makeWorkflow();
    const wrapper = mountShell();
    await flushPromises();

    expect(fetchWorkflow).not.toHaveBeenCalled();
    expect(wrapper.find('[data-testid="section-outlet"]').exists()).toBe(true);
  });
});

describe('WorkflowDetailView shell — capability-gated action bar (§3.1)', () => {
  it('renders Run / Deactivate / Edit for a fully-capable active workflow + the section outlet', async () => {
    detailRef.value = makeWorkflow();
    const wrapper = mountShell();
    await flushPromises();

    const text = wrapper.text();
    expect(text).toContain(en.workflows.actions.run);
    expect(text).toContain(en.workflows.actions.deactivate);
    expect(text).toContain(en.workflows.actions.edit);
    // No back button in the header — back-navigation lives in the aside/breadcrumb.
    expect(text).not.toContain(en.workflows.detail.back);
    expect(wrapper.find('[data-testid="section-outlet"]').exists()).toBe(true);
  });

  it('the PageHeader h1 names the SECTION purpose (identity lives in the aside, not here)', async () => {
    detailRef.value = makeWorkflow({ name: 'My automation' });
    const wrapper = mountShell();
    await flushPromises();

    const headings = wrapper.findAll('h1');
    expect(headings).toHaveLength(1);
    // The route mock sits on the overview child → the Overview section header.
    expect(headings[0].text()).toBe(en.workflows.detail.tabOverview);
    expect(wrapper.text()).toContain(en.workflows.detail.sectionDescriptions.overview);
    expect(wrapper.text()).not.toContain('My automation');
    expect(wrapper.findComponent({ name: 'StatusBadge' }).exists()).toBe(false);
  });

  it('shell + a REAL Overview child compose to exactly one h1 at the uniform scale', async () => {
    detailRef.value = makeWorkflow();
    const { default: WorkflowOverviewView } = await import('../WorkflowOverviewView.vue');
    const wrapper = mount(WorkflowDetailView, {
      attachTo: document.body,
      global: { components: { RouterView: WorkflowOverviewView } },
    });
    await flushPromises();

    // The Overview panels render (their headings are h2s)…
    expect(wrapper.text()).toContain(en.workflows.detail.triggerTitle);
    // …and the page still has exactly ONE h1: the shell's section header, md scale.
    const headings = wrapper.findAll('h1');
    expect(headings).toHaveLength(1);
    expect(headings[0].text()).toBe(en.workflows.detail.tabOverview);
    expect(headings[0].classes()).toContain('text-next-2xl');
  });

  it('hides the gated actions when every capability is off', async () => {
    detailRef.value = makeWorkflow({ can_run: false, can_change_status: false, can_be_edited: false });
    const wrapper = mountShell();
    await flushPromises();

    const text = wrapper.text();
    // The section header still renders; no action (and no back button) does.
    expect(text).toContain(en.workflows.detail.tabOverview);
    expect(text).not.toContain(en.workflows.actions.run);
    expect(text).not.toContain(en.workflows.actions.deactivate);
    expect(text).not.toContain(en.workflows.actions.edit);
    expect(wrapper.find('button').exists()).toBe(false);
  });

  it('"Run now" opens the run-now overlay by pushing `?run=<id>` with the query preserved', async () => {
    routeQuery.value = { state: 'failed' };
    detailRef.value = makeWorkflow();
    const wrapper = mountShell();
    await flushPromises();

    const runButton = wrapper
      .findAll('button')
      .find((b) => b.text().includes(en.workflows.actions.run));
    await runButton!.trigger('click');

    expect(routerPush).toHaveBeenCalledWith({ query: { state: 'failed', run: 'wf-1' } });
  });
});
