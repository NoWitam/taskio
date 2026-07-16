// @vitest-environment happy-dom
// FormSubmissionsView.spec — the `?submission=<id>` deep-link contract (Problem D).
//
// A submission deep-link (e.g. the run-detail "open original submission" button →
// /next/forms/{id}/submissions?submission={sid}) must AUTO-OPEN the preview drawer
// for that submission on mount: from the loaded page when present, otherwise by
// fetching the single submission. The heavy list scaffolding (FilterBar / saved
// views / composables) is mocked; we assert only the drawer wiring.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import FormSubmissionsView from '../FormSubmissionsView.vue';
import SubmissionPreviewDrawer from '../SubmissionPreviewDrawer.vue';
import { FORM_MODULE_CTX } from '../formContext';
import { setLocale } from '../../../app/i18n';
import type { FormSubmission } from '../types';

// --- Route: the deep-link carries `?submission=sub-9` ------------------------
let routeQuery: Record<string, unknown> = {};
const routerReplace = vi.fn();
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: 'form-1' }, query: routeQuery }),
  useRouter: () => ({ replace: routerReplace, push: vi.fn() }),
}));

// --- Forms store: fetch a single submission on demand ------------------------
const fetchSubmission = vi.fn();
const fetchSubmissions = vi.fn();
const submissionsFor = vi.fn<(id: string) => FormSubmission[]>(() => []);
const store = {
  fetchSubmissions,
  loadMoreSubmissions: vi.fn(),
  submissionsFor,
  fetchSubmission,
  updateSubmission: vi.fn(),
  deleteSubmission: vi.fn(),
  restoreSubmission: vi.fn(),
  forceDeleteSubmission: vi.fn(),
  subLoading: {} as Record<string, boolean>,
  subError: {} as Record<string, string | null>,
  subHasMore: {} as Record<string, boolean>,
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

function makeSubmission(overrides: Partial<FormSubmission> = {}): FormSubmission {
  return {
    id: 'sub-9',
    form_id: 'form-1',
    data: { name: 'Ann' },
    source: 'form',
    form_content_version_id: null,
    indexed_at: null,
    approved_at: '2026-07-10T09:00:00Z',
    is_approved: true,
    can_be_edited: false,
    creator: null,
    created_at: '2026-07-10T09:00:00Z',
    updated_at: null,
    ...overrides,
  };
}

function mountView() {
  return shallowMount(FormSubmissionsView, {
    global: {
      provide: {
        [FORM_MODULE_CTX as symbol]: {
          form: ref({ id: 'form-1', name: 'Form', content: [], can_be_filled: true }),
          loading: ref(false),
        },
      },
    },
  });
}

beforeEach(() => {
  setLocale('en');
  routeQuery = {};
  routerReplace.mockReset();
  fetchSubmission.mockReset();
  fetchSubmissions.mockReset();
  submissionsFor.mockReset();
  submissionsFor.mockReturnValue([]);
});

afterEach(() => {
  document.body.innerHTML = '';
});

describe('FormSubmissionsView — ?submission deep-link (Problem D)', () => {
  it('fetches and auto-opens the preview for a submission not in the loaded page', async () => {
    routeQuery = { submission: 'sub-9' };
    fetchSubmission.mockResolvedValue(makeSubmission());

    const wrapper = mountView();
    await flushPromises();

    expect(fetchSubmission).toHaveBeenCalledWith('sub-9');
    const drawer = wrapper.findComponent(SubmissionPreviewDrawer);
    expect(drawer.props('open')).toBe(true);
    expect((drawer.props('submission') as FormSubmission).id).toBe('sub-9');
    expect(drawer.props('mode')).toBe('view');
  });

  it('opens from the loaded page without a fetch when the submission is present', async () => {
    routeQuery = { submission: 'sub-9' };
    submissionsFor.mockReturnValue([makeSubmission()]);

    const wrapper = mountView();
    await flushPromises();

    expect(fetchSubmission).not.toHaveBeenCalled();
    expect(wrapper.findComponent(SubmissionPreviewDrawer).props('open')).toBe(true);
  });

  it('leaves the drawer closed with no `?submission` param', async () => {
    const wrapper = mountView();
    await flushPromises();

    expect(fetchSubmission).not.toHaveBeenCalled();
    expect(wrapper.findComponent(SubmissionPreviewDrawer).props('open')).toBe(false);
  });
});
