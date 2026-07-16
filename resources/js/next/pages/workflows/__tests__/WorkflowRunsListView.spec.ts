// @vitest-environment happy-dom
// WorkflowRunsListView.spec — the GLOBAL runs list (B5). Pins the two load-bearing
// behaviours: it fetches the store's GLOBAL scope on mount, and it renders each row
// through WorkflowRunRow with `showWorkflow` so the parent-workflow column appears.
// The heavy filter/overlay children are stubbed; the runs store, workflows store,
// saved-views composable/store and toast are mocked (no Pinia / HTTP).
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { WorkflowRun } from '../types';

// --- Runs store mock --------------------------------------------------------
const fetchRuns = vi.fn();
const runItems: WorkflowRun[] = [
  {
    id: 'run-1',
    state: 'completed',
    state_label: 'Completed',
    state_tone: 'success',
    origin: 'schedule',
    trigger_type: 'schedule',
    depth: 0,
    origin_run_id: null,
    error: null,
    started_at: '2026-01-01T00:00:00Z',
    finished_at: '2026-01-01T00:00:05Z',
    created_at: '2026-01-01T00:00:00Z',
    duration_seconds: 5,
    steps_count: 1,
    workflow: { id: 'wf-7', name: 'Weekly report builder', icon: 'workflow', status: 'active', trigger_type: 'schedule' },
  },
];
const runsStoreMock = {
  items: runItems,
  loading: false,
  loadingMore: false,
  errored: false,
  loadMoreErrored: false,
  hasMore: false,
  scope: 'global',
  fetchRuns,
  loadMore: vi.fn(),
  retryLoadMore: vi.fn(),
  resetAll: vi.fn(),
};
vi.mock('../../../app/stores/workflowRuns', () => ({
  useWorkflowRunsStore: () => runsStoreMock,
}));

// --- Workflows store mock (seeds the workflow filter options) ----------------
vi.mock('../../../app/stores/workflows', () => ({
  useWorkflowsStore: () => ({ items: [], fetchWorkflows: vi.fn() }),
}));

// --- Saved-views composable + store + toast mocks ---------------------------
vi.mock('../../../app/composables/useFilterTabs', () => ({
  useFilterTabs: () => ({
    tabs: ref([]),
    activeTabId: ref(null),
    activeTab: ref(null),
    dirty: ref(false),
    loading: ref(false),
    loadError: ref(false),
    load: vi.fn(),
    applyTab: vi.fn(),
    clearActive: vi.fn(),
    saveActive: vi.fn(),
    saveAs: vi.fn(),
    restoreFilter: vi.fn(),
    decorateActiveFilters: (f: unknown[]) => f,
  }),
}));
vi.mock('../../../app/stores/filterTabs', () => ({
  useFilterTabsStore: () => ({ update: vi.fn(), remove: vi.fn(), reorder: vi.fn(), create: vi.fn() }),
}));
vi.mock('../../../app/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), danger: vi.fn() }),
}));

import type { DefineComponent } from 'vue';
import WorkflowRunsListView from '../WorkflowRunsListView.vue';
import WorkflowRunRow from '../WorkflowRunRow.vue';
import SegmentedControlRaw from '../../../ui/forms/SegmentedControl.vue';
import Select from '../../../ui/forms/Select.vue';

// SegmentedControl is a GENERIC SFC — cast (carrying the props we read) so
// `findAllComponents` resolves the typed (VueWrapper) overload instead of the DOM one.
const SegmentedControl = SegmentedControlRaw as unknown as DefineComponent<{ multiple?: boolean }>;

function mountList() {
  return mount(WorkflowRunsListView, {
    attachTo: document.body,
    global: {
      stubs: {
        PageHeader: true,
        FilterBar: true,
        FilterTabBar: true,
        SaveViewModal: true,
        ConfirmDialog: true,
        Select: true,
        SegmentedControl: true,
        DateRangeFilter: true,
        Drawer: true,
        WorkflowRunTimeline: true,
      },
    },
  });
}

/**
 * Mount with the FilterBar + its filter controls REAL (only heavy/unrelated children
 * stubbed) so the control TYPES can be asserted: state/source/trigger are
 * SegmentedControl (multiple), workflow is a multi Select.
 */
function mountListWithControls() {
  return mount(WorkflowRunsListView, {
    attachTo: document.body,
    global: {
      stubs: {
        PageHeader: true,
        FilterTabBar: true,
        SaveViewModal: true,
        ConfirmDialog: true,
        DateRangeFilter: true,
        Drawer: true,
        WorkflowRunTimeline: true,
      },
    },
  });
}

beforeEach(() => {
  installBrowserMocks();
  fetchRuns.mockReset();
});
afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('WorkflowRunsListView — global feed', () => {
  it('fetches the GLOBAL scope on mount', async () => {
    mountList();
    await flushPromises();
    expect(fetchRuns).toHaveBeenCalledWith(null, {}, { reset: true, scope: 'global' });
  });

  it('renders each row with the workflow column (showWorkflow) + name', async () => {
    const wrapper = mountList();
    await flushPromises();

    const rows = wrapper.findAllComponents(WorkflowRunRow);
    expect(rows).toHaveLength(1);
    expect(rows[0].props('showWorkflow')).toBe(true);
    expect(wrapper.text()).toContain('Weekly report builder');
  });
});

describe('WorkflowRunsListView — reverted filter controls', () => {
  it('state / source / trigger are SegmentedControl (multiple); workflow is a multi Select', async () => {
    const wrapper = mountListWithControls();
    await flushPromises();

    // Three small enums → SegmentedControl in multiple mode.
    const segs = wrapper.findAllComponents(SegmentedControl);
    expect(segs).toHaveLength(3);
    segs.forEach((s) => expect(s.props('multiple')).toBe(true));

    // Workflow (many options) → a searchable multi Select.
    const selects = wrapper.findAllComponents(Select);
    expect(selects).toHaveLength(1);
    expect(selects[0].props('multiple')).toBe(true);
    expect(selects[0].props('searchable')).toBe(true);

    wrapper.unmount();
  });
});
