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
import type { DefineComponent } from 'vue';
import WorkflowRunsView from '../WorkflowRunsView.vue';
import SegmentedControlRaw from '../../../ui/forms/SegmentedControl.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

// SegmentedControl is a GENERIC SFC — cast to a plain component (carrying the props we
// read) so `findAllComponents` resolves the typed (VueWrapper) overload, not the DOM one.
const SegmentedControl = SegmentedControlRaw as unknown as DefineComponent<{
  multiple?: boolean;
  options?: { value: string }[];
}>;

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

function mountRuns(props: Record<string, unknown> = {}) {
  return mount(WorkflowRunsView, {
    attachTo: document.body,
    props: { workflowId: 'wf-1', ...props },
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
    // The list itself fetched with the URL filters honored — a legacy single ?state=
    // scalar is normalized to the array shape the store now serializes.
    expect(fetchRuns).toHaveBeenCalledWith('wf-1', { state: ['failed'] }, { reset: true });
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

describe('WorkflowRunsView — state + source as SegmentedControl multiple (B4)', () => {
  // The state SegmentedControl is [0], the source (origin) one is [1] (DateRangeFilter
  // isn't a SegmentedControl), so we read the origin control's `options` prop directly.
  function originValues(wrapper: ReturnType<typeof mountRuns>): string[] {
    const segs = wrapper.findAllComponents(SegmentedControl);
    return (segs[1].props('options') as { value: string }[]).map((o) => o.value);
  }

  it('renders state + source as SegmentedControl in `multiple` mode (reverted from Select)', async () => {
    const wrapper = mountRuns();
    await flushPromises();
    const segs = wrapper.findAllComponents(SegmentedControl);
    expect(segs).toHaveLength(2);
    expect(segs[0].props('multiple')).toBe(true);
    expect(segs[1].props('multiple')).toBe(true);
    wrapper.unmount();
  });

  it('form_submitted workflow drops the "schedule" source option (keeps event + manual)', async () => {
    const wrapper = mountRuns({ triggerType: 'form_submitted' });
    await flushPromises();
    expect(originValues(wrapper)).toEqual(['event', 'manual']);
    wrapper.unmount();
  });

  it('a schedule / unknown-trigger workflow keeps all three source options', async () => {
    const wrapper = mountRuns({ triggerType: 'schedule' });
    await flushPromises();
    expect(originValues(wrapper)).toEqual(['event', 'schedule', 'manual']);
    wrapper.unmount();
  });
});
