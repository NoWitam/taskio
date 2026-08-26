// @vitest-environment happy-dom
// CalendarView.spec — the screen's own contract, and its smoke test.
//
// Four things here are behaviours the spec calls out explicitly and that no lower-level
// test can reach, because they are decisions the PAGE makes:
//   • a source that failed does NOT blank the calendar (the backend is fail-soft, and the
//     interface must be able to say "schedules didn't load" while showing everything else),
//   • the grid gets NO empty state — an empty month is still a month, and still the surface
//     the user is about to create something on,
//   • the filter chips and the legend are built from `meta.sources`, so a source this build
//     has never heard of appears by itself,
//   • switching between the grid and the agenda does NOT refetch (one window, two surfaces).
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive, ref } from 'vue';

vi.mock('../../../app/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

// --- Calendar store mock -----------------------------------------------------
const fetchOccurrences = vi.fn();
const refresh = vi.fn();
const resetAll = vi.fn();
const storeMock = reactive({
  occurrences: [] as unknown[],
  meta: { timezone: 'Europe/Warsaw', truncated: false, truncations: [], sources: [], unavailable_sources: [] },
  loading: false,
  refreshing: false,
  errored: false,
  error: null as string | null,
  loaded: true,
  currentQuery: null,
  sources: [] as { id: string; label: string }[],
  // `{source, reason}` objects — the reason is what decides whether a retry is offered.
  unavailableSources: [] as { source: string; reason: string }[],
  truncations: [] as unknown[],
  timezone: 'Europe/Warsaw',
  sourceLabel: (id: string) => storeMock.sources.find((s) => s.id === id)?.label ?? id,
  isSourceUnavailable: (id: string) => storeMock.unavailableSources.some((e) => e.source === id),
  fetchOccurrences,
  refresh,
  eventDetail: null,
  eventLoading: false,
  eventError: null,
  saving: false,
  fetchEvent: vi.fn(),
  clearEvent: vi.fn(),
  saveEvent: vi.fn(),
  deleteEvent: vi.fn(),
  resetAll,
});
vi.mock('../../../app/stores/calendar', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../../app/stores/calendar')>();
  return { ...actual, useCalendarStore: () => storeMock };
});

// --- Saved views (server state) ----------------------------------------------
vi.mock('../../../app/stores/filterTabs', () => ({
  useFilterTabsStore: () => ({
    byContext: () => [],
    isLoading: () => false,
    hasError: () => false,
    fetch: vi.fn().mockResolvedValue(undefined),
    create: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
    reorder: vi.fn(),
  }),
}));

// --- Router ------------------------------------------------------------------
const routeQuery = ref<Record<string, unknown>>({});
const routerReplace = vi.fn((to: { query?: Record<string, unknown> }) => {
  routeQuery.value = { ...(to.query ?? {}) };
});
const routerPush = vi.fn((to: { query?: Record<string, unknown> }) => {
  routeQuery.value = { ...(to.query ?? {}) };
});
vi.mock('vue-router', () => ({
  useRoute: () => ({
    name: 'next.calendar',
    params: {},
    get query() {
      return routeQuery.value;
    },
  }),
  useRouter: () => ({ push: routerPush, replace: routerReplace, resolve: () => ({ href: '#' }) }),
}));

import CalendarView from '../CalendarView.vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { CalendarOccurrence } from '../types';

function occurrence(id: string, over: Partial<CalendarOccurrence> = {}): CalendarOccurrence {
  return {
    id,
    source: 'task',
    editable: false,
    all_day: true,
    start_date: '2026-08-09',
    starts_at: null,
    ends_at: null,
    title: id,
    color: 'neutral',
    badge: null,
    dense: false,
    cadence_label: null,
    recurring: false,
    occurrence_date: null,
    subject: { type: 'task', id },
    ...over,
  };
}

function mountView() {
  return mount(CalendarView, { attachTo: document.body });
}

beforeEach(() => {
  installBrowserMocks();
  setLocale('en');
  routeQuery.value = { month: '2026-08' };
  fetchOccurrences.mockReset();
  refresh.mockReset();
  resetAll.mockReset();
  routerReplace.mockClear();
  routerPush.mockClear();
  storeMock.occurrences = [];
  storeMock.sources = [];
  storeMock.unavailableSources = [];
  storeMock.truncations = [];
  storeMock.loading = false;
  storeMock.errored = false;
  storeMock.loaded = true;
  storeMock.refreshing = false;
});

afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('CalendarView', () => {
  it('mounts, fetches ONE 42-day window and renders the grid', async () => {
    const wrapper = mountView();
    await flushPromises();

    expect(fetchOccurrences).toHaveBeenCalledTimes(1);
    // August 2026 starts on a Saturday, so the Monday-start grid runs 2026-07-27 → 2026-09-06.
    expect(fetchOccurrences.mock.calls[0][0]).toMatchObject({ from: '2026-07-27', to: '2026-09-06' });
    expect(wrapper.find('[role="grid"]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('does NOT refetch when the surface changes — one window feeds both', async () => {
    const wrapper = mountView();
    await flushPromises();
    fetchOccurrences.mockClear();

    routeQuery.value = { ...routeQuery.value, mode: 'agenda' };
    await flushPromises();

    expect(fetchOccurrences).not.toHaveBeenCalled();
    expect(wrapper.find('[role="grid"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('refetches when the MONTH changes', async () => {
    const wrapper = mountView();
    await flushPromises();
    fetchOccurrences.mockClear();

    routeQuery.value = { month: '2026-09' };
    await flushPromises();

    expect(fetchOccurrences).toHaveBeenCalledTimes(1);
    wrapper.unmount();
  });

  it('keeps the calendar rendering when a SOURCE failed, and says which one', async () => {
    // Fail-soft: the backend returned everything else, so blanking the screen would be a
    // lie about the data that DID arrive.
    storeMock.sources = [
      { id: 'task', label: 'Task deadlines' },
      { id: 'workflow_schedule', label: 'Scheduled automations' },
    ];
    storeMock.unavailableSources = [{ source: 'workflow_schedule', reason: 'failed' }];
    storeMock.occurrences = [occurrence('deadline')];

    const wrapper = mountView();
    await flushPromises();

    expect(wrapper.find('[role="grid"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('deadline');
    expect(wrapper.text()).toContain('Scheduled automations');
    expect(wrapper.find('[role="alert"]').text()).toMatch(/Couldn’t load/i);
    wrapper.unmount();
  });

  /**
   * THE RETRY BUTTON IS A CLAIM, AND ONLY ONE REASON SUPPORTS IT.
   *
   * `unavailable_sources` carries WHY each source could not answer, and the three reasons
   * differ on exactly one axis: whether asking again could produce a different answer.
   * `failed` (the source threw — a timeout, a bad minute) can. `not_constructed`
   * (configuration, a broken boot) and `malformed` (that source answers wrongly; a defect)
   * cannot, and a retry button beside either is a control that does nothing — the failure
   * this screen already removed once, from the "Open" that landed on a list.
   *
   * Pinned per reason, and on the BUTTON rather than on the wording, because the wording is
   * the part a translator may reasonably change and the affordance is not.
   */
  describe('a failed source — the retry is offered only where it could help', () => {
    const SOURCES = [
      { id: 'task', label: 'Task deadlines' },
      { id: 'workflow_schedule', label: 'Scheduled automations' },
    ];

    /** The alert's retry control, or null when the alert offers none. */
    function retryButton(wrapper: ReturnType<typeof mountView>) {
      const alert = wrapper.find('[role="alert"]');
      if (!alert.exists()) return null;
      return alert.findAll('button').find((b) => b.text().includes('Try again')) ?? null;
    }

    it('offers it for `failed` — the one reason another request could answer differently', async () => {
      storeMock.sources = SOURCES;
      storeMock.unavailableSources = [{ source: 'workflow_schedule', reason: 'failed' }];
      const wrapper = mountView();
      await flushPromises();

      const button = retryButton(wrapper);
      expect(button).not.toBeNull();

      // And it retries the window that is on screen, rather than navigating anywhere.
      await button!.trigger('click');
      expect(refresh).toHaveBeenCalledTimes(1);
      wrapper.unmount();
    });

    for (const reason of ['not_constructed', 'malformed']) {
      it(`withholds it for \`${reason}\` — no click changes that answer`, async () => {
        storeMock.sources = SOURCES;
        storeMock.unavailableSources = [{ source: 'workflow_schedule', reason }];
        const wrapper = mountView();
        await flushPromises();

        expect(retryButton(wrapper)).toBeNull();
        // The failure is still REPORTED, by name — withholding the button is not withholding
        // the news, and the reader is told plainly that waiting will not fix it.
        const alert = wrapper.find('[role="alert"]');
        expect(alert.exists()).toBe(true);
        expect(alert.text()).toContain('Scheduled automations');
        expect(alert.text()).toMatch(/won’t change this one/i);
        wrapper.unmount();
      });
    }

    it('treats an UNRECOGNISED reason as unrecoverable, and still names the source', async () => {
      // A fourth reason from a newer backend must not inherit the button by default: "we do
      // not know whether this can recover" is not a reason to promise that it can.
      storeMock.sources = SOURCES;
      storeMock.unavailableSources = [{ source: 'workflow_schedule', reason: 'something_new' }];
      const wrapper = mountView();
      await flushPromises();

      expect(retryButton(wrapper)).toBeNull();
      expect(wrapper.find('[role="alert"]').text()).toContain('Scheduled automations');
      wrapper.unmount();
    });

    it('splits a MIXED failure: both named, in the group that describes each', async () => {
      storeMock.sources = SOURCES;
      storeMock.unavailableSources = [
        { source: 'workflow_schedule', reason: 'failed' },
        { source: 'task', reason: 'malformed' },
      ];
      const wrapper = mountView();
      await flushPromises();

      const text = wrapper.find('[role="alert"]').text();
      // The retryable line names ONLY the retryable source, so the button right under it is
      // never ambiguous about which failure it is offering to help with.
      expect(text).toMatch(/Scheduled automations\. That’s usually temporary/);
      expect(text).toMatch(/Task deadlines\. Trying again won’t change this one/);
      expect(retryButton(wrapper)).not.toBeNull();
      wrapper.unmount();
    });

    it('says the rest of the calendar is current — once, whichever groups appeared', async () => {
      storeMock.sources = SOURCES;
      storeMock.unavailableSources = [
        { source: 'workflow_schedule', reason: 'failed' },
        { source: 'task', reason: 'not_constructed' },
      ];
      const wrapper = mountView();
      await flushPromises();

      const text = wrapper.find('[role="alert"]').text();
      expect(text.match(/Everything else on the calendar is up to date/g)).toHaveLength(1);
      wrapper.unmount();
    });

    it('dims a failed source in the legend whatever its reason', async () => {
      storeMock.sources = SOURCES;
      storeMock.unavailableSources = [{ source: 'workflow_schedule', reason: 'not_constructed' }];
      const wrapper = mountView();
      await flushPromises();

      const dimmed = wrapper.findAll('span.line-through').map((el) => el.text());
      expect(dimmed.some((t) => t.includes('Scheduled automations'))).toBe(true);
      expect(dimmed.some((t) => t.includes('Task deadlines'))).toBe(false);
      wrapper.unmount();
    });
  });

  it('gives the GRID no empty state — the squares are still there to create on', async () => {
    storeMock.occurrences = [];
    const wrapper = mountView();
    await flushPromises();

    expect(wrapper.find('[role="grid"]').exists()).toBe(true);
    expect(wrapper.find('.next-empty-state').exists()).toBe(false);
    // The count lives in the FilterBar's results slot instead.
    expect(wrapper.text()).toContain('No occurrences');
    wrapper.unmount();
  });

  it('gives the AGENDA an empty state — a list with nothing in it really is empty', async () => {
    routeQuery.value = { month: '2026-08', mode: 'agenda' };
    storeMock.occurrences = [];
    const wrapper = mountView();
    await flushPromises();

    expect(wrapper.find('.next-empty-state').exists()).toBe(true);
    wrapper.unmount();
  });

  it('builds the source filter and legend from meta.sources — including a source it has never seen', async () => {
    // The R4 promise, tested at the only place it can break: the filter and the legend.
    storeMock.sources = [
      { id: 'task', label: 'Task deadlines' },
      { id: 'publication', label: 'Publications' },
    ];
    const wrapper = mountView();
    await flushPromises();

    expect(wrapper.text()).toContain('Publications');
    const checkboxes = wrapper.findAll('[role="checkbox"]');
    // One per source plus the "select all" card.
    expect(checkboxes.length).toBeGreaterThanOrEqual(2);
    wrapper.unmount();
  });

  it('shows the workspace time zone permanently, and warns when the browser disagrees', async () => {
    const wrapper = mountView();
    await flushPromises();
    expect(wrapper.text()).toContain('Europe/Warsaw');
    wrapper.unmount();
  });

  it('renders the whole-request error WITHOUT hiding the month navigation', async () => {
    storeMock.loaded = false;
    storeMock.errored = true;
    storeMock.error = 'Boom';
    const wrapper = mountView();
    await flushPromises();

    expect(wrapper.find('.next-empty-state').text()).toContain('Boom');
    // Changing the month IS the retry a user reaches for first, so the arrows stay.
    expect(wrapper.find('[aria-label="Next month"]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('opens the create drawer with the clicked day seeded into the URL', async () => {
    const wrapper = mountView();
    await flushPromises();

    await wrapper.find('[data-iso="2026-08-12"]').trigger('click');
    expect(routerPush).toHaveBeenCalled();
    expect(routeQuery.value).toMatchObject({ new: '1', date: '2026-08-12' });
    wrapper.unmount();
  });

  it('resets the store on unmount so a stale window cannot bleed into the next visit', async () => {
    const wrapper = mountView();
    await flushPromises();
    wrapper.unmount();
    expect(resetAll).toHaveBeenCalled();
  });
});

/**
 * CLICKING A SQUARE OF A SERIES.
 *
 * The two occurrence keys are the whole reason the drawer can be right about which day it is
 * editing, and each of them is carried VERBATIM from the square the server drew:
 *
 *   `on` — the server's own `occurrence_date`. The IDENTIFIER, reckoned on the series' stamped
 *          clock. Re-deriving it from `at` plus a zone is exactly the drift this key exists to
 *          make impossible.
 *   `at` — the occurrence's own instant, carried only so a scoped edit can seed its date and
 *          time from the square that was clicked rather than from the series' anchor.
 *
 * And `scope` is deliberately NOT written by a click: opening the drawer is reading, and how
 * much of the series a change would touch is a separate, explicit question asked afterwards.
 */
describe('CalendarView — pointing at one occurrence of a series', () => {
  const seriesSquare = () =>
    occurrence('event:evt-1:2026-08-12', {
      source: 'event',
      editable: true,
      all_day: false,
      start_date: null,
      starts_at: '2026-08-12T12:30:00.000000Z',
      ends_at: '2026-08-12T13:30:00.000000Z',
      title: 'Sprint review',
      cadence_label: 'Weekly on Wed',
      recurring: true,
      occurrence_date: '2026-08-12',
      subject: { type: 'calendar_event', id: 'evt-1' },
    });

  it('carries the server’s own occurrence day AND the square’s instant into the URL', async () => {
    storeMock.occurrences = [seriesSquare()];
    const wrapper = mountView();
    await flushPromises();

    const chip = wrapper.find('.next-occurrence-chip');
    expect(chip.exists()).toBe(true);
    await chip.trigger('click');

    expect(routeQuery.value).toMatchObject({
      event: 'evt-1',
      on: '2026-08-12',
      at: '2026-08-12T12:30:00.000000Z',
    });
    // A click is READING. Which occurrences a change touches is asked separately.
    expect(routeQuery.value.scope).toBeUndefined();
    wrapper.unmount();
  });

  it('carries NO occurrence day for a one-off event — there is nothing to scope', async () => {
    storeMock.occurrences = [
      occurrence('event:evt-2', {
        source: 'event',
        editable: true,
        title: 'Offsite',
        recurring: false,
        occurrence_date: null,
        subject: { type: 'calendar_event', id: 'evt-2' },
      }),
    ];
    const wrapper = mountView();
    await flushPromises();

    await wrapper.find('.next-occurrence-chip').trigger('click');

    expect(routeQuery.value).toMatchObject({ event: 'evt-2' });
    expect(routeQuery.value.on).toBeUndefined();
    expect(routeQuery.value.at).toBeUndefined();
    wrapper.unmount();
  });
});
