// @vitest-environment happy-dom
// WorkflowRunsView.spec — the `?run_detail=` drawer contract (Batch 3, tension 7).
// The Runs section is reached through a CHILD ROUTE now (WorkflowRunsSection →
// WorkflowRunsView), so this pins the deep-link behaviour the migration must not
// break: mounting with `?run_detail=<id>` in the query opens the run-detail
// Drawer immediately, and unmounting (leaving the section) resets the store and
// never throws. The runs store + vue-router are mocked; the run timeline body
// (it owns its own fetch) is stubbed.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import WorkflowRunsView from '../WorkflowRunsView.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

// --- Runs store + router mocks ----------------------------------------------
const fetchRuns = vi.fn();
const resetAll = vi.fn();
const runsStoreMock = {
  items: [] as unknown[],
  loading: false,
  loadingMore: false,
  errored: false,
  loadMoreErrored: false,
  hasMore: false,
  fetchRuns,
  loadMore: vi.fn(),
  retryLoadMore: vi.fn(),
  resetAll,
};
vi.mock('../../../app/stores/workflowRuns', () => ({
  useWorkflowRunsStore: () => runsStoreMock,
}));

const routeQuery = ref<Record<string, unknown>>({});
const routerReplace = vi.fn();
vi.mock('vue-router', () => ({
  useRoute: () => ({
    name: 'next.workflows.detail.runs',
    params: { id: 'wf-1' },
    get query() {
      return routeQuery.value;
    },
  }),
  useRouter: () => ({ push: vi.fn(), replace: routerReplace }),
}));

function mountRuns() {
  return mount(WorkflowRunsView, {
    attachTo: document.body,
    props: { workflowId: 'wf-1' },
    global: { stubs: { WorkflowRunTimeline: true } },
  });
}

beforeEach(() => {
  installBrowserMocks();
  routeQuery.value = {};
  fetchRuns.mockReset();
  resetAll.mockReset();
});

afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('WorkflowRunsView — `?run_detail=` deep link (tension 7)', () => {
  it('mounting with run_detail in the query opens the run-detail drawer', async () => {
    routeQuery.value = { run_detail: 'run-X', state: 'failed' };
    const wrapper = mountRuns();
    await flushPromises();

    expect(document.body.querySelector('[role="dialog"]')).not.toBeNull();
    expect(wrapper.findComponent({ name: 'WorkflowRunTimeline' }).exists()).toBe(true);
    // The list itself fetched with the URL filters honored.
    expect(fetchRuns).toHaveBeenCalledWith('wf-1', { state: 'failed' }, { reset: true });
    wrapper.unmount();
  });

  it('no run_detail → no drawer', async () => {
    const wrapper = mountRuns();
    await flushPromises();

    expect(document.body.querySelector('[role="dialog"]')).toBeNull();
    wrapper.unmount();
  });

  it('unmounting with the drawer open resets the store and does not throw', async () => {
    routeQuery.value = { run_detail: 'run-X' };
    const wrapper = mountRuns();
    await flushPromises();

    expect(() => wrapper.unmount()).not.toThrow();
    expect(resetAll).toHaveBeenCalled();
  });
});
