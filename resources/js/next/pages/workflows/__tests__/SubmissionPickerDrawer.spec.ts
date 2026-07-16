// @vitest-environment happy-dom
// SubmissionPickerDrawer.spec — the run-now "Pick submission" drawer (B8).
//
// The drawer is now a thin shell around the SHARED SubmissionsBrowser (in
// `selectable` mode), so its filters + Saved Views tabs are IDENTICAL to the Forms
// submissions LIST page. This spec pins the drawer's own contract: (a) it mounts
// the browser scoped to the form and the browser fetches its submissions on open;
// (b) the SHARED Saved Views toolbar + full filter set are now present (they were
// absent in the old lean drawer); (c) a source filter change refetches; (d) picking
// a card emits `select(id, submission)` and closes the drawer; (e) the form_id-null
// case shows a FormSelect step first and only mounts the browser once a form is
// chosen; (f) empty / error states. The forms store + saved-views scaffolding are
// mocked (no HTTP); FormSelect is stubbed; i18n renders via setLocale('en').
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';
import SubmissionPickerDrawer from '../SubmissionPickerDrawer.vue';
import Drawer from '../../../ui/overlay/Drawer.vue';
import SubmissionCard from '../../forms/SubmissionCard.vue';
import FilterTabBar from '../../../ui/patterns/FilterTabBar.vue';
import Select from '../../../ui/forms/Select.vue';
import DateRangeFilter from '../../../ui/forms/DateRangeFilter.vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { FormSubmission } from '../../forms/types';

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

// --- Shared browser scaffolding (inert but present) -------------------------
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

// FormSelect stub — a button that emits a form id (avoids the /forms HTTP fetch).
const FormSelectStub = defineComponent({
  name: 'FormSelect',
  props: { modelValue: { default: null } },
  emits: ['update:modelValue'],
  setup(_, { emit }) {
    return () => h('button', { 'data-test': 'form-select', onClick: () => emit('update:modelValue', 'form-2') });
  },
});

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

function mountDrawer(props: Record<string, unknown> = {}) {
  return mount(SubmissionPickerDrawer, {
    attachTo: document.body,
    props: { open: true, formId: 'form-1', ...props },
    global: { stubs: { FormSelect: FormSelectStub } },
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

describe('SubmissionPickerDrawer — mounts the shared browser (scoped to the form)', () => {
  it('fetches the bound form on open and lists its submissions', async () => {
    state.submissions['form-1'] = [makeSubmission('sub-a'), makeSubmission('sub-b')];
    const wrapper = mountDrawer();
    await flushPromises();

    expect(fetchSubmissions.mock.calls[0][0]).toBe('form-1');
    expect(fetchSubmissions.mock.calls[0][2]).toEqual({ reset: true });
    expect(wrapper.findAllComponents(SubmissionCard)).toHaveLength(2);
  });

  it('now shows the SHARED Saved Views tabs + full filter set (identical to the list)', async () => {
    state.submissions['form-1'] = [makeSubmission('sub-a')];
    const wrapper = mountDrawer();
    await flushPromises();

    // The saved-views toolbar (absent in the old lean drawer) is now present.
    // (The Drawer teleports to body, so read the document, not the wrapper root.)
    expect(wrapper.findComponent(FilterTabBar).exists()).toBe(true);
    expect(document.body.textContent).toContain('My view');
    // The full filter set: source + indexed + sort Selects + a date range.
    expect(wrapper.findAllComponents(Select).length).toBeGreaterThanOrEqual(3);
    expect(wrapper.findComponent(DateRangeFilter).exists()).toBe(true);
  });

  it('refetches with the source filter when the source Select changes', async () => {
    state.submissions['form-1'] = [makeSubmission('sub-a')];
    const wrapper = mountDrawer();
    await flushPromises();
    fetchSubmissions.mockClear();

    wrapper.findComponent(Select).vm.$emit('update:values', ['task']);
    await flushPromises();

    expect(fetchSubmissions).toHaveBeenCalled();
    const lastFilters = last(fetchSubmissions.mock.calls)?.[1] as { sources?: string[] };
    expect(lastFilters.sources).toEqual(['task']);
  });
});

describe('SubmissionPickerDrawer — sizing', () => {
  it('mounts the Drawer at the widest fixed size so the full filter row is uncramped', async () => {
    state.submissions['form-1'] = [makeSubmission('sub-a')];
    const wrapper = mountDrawer();
    await flushPromises();

    // The picker hosts the full browser (search + source + indexed + sort + date +
    // saved-views + list) — it uses the widest fixed Drawer size (~2x the old `xl`).
    expect(wrapper.findComponent(Drawer).props('size')).toBe('4xl');
    // Still responsive: the Drawer caps its width to the viewport on small screens.
    const panel = document.body.querySelector('.next-drawer') as HTMLElement;
    expect(panel.className).toContain('max-w-[calc(100vw-2rem)]');
  });
});

describe('SubmissionPickerDrawer — selection', () => {
  it('emits select(id, submission) and closes when a card is picked', async () => {
    state.submissions['form-1'] = [makeSubmission('sub-a'), makeSubmission('sub-b')];
    const wrapper = mountDrawer();
    await flushPromises();

    const firstCard = document.body.querySelector('.next-entity-card__action') as HTMLButtonElement;
    firstCard.click();
    await flushPromises();

    expect(wrapper.emitted('select')?.[0]?.[0]).toBe('sub-a');
    expect((wrapper.emitted('select')?.[0]?.[1] as FormSubmission).id).toBe('sub-a');
    expect(last(wrapper.emitted('update:open'))).toEqual([false]);
  });
});

describe('SubmissionPickerDrawer — any-form (form_id null) two-step', () => {
  it('shows a FormSelect step first, then mounts the browser once a form is chosen', async () => {
    state.submissions['form-2'] = [makeSubmission('sub-x')];
    const wrapper = mountDrawer({ formId: null });
    await flushPromises();

    // Step 1: no fetch, no cards, a FormSelect is shown.
    expect(fetchSubmissions).not.toHaveBeenCalled();
    expect(wrapper.findAllComponents(SubmissionCard)).toHaveLength(0);
    expect(document.body.querySelector('[data-test="form-select"]')).not.toBeNull();

    // Choose a form → the browser mounts scoped to it and fetches.
    (document.body.querySelector('[data-test="form-select"]') as HTMLButtonElement).click();
    await flushPromises();

    expect(last(fetchSubmissions.mock.calls)?.[0]).toBe('form-2');
    expect(wrapper.findAllComponents(SubmissionCard)).toHaveLength(1);
  });
});

describe('SubmissionPickerDrawer — empty / error states', () => {
  it('renders the empty state when the form has no submissions', async () => {
    state.submissions['form-1'] = [];
    state.subHasMore['form-1'] = false;
    mountDrawer();
    await flushPromises();

    expect(document.body.textContent).toContain('No submissions yet');
    expect(document.body.querySelector('.next-entity-card__action')).toBeNull();
  });

  it('renders the error state when the fetch failed', async () => {
    state.submissions['form-1'] = [];
    state.subError['form-1'] = 'boom';
    mountDrawer();
    await flushPromises();

    expect(document.body.textContent).toContain('Couldn’t load submissions');
  });
});
