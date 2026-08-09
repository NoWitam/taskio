// @vitest-environment happy-dom
// KnowledgeComposeView.spec — the AI composer (spec §24).
//
// What is pinned here is what the screen PROMISES:
//   • availability is decided BEFORE the form exists (DC9) — three states, three renderings;
//   • generation settles on ONE event and NEVER polls (DC1/R15) — the single hardest requirement
//     in this batch, and the easiest to regress into a `setInterval`;
//   • the failure reason is the SERVER's, mapped to copy, never "something went wrong";
//   • bulk acceptance is explicit, confirmed and counted (DC8);
//   • leaving with unreviewed drafts is guarded (§24.9).
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale, translate } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const h = vi.hoisted(() => {
  const availability = {
    can_compose: true,
    reason: null as string | null,
    budget: {
      cost_used: 1,
      cost_cap: 10,
      cost_remaining: 9,
      warn_reached: false,
      blocked: false,
      period: { resets_at: '2026-09-01T00:00:00Z' },
    },
    limits: { source_max_chars: 20000, prompt_max_chars: 2000, max_entries_per_session: 8 },
  };

  const draft = (id: string, title: string, extra: Record<string, unknown> = {}) => ({
    id,
    knowledge_base_id: 'b1',
    title,
    slug: title.toLowerCase().replace(/\s+/g, '-'),
    content: `Treść ${title}`,
    metadata: {},
    status: 'draft',
    stale_at: null,
    is_stale: false,
    position: 0,
    current_revision_id: `r-${id}`,
    index: { status: null, chunks_count: null, indexed_chunks_count: null, needs_indexing: false, can_retry: false },
    draft_session_id: 's1',
    creator: null,
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    can_be_purged: true,
    created_at: null,
    updated_at: null,
    deleted_at: null,
    ...extra,
  });

  const session = {
    id: 's1',
    knowledge_base_id: 'b1',
    status: 'ready' as string,
    failure_reason: null as string | null,
    source_text: 'Wszystko o zwrotach.',
    prompt_history: [] as string[],
    seed_slug: null,
    seed_title: null,
    drafts: [draft('d1', 'Polityka zwrotow'), draft('d2', 'Terminy')],
    duplicates: {} as Record<string, unknown>,
    creator: null,
    created_at: null,
    updated_at: null,
  };

  return {
    availability,
    session,
    draft,
    /** Every settle call, so a test can prove there was exactly ONE wait and no loop. */
    settleCalls: [] as string[],
    router: { push: vi.fn(), replace: vi.fn() },
    route: { params: { baseId: 'b1' }, query: {} as Record<string, string> },
    toast: { success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() },
    confirm: vi.fn(),
    aiUsage: { summary: null, fetchAiUsage: vi.fn() },
    store: {
      openBase: { id: 'b1', name: 'Marka', entries_count: 3, metadata_schema: [] },
      composeAvailability: availability as unknown,
      composeAvailabilityLoading: false,
      composeAvailabilityErrored: false,
      session: null as unknown,
      sessionLoading: false,
      sessionErrored: false,
      sessionError: null,
      sessionMissing: false,
      fetchComposeAvailability: vi.fn(),
      startDraftSession: vi.fn(),
      fetchDraftSession: vi.fn(),
      refineDraftSession: vi.fn(),
      acceptDrafts: vi.fn(),
      rejectDraft: vi.fn(),
      abandonDraftSession: vi.fn(),
      fetchDraftDiff: vi.fn(),
      fetchDraftRelations: vi.fn(),
      resetComposeSession: vi.fn(),
    },
  };
});

vi.mock('vue-router', () => ({
  useRouter: () => h.router,
  useRoute: () => h.route,
  onBeforeRouteLeave: (guard: unknown) => {
    // Capture the guard so a test can run it, the way a real navigation would.
    (globalThis as Record<string, unknown>).__leaveGuard = guard;
  },
}));
vi.mock('../../../app/stores/knowledge', () => ({ useKnowledgeStore: () => h.store }));
vi.mock('../../../app/stores/aiUsage', () => ({ useAiUsageStore: () => h.aiUsage }));
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => h.toast }));
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => h.confirm }));
// The settle composable is the no-polling contract; the spec drives it directly.
vi.mock('../compose/useComposeSettle', () => ({
  useComposeSettle: () => ({
    waitForSettle: (id: string) => {
      h.settleCalls.push(id);
      return Promise.resolve({ session: h.store.session, settled: true });
    },
    dispose: vi.fn(),
  }),
}));

import KnowledgeComposeView from '../KnowledgeComposeView.vue';
import KnowledgeDraftBoard from '../compose/KnowledgeDraftBoard.vue';

const t = translate;

function mountView() {
  return mount(KnowledgeComposeView, { attachTo: document.body });
}

describe('KnowledgeComposeView', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('pl');
    vi.clearAllMocks();
    h.settleCalls.length = 0;
    h.confirm.mockResolvedValue(true);
    h.route.params = { baseId: 'b1' };
    h.route.query = {};
    h.store.composeAvailability = { ...h.availability, can_compose: true, reason: null };
    h.store.session = null;
    h.store.sessionMissing = false;
    h.store.fetchDraftSession.mockResolvedValue(h.session);
  });

  afterEach(() => {
    restoreBrowserMocks();
    document.body.innerHTML = '';
  });

  // --- DC9: availability decides BEFORE the form ----------------------------

  it('asks whether the composer can run at all, before rendering the form', async () => {
    const wrapper = mountView();
    await nextTick();

    expect(h.store.fetchComposeAvailability).toHaveBeenCalledWith('b1');
    expect(wrapper.find('[data-compose-source]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('renders the EXPLANATION instead of the form when the budget is gone', async () => {
    h.store.composeAvailability = {
      ...h.availability,
      can_compose: false,
      reason: 'ai_budget_exceeded',
      budget: { ...h.availability.budget, blocked: true },
    };

    const wrapper = mountView();
    await nextTick();

    expect(wrapper.find('[data-compose-unavailable]').exists()).toBe(true);
    expect(wrapper.find('[data-compose-source]').exists()).toBe(false);
    // The reset date, and the promise that the rest of the module still works.
    expect(wrapper.text()).toContain(t('knowledge.compose.unavailable.editingWorks'));
    wrapper.unmount();
  });

  it('names the KILL SWITCH as its own cause, not as a budget problem', async () => {
    h.store.composeAvailability = { ...h.availability, can_compose: false, reason: 'disabled' };

    const wrapper = mountView();
    await nextTick();

    expect(wrapper.text()).toContain(t('knowledge.compose.unavailable.disabled'));
    expect(wrapper.find('[data-compose-source]').exists()).toBe(false);
    wrapper.unmount();
  });

  // --- DC1 / R15: settle on the event, never poll ---------------------------

  it('starts a session, puts its id in the URL, and waits ONCE — no polling', async () => {
    h.store.startDraftSession.mockResolvedValue({ ...h.session, status: 'generating' });

    const wrapper = mountView();
    await nextTick();

    await wrapper.find('textarea').setValue('Wszystko o zwrotach.');
    await wrapper.find('[data-compose-start]').trigger('click');
    await nextTick();
    await nextTick();

    expect(h.store.startDraftSession).toHaveBeenCalledWith('b1', {
      source_text: 'Wszystko o zwrotach.',
    });
    // The session belongs in the URL: a refresh has to find the same board.
    expect(h.router.replace).toHaveBeenCalledWith(
      expect.objectContaining({ name: 'next.knowledge.base.compose', params: { baseId: 'b1', session: 's1' } }),
    );
    // EXACTLY ONE wait. A poll loop would show up here as many.
    expect(h.settleCalls).toEqual(['s1']);
    wrapper.unmount();
  });

  it('re-subscribes to a session that is still generating when the route is re-entered', async () => {
    h.route.params = { baseId: 'b1', session: 's1' };
    h.store.fetchDraftSession.mockResolvedValue({ ...h.session, status: 'generating' });

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    expect(h.store.fetchDraftSession).toHaveBeenCalledWith('s1');
    expect(h.settleCalls).toEqual(['s1']);
    wrapper.unmount();
  });

  // --- The board ------------------------------------------------------------

  it('renders one card per draft, under a counted heading', async () => {
    h.route.params = { baseId: 'b1', session: 's1' };
    h.store.session = h.session;

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    expect(wrapper.findAll('[data-draft-id]')).toHaveLength(2);
    expect(wrapper.text()).toContain(t('knowledge.compose.boardTitle', '', { count: 2 }));
    wrapper.unmount();
  });

  it('accepts the SELECTED drafts only, after a counted confirmation', async () => {
    h.route.params = { baseId: 'b1', session: 's1' };
    h.store.session = h.session;
    h.store.acceptDrafts.mockResolvedValue({ accepted: [{ id: 'd1' }], conflicts: [] });

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    // Select the first draft only.
    const checkboxes = wrapper.findAll('input[type="checkbox"]');
    await checkboxes[1].setValue(true);
    await nextTick();

    await wrapper.find('[data-accept-selected]').trigger('click');
    await nextTick();
    await nextTick();

    expect(h.confirm).toHaveBeenCalled();
    expect(h.store.acceptDrafts).toHaveBeenCalledWith('s1', ['d1'], 'approved');
    wrapper.unmount();
  });

  it('does not accept anything when the confirmation is declined', async () => {
    h.route.params = { baseId: 'b1', session: 's1' };
    h.store.session = h.session;
    h.confirm.mockResolvedValue(false);

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    const checkboxes = wrapper.findAll('input[type="checkbox"]');
    await checkboxes[1].setValue(true);
    await wrapper.find('[data-accept-selected]').trigger('click');
    await nextTick();

    expect(h.store.acceptDrafts).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  // --- Failure states -------------------------------------------------------

  it('shows the SERVER’s failure reason, not a generic apology', async () => {
    h.route.params = { baseId: 'b1', session: 's1' };
    h.store.session = { ...h.session, status: 'failed', failure_reason: 'unparseable', drafts: [] };

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    expect(wrapper.text()).toContain(t('knowledge.compose.failed.title'));
    expect(wrapper.text()).toContain(t('knowledge.compose.failed.reason.unparseable'));
    wrapper.unmount();
  });

  it('maps every failure reason the service can produce', async () => {
    for (const reason of ['unparseable', 'empty', 'seed_missed', 'provider', 'disabled']) {
      h.route.params = { baseId: 'b1', session: 's1' };
      h.store.session = { ...h.session, status: 'failed', failure_reason: reason, drafts: [] };

      const wrapper = mountView();
      await nextTick();
      await nextTick();

      expect(wrapper.text()).toContain(t(`knowledge.compose.failed.reason.${reason}`));
      wrapper.unmount();
    }
  });

  it('says a vanished session expired rather than spinning on it', async () => {
    h.route.params = { baseId: 'b1', session: 'gone' };
    h.store.sessionMissing = true;
    h.store.session = null;

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    expect(wrapper.text()).toContain(t('knowledge.compose.expired.title'));
    wrapper.unmount();
  });

  // --- Seeds ----------------------------------------------------------------

  it('prefills and EXPLAINS a seeded composer (a red link sent the user here)', async () => {
    h.route.query = { seed: 'polityka-zwrotow' };

    const wrapper = mountView();
    await nextTick();

    expect(wrapper.text()).toContain(t('knowledge.compose.seedNotice'));
    expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toContain('polityka-zwrotow');
    wrapper.unmount();
  });

  it('carries the seed slug into the session it starts', async () => {
    h.route.query = { seed: 'polityka-zwrotow' };
    h.store.startDraftSession.mockResolvedValue({ ...h.session, status: 'generating' });

    const wrapper = mountView();
    await nextTick();

    await wrapper.find('[data-compose-start]').trigger('click');
    await nextTick();

    expect(h.store.startDraftSession).toHaveBeenCalledWith(
      'b1',
      expect.objectContaining({ seed_slug: 'polityka-zwrotow' }),
    );
    wrapper.unmount();
  });

  // --- Run notes: WHERE a note renders is decided by its slug ----------------
  //
  // The routing rule is one line of code and the whole reason `entry_incomplete` is visible at all:
  // a note naming a slug is filed under that entry's CARD, everything else goes to the run list. A
  // dropped proposal HAS no card, so had that note carried a slug it would have rendered nowhere —
  // the fix would have shipped, passed review, and changed nothing on screen.

  it('files a note with NO slug in the run list, and one WITH a slug under its card', async () => {
    h.route.params = { baseId: 'b1', session: 's1' };
    h.store.session = {
      ...h.session,
      notes: [
        // The dropped proposal: no slug, because there is no card to attach it to.
        { code: 'entry_incomplete', field: 'title', name: 'polityka-zwrotow' },
        // An amendment note: names the entry it is about, so it belongs on that card.
        { code: 'amend_append_only', slug: 'polityka-zwrotow' },
      ],
    };

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    // In the run list…
    const runNote = wrapper.find('[data-note="entry_incomplete"]');
    expect(runNote.exists()).toBe(true);
    expect(runNote.text()).toContain('polityka-zwrotow');
    expect(wrapper.find('[data-note="amend_append_only"]').exists()).toBe(false);

    // …and NOT in the per-card map, which is the half that would have swallowed it.
    const board = wrapper.findComponent(KnowledgeDraftBoard);
    const byCard = board.props('notes') as Record<string, Array<{ code: string }>>;
    expect(Object.keys(byCard)).toEqual(['polityka-zwrotow']);
    expect(byCard['polityka-zwrotow'].map((note) => note.code)).toEqual(['amend_append_only']);

    wrapper.unmount();
  });

  it('says WHAT was missing from the dropped proposal, in the reader’s language', async () => {
    h.route.params = { baseId: 'b1', session: 's1' };
    h.store.session = {
      ...h.session,
      notes: [{ code: 'entry_incomplete', field: 'content', name: 'polityka-zwrotow' }],
    };

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    // `pl` is this spec's locale. The field is an enum on the wire and must never reach the screen
    // raw — "content" in a Polish sentence is the same defect as an untranslated status badge.
    const text = wrapper.find('[data-note="entry_incomplete"]').text();
    expect(text).toContain('treść');
    expect(text).not.toContain('content');

    wrapper.unmount();
  });

  // --- Guarded exit ---------------------------------------------------------

  it('guards leaving while drafts are unreviewed, and lets go once they are not', async () => {
    h.route.params = { baseId: 'b1', session: 's1' };
    h.store.session = h.session;

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    const guard = (globalThis as Record<string, unknown>).__leaveGuard as () => Promise<boolean>;
    expect(typeof guard).toBe('function');

    await guard();
    expect(h.confirm).toHaveBeenCalledWith(
      expect.objectContaining({ message: t('knowledge.compose.leave.message', '', { count: 2 }) }),
    );

    // With NOTHING left to lose the guard must not ask at all.
    h.confirm.mockClear();
    h.store.session = { ...h.session, drafts: [] };
    const clean = mountView();
    await nextTick();
    const cleanGuard = (globalThis as Record<string, unknown>).__leaveGuard as () => Promise<boolean>;
    expect(await cleanGuard()).toBe(true);
    expect(h.confirm).not.toHaveBeenCalled();

    wrapper.unmount();
    clean.unmount();
  });
});
