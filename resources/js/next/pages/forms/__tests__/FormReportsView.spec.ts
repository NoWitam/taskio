// @vitest-environment happy-dom
// FormReportsView.spec — the `?report=<id>` deep-link contract.
//
// A report deep-link (e.g. the run-detail create_form_report step card →
// /next/forms/{id}/reports?report={rid}) must AUTO-OPEN the report detail drawer for
// that report on mount: from the loaded page when present, otherwise by fetching the
// single report. Mirrors FormSubmissionsView's `?submission=` pattern. The heavy list
// scaffolding (FilterBar / saved views / composables / cards) is mocked; we assert only
// the drawer wiring.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import FormReportsView from '../FormReportsView.vue';
import Drawer from '../../../ui/overlay/Drawer.vue';
import { FORM_MODULE_CTX } from '../formContext';
import { setLocale } from '../../../app/i18n';
import type { FormReport } from '../types';

// --- Route: the deep-link carries `?report=rep-9` ---------------------------
let routeQuery: Record<string, unknown> = {};
const routerReplace = vi.fn();
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: 'form-1' }, query: routeQuery }),
  useRouter: () => ({ replace: routerReplace, push: vi.fn() }),
}));

// --- Forms store: fetch a single report on demand ---------------------------
const fetchReport = vi.fn();
const fetchReports = vi.fn();
const reportsFor = vi.fn<(id: string) => FormReport[]>(() => []);
const store = {
  fetchReports,
  loadMoreReports: vi.fn(),
  reportsFor,
  fetchReport,
  fetchReportFile: vi.fn().mockResolvedValue(''),
  patchReport: vi.fn(),
  deleteReport: vi.fn(),
  restoreReport: vi.fn(),
  forceDeleteReport: vi.fn(),
  repLoading: {} as Record<string, boolean>,
  repError: {} as Record<string, string | null>,
  repHasMore: {} as Record<string, boolean>,
};
vi.mock('../../../app/stores/forms', () => ({ useFormsStore: () => store }));

// --- Composables / saved-views scaffolding (inert stubs) --------------------
vi.mock('../../../app/composables/useInfiniteScroll', () => ({
  useInfiniteScroll: () => ({ sentinelRef: ref(null), pause: vi.fn(), resume: vi.fn() }),
}));
vi.mock('../../../app/composables/useDebounce', () => ({
  useDebounce: (fn: (...a: unknown[]) => unknown) => fn,
}));
vi.mock('../../../app/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), danger: vi.fn() }),
}));
vi.mock('../../../app/composables/useConfirm', () => ({
  useConfirm: () => vi.fn().mockResolvedValue(false),
}));
vi.mock('../../../app/composables/useFilterTabs', () => ({
  useFilterTabs: () => ({
    tabs: ref([]),
    activeTabId: ref(null),
    activeTab: ref(null),
    dirty: ref(false),
    loading: ref(false),
    loadError: ref(false),
    load: vi.fn().mockResolvedValue(undefined),
    applyTab: vi.fn(),
    clearActive: vi.fn(),
    saveActive: vi.fn().mockResolvedValue(undefined),
    saveAs: vi.fn().mockResolvedValue(undefined),
    restoreFilter: vi.fn(),
    decorateActiveFilters: (f: unknown) => f,
  }),
}));
vi.mock('../../../app/stores/filterTabs', () => ({
  useFilterTabsStore: () => ({ update: vi.fn(), remove: vi.fn(), reorder: vi.fn(), load: vi.fn() }),
}));

function makeReport(overrides: Partial<FormReport> = {}): FormReport {
  return {
    id: 'rep-9',
    form_id: 'form-1',
    name: 'Weekly summary',
    guidelines: null,
    sources: null,
    sources_formatted: [],
    submissions_from: '2026-07-01',
    submissions_to: '2026-07-07',
    date_range: '01.07.2026 - 07.07.2026',
    // Pending → the detail drawer opens on the "generating" state (no file fetch needed).
    is_completed: false,
    completed_at: null,
    file: null,
    creator: null,
    created_at: '2026-07-08T09:00:00Z',
    updated_at: null,
    deleted_at: null,
    ...overrides,
  };
}

function mountView() {
  return shallowMount(FormReportsView, {
    global: {
      provide: {
        [FORM_MODULE_CTX as symbol]: {
          form: ref({ id: 'form-1', name: 'Form', is_enabled: true, created_at: '2026-07-01T00:00:00Z' }),
          loading: ref(false),
        },
      },
    },
  });
}

/** The detail drawer is the (only) open Drawer once a report is selected. */
function openDrawers(wrapper: ReturnType<typeof mountView>) {
  return wrapper.findAllComponents(Drawer).filter((d) => d.props('open') === true);
}

beforeEach(() => {
  setLocale('en');
  routeQuery = {};
  routerReplace.mockReset();
  fetchReport.mockReset();
  fetchReports.mockReset();
  reportsFor.mockReset();
  reportsFor.mockReturnValue([]);
});

afterEach(() => {
  document.body.innerHTML = '';
});

describe('FormReportsView — ?report deep-link', () => {
  it('fetches and auto-opens the detail drawer for a report not in the loaded page', async () => {
    routeQuery = { report: 'rep-9' };
    fetchReport.mockResolvedValue(makeReport());

    const wrapper = mountView();
    await flushPromises();

    expect(fetchReport).toHaveBeenCalledWith('rep-9');
    expect(openDrawers(wrapper)).toHaveLength(1);
  });

  it('opens from the loaded page without a fetch when the report is present', async () => {
    routeQuery = { report: 'rep-9' };
    reportsFor.mockReturnValue([makeReport()]);

    const wrapper = mountView();
    await flushPromises();

    expect(fetchReport).not.toHaveBeenCalled();
    expect(openDrawers(wrapper)).toHaveLength(1);
  });

  it('leaves the detail drawer closed with no `?report` param', async () => {
    const wrapper = mountView();
    await flushPromises();

    expect(fetchReport).not.toHaveBeenCalled();
    expect(openDrawers(wrapper)).toHaveLength(0);
  });

  it('strips the `?report` param when the detail drawer closes', async () => {
    routeQuery = { report: 'rep-9', tab: 'active' };
    reportsFor.mockReturnValue([makeReport()]);

    const wrapper = mountView();
    await flushPromises();

    const detail = openDrawers(wrapper)[0];
    detail.vm.$emit('update:open', false);
    await flushPromises();

    // The report key is dropped; every other query key is preserved.
    expect(routerReplace).toHaveBeenCalled();
    const lastArg = routerReplace.mock.calls[routerReplace.mock.calls.length - 1][0];
    expect(lastArg.query).not.toHaveProperty('report');
  });
});
