// @vitest-environment happy-dom
// PublicationsView.spec — "we could not count" is not "there is nothing" (§16.4 pkt 9).
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// THE ONE LIE NOBODY CAN SEE
// ═════════════════════════════════════════════════════════════════════════════════════════
// `GET /counts` is a SECOND request beside the list, and it can fail on its own. The store
// already refuses to invent a number — `counts` goes back to `null` and a `?? 0` anywhere in
// it would turn the failure into a claim — and `stores/publishing` has its own spec for that.
// What nothing pinned is the other half, on the SCREEN: that a null reaches the tab bar as an
// ABSENT badge rather than a zero.
//
// The difference matters more here than anywhere else in the module, because of what the
// zeroes would be saying. "Needs checking: 0" is the sentence somebody uses to decide there
// is nothing to check — and `needs_reconcile` is the state where a publication may already be
// in the world and nobody knows. A tab bar that says 0 because a request timed out is an
// invitation to stop looking.
//
// So: no badges, no `0` anywhere in the bar, and a banner that says the counting failed and
// offers to try again — while the LIST and every filter stay live, because the list itself
// answered perfectly well.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive, ref } from 'vue';

// --- Router -----------------------------------------------------------------
const routeMock = reactive({ query: {} as Record<string, unknown>, path: '/publishing/publications' });
vi.mock('vue-router', () => ({
  useRoute: () => routeMock,
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}));

// --- Stores -----------------------------------------------------------------
const fetchPublications = vi.fn();
const fetchCounts = vi.fn();
const storeMock = reactive({
  items: [] as Publication[],
  counts: null as PublicationCounts | null,
  countsErrored: false,
  loading: false,
  loadingMore: false,
  errored: false,
  error: null as string | null,
  loadMoreErrored: false,
  hasMore: false,
  timezone: 'Europe/Warsaw' as string | null,
  fetchPublications,
  fetchCounts,
  loadMore: vi.fn(),
  retryLoadMore: vi.fn(),
  loadTimezone: vi.fn().mockResolvedValue(undefined),
  deletePublication: vi.fn(),
});
vi.mock('../../../app/stores/publishing', () => ({ usePublishingStore: () => storeMock }));

vi.mock('../../../app/stores/publishingConnections', () => ({
  usePublishingConnectionsStore: () => reactive({
    connections: [],
    fetchConnections: vi.fn().mockResolvedValue(undefined),
  }),
}));

vi.mock('../../../app/stores/filterTabs', () => ({
  useFilterTabsStore: () => ({ update: vi.fn(), remove: vi.fn(), reorder: vi.fn() }),
}));

// The saved-views composable has its own spec; here it only has to exist so the toolbar
// renders. `decorateActiveFilters` is identity — the chips are not what this file is about.
vi.mock('../../../app/composables/useFilterTabs', () => ({
  useFilterTabs: () => ({
    tabs: ref([]),
    activeTabId: ref(null),
    dirty: ref(false),
    loading: ref(false),
    loadError: ref(null),
    decorateActiveFilters: (f: unknown[]) => f,
    restoreFilter: vi.fn(),
    applyTab: vi.fn(),
    clearActive: vi.fn(),
    saveActive: vi.fn(),
    saveAs: vi.fn(),
    load: vi.fn().mockResolvedValue(undefined),
  }),
}));

// The sentinel's IntersectionObserver has nothing to observe in a detached test DOM.
vi.mock('../../../app/composables/useInfiniteScroll', () => ({
  useInfiniteScroll: () => ({ sentinelRef: ref(null) }),
}));

const toast = { success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() };
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => toast }));
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => vi.fn() }));

import PublicationsView from '../PublicationsView.vue';
import { setLocale } from '../../../app/i18n';
import type { Publication, PublicationCounts } from '../types';

function counts(overrides: Partial<PublicationCounts> = {}): PublicationCounts {
  return {
    counts: {
      draft: 3,
      scheduled: 5,
      publishing: 1,
      published: 12,
      failed: 2,
      needs_reconcile: 1,
      blocked: 4,
    },
    total: 28,
    needs_attention: 7,
    ...overrides,
  } as PublicationCounts;
}

async function mountView() {
  const wrapper = mount(PublicationsView, {
    global: {
      stubs: {
        RouterLink: true,
        // Everything with its own spec, so this file can only fail for reasons about counts.
        FilterBar: true,
        FilterTabBar: true,
        SaveViewModal: true,
        ConfirmDialog: true,
        Select: true,
        DateRangeFilter: true,
        PublicationCard: true,
        SchedulePublicationModal: true,
        // `Tabs` stays REAL: its badge is the thing under test.
      },
    },
  });
  await flushPromises();
  return wrapper;
}

type Wrapper = Awaited<ReturnType<typeof mountView>>;

/** The status bar, tab by tab, exactly as a reader sees it. */
function tabTexts(wrapper: Wrapper): string[] {
  return wrapper.findAll('[role="tab"]').map((t) => t.text().trim());
}

/** The badges inside the tab bar — absent, not zero, is the whole point. */
function tabBadges(wrapper: Wrapper): string[] {
  return wrapper.findAll('[role="tab"] .next-badge').map((b) => b.text().trim());
}

beforeEach(() => {
  setLocale('en');
  routeMock.query = {};
  storeMock.items = [];
  storeMock.counts = null;
  storeMock.countsErrored = false;
  storeMock.loading = false;
  storeMock.errored = false;
  storeMock.error = null;
  storeMock.hasMore = false;
  fetchPublications.mockReset().mockResolvedValue(undefined);
  fetchCounts.mockReset().mockResolvedValue(undefined);
  toast.danger.mockReset();
});

describe('when `/counts` answers', () => {
  it('puts the server’s number on every tab, including a real zero', async () => {
    storeMock.counts = counts({
      counts: {
        draft: 3,
        scheduled: 0,
        publishing: 0,
        published: 12,
        failed: 0,
        needs_reconcile: 0,
        blocked: 0,
      },
      total: 15,
      needs_attention: 0,
    });
    const wrapper = await mountView();

    // A CONFIRMED zero is information and is shown: the server counted, and the answer is
    // none. It is the UNCOUNTED case below that must not look like this one.
    expect(tabBadges(wrapper)).toEqual(['15', '3', '0', '0', '12', '0', '0', '0']);
    expect(tabTexts(wrapper)[0]).toContain('All');
  });
});

describe('when `/counts` FAILS but the list did not', () => {
  beforeEach(() => {
    storeMock.counts = null;
    storeMock.countsErrored = true;
  });

  it('renders NO badges, and no `0` anywhere in the bar', async () => {
    const wrapper = await mountView();

    expect(tabBadges(wrapper)).toEqual([]);
    // Said as a whole-bar property, because a single stray zero is the defect.
    expect(tabTexts(wrapper).join(' ')).not.toMatch(/\d/);
    // The eight tabs are all still there and still navigable — only the numbers are unknown.
    expect(tabTexts(wrapper)).toHaveLength(8);
  });

  it('says the counting failed, and offers to try just that again', async () => {
    const wrapper = await mountView();

    expect(wrapper.text()).toContain('The tab counts could not be loaded');
    const retry = wrapper.findAll('button').find((b) => b.text().includes('Try again'))!;
    expect(retry).toBeDefined();

    const before = fetchCounts.mock.calls.length;
    await retry.trigger('click');
    await flushPromises();

    // Only the counts are re-asked for: the list is on screen and was never the problem.
    expect(fetchCounts.mock.calls.length).toBe(before + 1);
    expect(fetchPublications.mock.calls.length).toBe(1);
  });

  it('does not claim anything about what needs a decision', async () => {
    const wrapper = await mountView();
    // The attention banner's number is the SERVER's `needs_attention`; with no answer there
    // is no banner rather than a reassuring "0 publications need a decision".
    expect(wrapper.text()).not.toContain('need a decision');
  });
});

describe('the "needs a decision" banner', () => {
  it('uses the server’s own total, never a sum of the client’s three counts', async () => {
    // The two disagree on purpose here: the day an eighth status joins that set, a client
    // doing its own arithmetic would be quietly wrong, and this is what that looks like.
    storeMock.counts = counts({ needs_attention: 9 });
    const wrapper = await mountView();

    expect(wrapper.text()).toContain('9 publications need a decision');
    // 2 + 1 + 4 = 7, which is what a client-side sum would have said.
    expect(wrapper.text()).not.toContain('7 publications need a decision');
  });

  it('links only to the attention tabs that actually have rows', async () => {
    storeMock.counts = counts({
      counts: {
        draft: 3,
        scheduled: 5,
        publishing: 0,
        published: 12,
        failed: 2,
        needs_reconcile: 0,
        blocked: 0,
      },
      needs_attention: 2,
    });
    const wrapper = await mountView();
    const links = wrapper.findAll('button').map((b) => b.text());

    expect(links.some((l) => l.includes('Failed (2)'))).toBe(true);
    // No road to an empty tab.
    expect(links.some((l) => l.includes('Needs checking (0)'))).toBe(false);
    expect(links.some((l) => l.includes('On hold (0)'))).toBe(false);
  });

  it('is absent when nothing needs a decision', async () => {
    storeMock.counts = counts({ needs_attention: 0 });
    const wrapper = await mountView();
    expect(wrapper.text()).not.toContain('need a decision');
  });
});
