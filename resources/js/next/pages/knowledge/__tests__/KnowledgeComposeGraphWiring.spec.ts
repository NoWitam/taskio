// @vitest-environment happy-dom
// KnowledgeComposeGraphWiring.spec — the VIEW's half of the graph review (G8).
//
// ------------------------------------------------------------------------------------------------
// WHY THIS FILE EXISTS NEXT TO THE PANEL'S OWN SPECS
//
// `graphOpSelection.spec` and `graphOpPairs.spec` mount KnowledgeGraphUpdatesPanel with hand-written
// props and read `opKeys` off the component instance. They prove the panel computes the right answer.
// They CANNOT prove that anybody asks it — the panel is reached through a template ref, its proposals
// arrive from a store action nobody in those specs calls, and the three-valued contract only reaches
// the wire if `publish()` branches on arity correctly.
//
// Every defect that has actually shipped in this area lived in exactly that gap: a ref that was never
// bound, a fetch whose result was assigned to the wrong ref, a 422 branch that referenced a variable
// out of scope. So this file drives the WHOLE view — real panel, real board, real payload — and
// asserts only what crosses a seam:
//
//   • the relations endpoint's answer reaches the panel;
//   • a ticked checkbox becomes a key in the accept call, and an untouched one does not;
//   • a replacement is ONE control that commits BOTH of its keys through the whole view;
//   • a server refusal about the SELECTION is shown as such, and never stamped on the drafts;
//   • the reload the panel offers really reloads the session.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale } from '../../../app/i18n';
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
    index: {
      status: null,
      chunks_count: null,
      indexed_chunks_count: null,
      needs_indexing: false,
      can_retry: false,
    },
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

  /**
   * A session shaped like the SERVER's, including the three keys the older fixture omits —
   * `graph_ops`, `resolution` and `notes`. The view reads all three with optional chaining, so a
   * fixture without them silently exercises the null path of every graph assertion in this file.
   */
  const session = {
    id: 's1',
    knowledge_base_id: 'b1',
    status: 'ready' as string,
    failure_reason: null as string | null,
    source_text: 'Łukasz przeszedł do Kwadratury.',
    prompt_history: [] as string[],
    seed_slug: null,
    seed_title: null,
    drafts: [draft('d1', 'Polityka zwrotow')],
    duplicates: {} as Record<string, unknown>,
    notes: [] as unknown[],
    resolution: { entities: [], ambiguous: [], unresolved: [], degraded: [] },
    graph_ops: {
      entities: [] as unknown[],
      wiki_updates: [] as unknown[],
      graph_updates: [] as unknown[],
      unresolved: [] as unknown[],
      rejected: [] as unknown[],
      warnings: [] as unknown[],
    },
    creator: null,
    created_at: null,
    updated_at: '2026-08-05T10:00:00Z',
  };

  return {
    availability,
    session,
    draft,
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
      rebaseDraft: vi.fn(),
      expandDraftContext: vi.fn(),
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
    (globalThis as Record<string, unknown>).__leaveGuard = guard;
  },
}));
vi.mock('../../../app/stores/knowledge', () => ({ useKnowledgeStore: () => h.store }));
vi.mock('../../../app/stores/aiUsage', () => ({ useAiUsageStore: () => h.aiUsage }));
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => h.toast }));
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => h.confirm }));
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
import KnowledgeGraphUpdatesPanel from '../compose/KnowledgeGraphUpdatesPanel.vue';

/** One proposed relation, in the shape the relations endpoint really publishes. */
function proposal(over: Record<string, unknown> = {}) {
  return {
    kind: 'relation',
    id: null,
    key: 'graph:0',
    pair_with: null,
    replaces: null,
    op: 'create',
    from: 'E1',
    to: 'E2',
    relation: null,
    from_title: 'Łukasz Barszcz',
    to_title: 'Acme',
    relation_type: 'member_of',
    description: null,
    properties: {},
    valid_from: null,
    valid_to: null,
    depends_on_draft: [] as string[],
    ...over,
  };
}

/** The two halves of a replacement, each naming the other — what `pair_with` is for. */
const REPLACEMENT = [
  proposal({ key: 'graph:0', op: 'end', pair_with: 'graph:1', relation: 'R1', to_title: 'Acme', valid_to: '2026-07-01' }),
  proposal({ key: 'graph:1', op: 'create', pair_with: 'graph:0', replaces: 'R1', to_title: 'Kwadratura' }),
];

async function mountBoard(relations: Record<string, unknown>[] = [proposal()], entities: unknown[] = []) {
  h.route.params = { baseId: 'b1', session: 's1' };
  h.store.session = h.session;
  h.store.fetchDraftRelations.mockResolvedValue({
    proposed_relations: relations,
    proposed_entities: entities,
  });

  const wrapper = mount(KnowledgeComposeView, { attachTo: document.body });

  // Mount, the availability fetch, the session fetch, and the relations watcher — each a tick.
  for (let i = 0; i < 5; i++) await nextTick();

  return wrapper;
}

/** Tick the board's checkbox for a draft, then press the accept button. */
async function acceptFirstDraft(wrapper: Awaited<ReturnType<typeof mountBoard>>) {
  const checkboxes = wrapper.findAll('input[type="checkbox"]');
  // The first is the board's "select all"; the second is the only draft's own box.
  await checkboxes[1].setValue(true);
  await nextTick();

  await wrapper.find('[data-accept-selected]').trigger('click');
  for (let i = 0; i < 5; i++) await nextTick();
}

describe('KnowledgeComposeView — the graph half of the review', () => {
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
    h.store.acceptDrafts.mockResolvedValue({ accepted: [{ id: 'd1' }], conflicts: [], relations: [], skipped: [] });
  });

  afterEach(() => {
    restoreBrowserMocks();
    document.body.innerHTML = '';
  });

  // --- the fetch reaches the panel ------------------------------------------------

  it('fetches the relation proposals for the open session and renders them', async () => {
    const wrapper = await mountBoard([
      proposal({ key: 'graph:0', to_title: 'Acme' }),
      proposal({ key: 'graph:1', to_title: 'Orion', relation_type: 'works_on' }),
    ]);

    expect(h.store.fetchDraftRelations).toHaveBeenCalledWith('s1');
    // Read through the DOM rather than through the child's props: a panel that received the list and
    // did not render it is the same bug to a reviewer as one that never received it.
    expect(wrapper.findAll('[data-select-op]')).toHaveLength(2);
    expect(wrapper.text()).toContain('Orion');
    wrapper.unmount();
  });

  it('survives the relations endpoint failing without taking the drafts down with it', async () => {
    h.route.params = { baseId: 'b1', session: 's1' };
    h.store.session = h.session;
    h.store.fetchDraftRelations.mockRejectedValue(new Error('boom'));

    const wrapper = mount(KnowledgeComposeView, { attachTo: document.body });
    for (let i = 0; i < 5; i++) await nextTick();

    // The board is the thing being reviewed; the graph panel is an explanation of what else happens.
    expect(wrapper.findAll('[data-draft-id]')).toHaveLength(1);
    expect(wrapper.findAll('[data-select-op]')).toHaveLength(0);
    wrapper.unmount();
  });

  // --- the checkboxes become the wire payload ---------------------------------------

  it('sends exactly the ticked operation keys as `graph_op_keys`', async () => {
    const wrapper = await mountBoard([
      proposal({ key: 'graph:0', to_title: 'Acme' }),
      proposal({ key: 'graph:1', to_title: 'Orion', relation_type: 'works_on' }),
    ]);

    // Untick the second — the panel starts with everything selected.
    await wrapper.find('[data-select-op="graph:1"] input').setValue(false);
    await nextTick();

    await acceptFirstDraft(wrapper);

    // TWO CALLS, since the graph half became one call of its own: the draft publishes alone, then
    // the operations go together. The keys are still exactly the ticked ones — which is what this
    // test is about — they simply no longer ride along with a draft that has nothing to do with them.
    expect(h.store.acceptDrafts).toHaveBeenCalledWith('s1', ['d1'], 'approved', []);
    expect(h.store.acceptDrafts).toHaveBeenLastCalledWith('s1', [], 'approved', ['graph:0']);
    wrapper.unmount();
  });

  /**
   * REFUSING EVERY RELATION IS A DIFFERENT ACT FROM HAVING NOTHING TO SAY.
   *
   * Both look like "no keys" from the outside, and the server reads them oppositely: an empty array
   * applies nothing, an absent field applies everything. This is the one place the distinction can be
   * lost, because it is the only place the array is built.
   */
  it('sends an EMPTY list when the reviewer unticks every operation', async () => {
    const wrapper = await mountBoard([proposal({ key: 'graph:0' })]);

    await wrapper.find('[data-select-op="graph:0"] input').setValue(false);
    await nextTick();

    await acceptFirstDraft(wrapper);

    expect(h.store.acceptDrafts).toHaveBeenCalledWith('s1', ['d1'], 'approved', []);
    wrapper.unmount();
  });

  it('OMITS the field entirely when the run proposed no operations at all', async () => {
    const wrapper = await mountBoard([]);

    await acceptFirstDraft(wrapper);

    // Three arguments, not four with an `undefined` — `undefined` handed to a 4-argument call still
    // occupies the slot, and a client that serialises it would send `graph_op_keys: null`.
    expect(h.store.acceptDrafts).toHaveBeenCalledWith('s1', ['d1'], 'approved');
    wrapper.unmount();
  });

  // --- a replacement is ONE control ---------------------------------------------------

  /**
   * The server refuses a selection that splits a pair, so the interface must not be able to express
   * one. Pinned through the VIEW: the panel's own spec proves `opKeys` merges them, this proves the
   * merged answer is what reaches the wire.
   */
  it('treats a replacement as one control that commits BOTH of its keys', async () => {
    const wrapper = await mountBoard(REPLACEMENT);

    // ONE checkbox for the two operations, and it says so.
    const controls = wrapper.findAll('[data-select-op]');
    expect(controls).toHaveLength(1);
    expect(controls[0].attributes('data-commits')).toBe('graph:0 graph:1');

    await acceptFirstDraft(wrapper);

    // The LAST call is the graph half — the draft published on its own first.
    const call = h.store.acceptDrafts.mock.calls.at(-1) as unknown[];
    expect(call[3]).toEqual(expect.arrayContaining(['graph:0', 'graph:1']));
    expect(call[3]).toHaveLength(2);
    wrapper.unmount();
  });

  it('sends NEITHER half when the replacement is unticked', async () => {
    const wrapper = await mountBoard(REPLACEMENT);

    await wrapper.find('[data-select-op] input').setValue(false);
    await nextTick();

    await acceptFirstDraft(wrapper);

    // No graph call at all, and the draft call says "none" — neither half of the pair travels.
    expect(h.store.acceptDrafts).toHaveBeenCalledTimes(1);
    expect(h.store.acceptDrafts).toHaveBeenCalledWith('s1', ['d1'], 'approved', []);
    wrapper.unmount();
  });

  // --- the server's refusal about the SELECTION ----------------------------------------

  /**
   * A 422 ABOUT THE SELECTION IS NOT A FAILURE OF THE DRAFTS.
   *
   * The server refuses the whole request rather than applying what it can, so nothing was written and
   * the drafts are perfectly fine. Reporting it on the cards would tell the reviewer that five good
   * proposals failed, when the truth is that two checkboxes disagree.
   *
   * This is also the test that catches the branch being unreachable: an exception thrown inside the
   * `catch` (an out-of-scope variable, a renamed helper) leaves the screen with NO message at all,
   * which reads as a button that does nothing.
   */
  it('shows a selection refusal in the server’s own words, and not on the draft cards', async () => {
    const message = 'Operacje graph:0 + graph:1 są nierozdzielne.';
    h.store.acceptDrafts.mockRejectedValue({
      response: { status: 422, data: { errors: { graph_op_keys: [message] } } },
    });

    const wrapper = await mountBoard([proposal({ key: 'graph:0' })]);

    await acceptFirstDraft(wrapper);

    expect(wrapper.find('[data-selection-error]').exists()).toBe(true);
    expect(wrapper.find('[data-selection-error]').text()).toContain(message);
    // ...and the drafts were not blamed for it.
    expect(wrapper.find('[data-draft-error]').exists()).toBe(false);
    wrapper.unmount();
  });

  /** An ordinary failure still lands on the card it belongs to — the branch above is not a catch-all. */
  it('still reports a non-selection failure against the drafts', async () => {
    h.store.acceptDrafts.mockRejectedValue({
      response: { status: 422, data: { errors: { entry_ids: ['Nieprawidłowe id.'] } } },
    });

    const wrapper = await mountBoard([proposal({ key: 'graph:0' })]);

    await acceptFirstDraft(wrapper);

    expect(wrapper.find('[data-selection-error]').exists()).toBe(false);
    expect(wrapper.find('[data-draft-error]').exists()).toBe(true);
    expect(wrapper.find('[data-draft-error]').text()).toContain('Nieprawidłowe id.');
    wrapper.unmount();
  });

  // --- the reload the panel offers ------------------------------------------------------

  /**
   * The stale banner's only value is its button, and the button's only value is that it re-reads the
   * session. Driven by emitting from the panel, which is exactly what the button does — the banner
   * itself is the panel's own test.
   */
  it('re-reads the session when the panel asks to reload', async () => {
    const wrapper = await mountBoard([proposal({ key: 'graph:0' })]);

    h.store.fetchDraftSession.mockClear();

    wrapper.findComponent(KnowledgeGraphUpdatesPanel).vm.$emit('reload');
    for (let i = 0; i < 4; i++) await nextTick();

    // The session itself is re-read; the proposals follow it, because the relations fetch watches
    // `session.updated_at` and a renumbering run is exactly what moves that.
    expect(h.store.fetchDraftSession).toHaveBeenCalledWith('s1');
    wrapper.unmount();
  });
});
