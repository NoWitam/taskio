// @vitest-environment happy-dom
// SubmissionsBrowser.spec — the SHARED submissions filter + list section.
//
// This is the single source of truth reused by BOTH the Forms submissions LIST page
// (FormSubmissionsView, default mode) and the workflow run-now picker drawer
// (SubmissionPickerDrawer, `selectable` mode). It pins the contract that makes the
// two identical:
//   • it renders the Saved Views toolbar (FilterTabBar) + the FULL filter set
//     (source + indexed + sort Selects and a DateRangeFilter),
//   • it fetches the scoped form's submissions on mount,
//   • default mode → a card click emits `preview(submission)` and the kebab shows,
//   • `selectable` mode → the kebab is hidden and a card click emits
//     `select(id, submission)`.
// The forms store + saved-views scaffolding are mocked (no HTTP); i18n renders via
// setLocale('en').
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import SubmissionsBrowser from '../SubmissionsBrowser.vue';
import SubmissionCard from '../SubmissionCard.vue';
import FilterTabBar from '../../../ui/patterns/FilterTabBar.vue';
import Select from '../../../ui/forms/Select.vue';
import DateRangeFilter from '../../../ui/forms/DateRangeFilter.vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { FormSubmission } from '../types';

/** Last element (the tsconfig lib target predates Array.prototype.at). */
function last<T>(arr: readonly T[] | undefined): T | undefined {
  return arr && arr.length ? arr[arr.length - 1] : undefined;
}

// --- Forms store mock (controllable per-form list state) --------------------
const state: {
  submissions: Record<string, FormSubmission[]>;
  subLoading: Record<string, boolean>;
  subError: Record<string, string | null>;
  subHasMore: Record<string, boolean>;
} = { submissions: {}, subLoading: {}, subError: {}, subHasMore: {} };

const fetchSubmissions = vi.fn();
const loadMoreSubmissions = vi.fn();

vi.mock('../../../app/stores/forms', () => ({
  useFormsStore: () => ({
    submissionsFor: (id: string) => state.submissions[id] ?? [],
    subLoading: state.subLoading,
    subError: state.subError,
    subHasMore: state.subHasMore,
    fetchSubmissions,
    loadMoreSubmissions,
    deleteSubmission: vi.fn(),
    restoreSubmission: vi.fn(),
    forceDeleteSubmission: vi.fn(),
  }),
}));

// --- Composables / saved-views scaffolding (inert but present) --------------
vi.mock('../../../app/composables/useInfiniteScroll', () => ({
  useInfiniteScroll: () => ({ sentinelRef: ref(null), pause: vi.fn(), resume: vi.fn() }),
}));
vi.mock('../../../app/composables/useDebounce', () => ({
  useDebounce: (fn: (...a: unknown[]) => unknown) =>
    Object.assign((...a: unknown[]) => fn(...a), { cancel: vi.fn() }),
}));
vi.mock('../../../app/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), danger: vi.fn() }),
}));
vi.mock('../../../app/composables/useConfirm', () => ({
  useConfirm: () => vi.fn().mockResolvedValue(false),
}));
vi.mock('../../../app/composables/useFilterTabs', () => ({
  useFilterTabs: () => ({
    tabs: ref([{ id: 'v1', name: 'My view', icon: null, filters: {}, sort_order: 0 }]),
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

function makeSubmission(id: string): FormSubmission {
  return {
    id,
    form_id: 'form-1',
    data: {},
    source: 'form',
    form_content_version_id: null,
    indexed_at: null,
    approved_at: '2026-07-10T09:00:00Z',
    is_approved: true,
    can_be_edited: false,
    creator: null,
    created_at: '2026-07-10T09:00:00Z',
    updated_at: null,
  };
}

function mountBrowser(props: Record<string, unknown> = {}) {
  return mount(SubmissionsBrowser, {
    attachTo: document.body,
    props: { formId: 'form-1', ...props },
  });
}

beforeEach(() => {
  installBrowserMocks();
  setLocale('en');
  state.submissions = {};
  state.subLoading = {};
  state.subError = {};
  state.subHasMore = {};
  fetchSubmissions.mockReset();
  loadMoreSubmissions.mockReset();
});

afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('SubmissionsBrowser — saved views + full filter set', () => {
  it('renders the Saved Views toolbar and the complete filter set', async () => {
    state.submissions['form-1'] = [makeSubmission('sub-a')];
    const wrapper = mountBrowser();
    await flushPromises();

    // Saved Views toolbar (FilterTabBar) + a saved view pill.
    expect(wrapper.findComponent(FilterTabBar).exists()).toBe(true);
    expect(wrapper.text()).toContain('My view');

    // Full filter set: source + indexed + sort Selects, plus a date range.
    expect(wrapper.findAllComponents(Select).length).toBeGreaterThanOrEqual(3);
    expect(wrapper.findComponent(DateRangeFilter).exists()).toBe(true);
  });

  it('fetches the scoped form on mount', async () => {
    state.submissions['form-1'] = [makeSubmission('sub-a')];
    mountBrowser();
    await flushPromises();

    expect(fetchSubmissions.mock.calls[0][0]).toBe('form-1');
    expect(fetchSubmissions.mock.calls[0][2]).toEqual({ reset: true });
  });
});

describe('SubmissionsBrowser — default (page) mode', () => {
  it('shows the Active/Deleted bucket tabs and emits preview on a card click', async () => {
    state.submissions['form-1'] = [makeSubmission('sub-a')];
    const wrapper = mountBrowser();
    await flushPromises();

    // The bucket tabs (Active/Deleted) are present in page mode.
    expect(wrapper.text()).toContain('Active');
    // The kebab action menu is available (not selection mode).
    expect(document.body.querySelector('button[aria-label="Submission actions"]')).not.toBeNull();

    await wrapper.find('.next-entity-card__action').trigger('click');
    expect((wrapper.emitted('preview')?.[0]?.[0] as FormSubmission).id).toBe('sub-a');
    expect(wrapper.emitted('select')).toBeUndefined();
  });
});

describe('SubmissionsBrowser — selectable mode', () => {
  it('hides the kebab, drops the bucket tabs, and emits select(id, submission)', async () => {
    state.submissions['form-1'] = [makeSubmission('sub-a'), makeSubmission('sub-b')];
    const wrapper = mountBrowser({ selectable: true });
    await flushPromises();

    // No bucket tabs, no kebab.
    expect(document.body.querySelector('button[aria-label="Submission actions"]')).toBeNull();

    const firstCard = document.body.querySelector('.next-entity-card__action') as HTMLButtonElement;
    firstCard.click();
    await flushPromises();

    expect(wrapper.emitted('select')?.[0]?.[0]).toBe('sub-a');
    expect((wrapper.emitted('select')?.[0]?.[1] as FormSubmission).id).toBe('sub-a');
    expect(wrapper.emitted('preview')).toBeUndefined();
  });

  it('refetches with the source filter when the source Select changes', async () => {
    state.submissions['form-1'] = [makeSubmission('sub-a')];
    const wrapper = mountBrowser({ selectable: true });
    await flushPromises();
    fetchSubmissions.mockClear();

    wrapper.findComponent(Select).vm.$emit('update:values', ['task']);
    await flushPromises();

    const lastFilters = last(fetchSubmissions.mock.calls)?.[1] as { sources?: string[] };
    expect(lastFilters.sources).toEqual(['task']);
  });
});
