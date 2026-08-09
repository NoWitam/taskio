// @vitest-environment happy-dom
// runGaps.spec — the two lists that describe what the composer did NOT do.
//
// Both are invisible by nature: an absent proposal looks exactly like a considered decision not to
// make one. That is the reading these sections exist to prevent, and it is the reason the backend
// reports them at all — "an operation that was skipped never goes quiet" is the rule this module
// holds everywhere else.
//
//   unresolved  the model said "I cannot tell who this is". Nothing was proposed for the name.
//               Left unsaid, a reviewer concludes the model judged the person unimportant — the
//               opposite of what it reported.
//   omitted     entries that matched but did not fit the context ceiling. The model never saw
//               them, so silence about them is a fact about the BUDGET, not an opinion.
//
// Tone matters as much as presence: neither is an error, and neither may be dressed as one.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale, translate as t } from '../../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../../__tests__/helpers/dom';
import { COMPOSE_ROUTE } from '../../composeSeed';

const h = vi.hoisted(() => {
  const emptyOps = {
    entities: [],
    wiki_updates: [],
    graph_updates: [],
    unresolved: [] as Array<{ mention: string; note: string | null }>,
    rejected: [],
    warnings: [],
  };

  const session = {
    id: 's1',
    knowledge_base_id: 'b1',
    status: 'ready',
    failure_reason: null,
    source_text: 'Kasia dołączyła do zespołu.',
    prompt_history: [],
    seed_slug: null,
    seed_title: null,
    context_expanded_at: null,
    notes: [] as Array<Record<string, unknown>>,
    graph_ops: emptyOps,
    resolution: {
      entities: [],
      ambiguous: [],
      unresolved: [],
      omitted: [] as string[],
      degraded: [] as string[],
    },
    drafts: [],
    duplicates: {},
    creator: null,
    created_at: null,
    updated_at: null,
  };

  return {
    session,
    router: { push: vi.fn(), replace: vi.fn() },
    toast: { success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() },
    confirm: vi.fn(),
    store: {
      session,
      composeAvailability: { can_compose: true, limits: { prompt_max_chars: 2000 } },
      openBase: { id: 'b1', name: 'Marka', metadata_schema: [] },
      fetchComposeAvailability: vi.fn(),
      fetchDraftSession: vi.fn().mockResolvedValue(session),
      resetComposeSession: vi.fn(),
      fetchDraftRelations: vi.fn().mockResolvedValue({ proposed_relations: [], proposed_entities: [] }),
      startDraftSession: vi.fn(),
      acceptDrafts: vi.fn(),
      rejectDraft: vi.fn(),
      rebaseDraft: vi.fn(),
      expandDraftContext: vi.fn(),
      refineDraftSession: vi.fn(),
    },
  };
});

vi.mock('vue-router', () => ({
  useRouter: () => h.router,
  useRoute: () => ({ params: { baseId: 'b1', session: 's1' }, query: {}, hash: '' }),
  onBeforeRouteLeave: vi.fn(),
}));
vi.mock('../../../../app/composables/useToast', () => ({ useToast: () => h.toast }));
vi.mock('../../../../app/composables/useConfirm', () => ({ useConfirm: () => h.confirm }));
vi.mock('../../../../app/stores/knowledge', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../../../app/stores/knowledge')>();

  return { ...actual, useKnowledgeStore: () => h.store };
});
vi.mock('../../../../app/stores/aiUsage', () => ({
  useAiUsageStore: () => ({ fetchAiUsage: vi.fn() }),
}));

import KnowledgeComposeView from '../../KnowledgeComposeView.vue';

function flush(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, 0));
}

async function mountView() {
  const wrapper = mount(KnowledgeComposeView, {
    attachTo: document.body,
    global: {
      stubs: {
        KnowledgeRefineBar: true,
        KnowledgeComposeSourceForm: true,
        KnowledgeDraftCard: true,
        KnowledgeDraftRelationsPanel: true,
      },
    },
  });
  await flush();
  await nextTick();
  await flush();
  await nextTick();

  return wrapper;
}

/** Press the board's accept button for a given set of draft ids. */
async function acceptDrafts(
  wrapper: Awaited<ReturnType<typeof mountView>>,
  ids: string[],
): Promise<void> {
  wrapper.findComponent({ name: 'KnowledgeDraftBoard' }).vm.$emit('accept-selected', ids);
  for (let i = 0; i < 6; i += 1) {
    await flush();
    await nextTick();
  }
}

/** Press the board's accept button with everything the panel has ticked. */
async function acceptAll(wrapper: Awaited<ReturnType<typeof mountView>>): Promise<void> {
  wrapper.findComponent({ name: 'KnowledgeDraftBoard' }).vm.$emit('accept-selected', []);
  await flush();
  await nextTick();
  await flush();
  await nextTick();
}

describe("names the run could not place", () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('en');
    vi.clearAllMocks();
    h.session.graph_ops.unresolved = [];
    h.session.resolution.omitted = [];
    h.session.notes = [];
    h.store.fetchDraftSession.mockResolvedValue(h.session);
    h.store.fetchDraftRelations.mockResolvedValue({
      proposed_relations: [],
      proposed_entities: [],
    });
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('lists each unrecognised mention with the reason the agent gave', async () => {
    h.session.graph_ops.unresolved = [
      { mention: 'Kasia', note: 'two people with that name' },
      { mention: 'Orion', note: null },
    ];

    const wrapper = await mountView();
    const section = wrapper.find('[data-unresolved]');

    expect(section.exists()).toBe(true);
    expect(wrapper.findAll('[data-unresolved-mention]')).toHaveLength(2);
    expect(section.text()).toContain('Kasia');
    expect(section.text()).toContain('two people with that name');
    wrapper.unmount();
  });

  it('says plainly that NOTHING was proposed for them', async () => {
    // The whole point: without this sentence the absence reads as a judgement about the person.
    h.session.graph_ops.unresolved = [{ mention: 'Kasia', note: null }];

    const wrapper = await mountView();

    expect(wrapper.find('[data-unresolved]').text()).toContain(
      t('knowledge.compose.unresolvedHint'),
    );
    wrapper.unmount();
  });

  it('renders a mention with no note, rather than dropping it', async () => {
    h.session.graph_ops.unresolved = [{ mention: 'Orion', note: null }];

    const wrapper = await mountView();

    expect(wrapper.find('[data-unresolved-mention="Orion"]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('disappears entirely when the run placed everything', async () => {
    const wrapper = await mountView();

    expect(wrapper.find('[data-unresolved]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('offers the way OUT: create an entry from the name', async () => {
    // Reporting a gap without the one action that closes it leaves the reviewer retyping the name
    // somewhere else.
    h.session.graph_ops.unresolved = [{ mention: 'Kasia', note: null }];

    const wrapper = await mountView();
    await wrapper.find('[data-create-from-mention="Kasia"]').trigger('click');

    expect(h.router.push).toHaveBeenCalledWith({
      name: COMPOSE_ROUTE,
      params: { baseId: 'b1' },
      // The name travels BOTH ways: as the seed (slugged server-side) and as the title, so the
      // new entry is born "Kasia" rather than "kasia".
      query: { seed: 'Kasia', seedTitle: 'Kasia' },
    });
    wrapper.unmount();
  });
});

describe('entries the composer never saw', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('en');
    vi.clearAllMocks();
    h.session.graph_ops.unresolved = [];
    h.session.resolution.omitted = [];
    h.session.notes = [];
    h.store.fetchDraftSession.mockResolvedValue(h.session);
    h.store.fetchDraftRelations.mockResolvedValue({
      proposed_relations: [],
      proposed_entities: [],
    });
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('names every omitted entry', async () => {
    h.session.resolution.omitted = ['Polityka zwrotów', 'Cennik'];

    const wrapper = await mountView();
    const section = wrapper.find('[data-omitted]');

    expect(section.exists()).toBe(true);
    expect(section.text()).toContain('Polityka zwrotów');
    expect(section.text()).toContain('Cennik');
    wrapper.unmount();
  });

  it('explains that silence about them is not the agent’s opinion', async () => {
    // "The agent proposed nothing about our refund policy" and "the agent was never shown our
    // refund policy" look identical on the board; only one is worth acting on.
    h.session.resolution.omitted = ['Polityka zwrotów'];

    const wrapper = await mountView();

    expect(wrapper.find('[data-omitted]').text()).toContain(t('knowledge.compose.omittedHint'));
    wrapper.unmount();
  });

  it('disappears entirely when everything fit', async () => {
    const wrapper = await mountView();

    expect(wrapper.find('[data-omitted]').exists()).toBe(false);
    wrapper.unmount();
  });
});

describe('the run summary as a whole', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('en');
    vi.clearAllMocks();
    h.session.graph_ops.unresolved = [];
    h.session.resolution.omitted = [];
    h.session.notes = [];
    h.store.fetchDraftSession.mockResolvedValue(h.session);
    h.store.fetchDraftRelations.mockResolvedValue({
      proposed_relations: [],
      proposed_entities: [],
    });
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('appears when there are ONLY gaps and no run notes', async () => {
    // The panel used to be gated on `notes.length`; a run whose only news is an unplaced name
    // would then have said nothing at all.
    h.session.graph_ops.unresolved = [{ mention: 'Kasia', note: null }];

    const wrapper = await mountView();

    expect(wrapper.find('[data-run-notes]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('stays away when the run has nothing to report', async () => {
    const wrapper = await mountView();

    expect(wrapper.find('[data-run-notes]').exists()).toBe(false);
    wrapper.unmount();
  });
});

// ------------------------------------------------------------------------------------------------
// THE STALE PREVIEW — reachable, and the earlier check could not see it.
//
// The scenario is two tabs (or two reviewers) on one session, which this module already assumes
// elsewhere: `target_revision_stale` and the whole conflict path exist because somebody else can be
// editing while you read. Tab A holds a preview; tab B refines; the run is RENUMBERED; tab A presses
// accept with keys that are perfectly valid locally and unknown to the server.
//
// The first version of the discriminator compared the sent keys against the preview WE were
// holding — but we only ever send keys from that preview, so the answer was always "all known" and
// the stale branch could not fire. It was not a dead banner; it was a dead TEST for a live branch,
// which is worse: the banner looked protected and was not.
//
// So the comparison is made against a FRESH read of the preview. One extra request, only on a path
// where a write was already refused, and the reader needs that fresh preview anyway.
describe('a preview that went stale under an open review', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('en');
    vi.clearAllMocks();
    h.session.graph_ops.unresolved = [];
    h.session.resolution.omitted = [];
    h.session.notes = [];
    h.store.fetchDraftSession.mockResolvedValue(h.session);
    // The bulk accept confirms first; without this the dialog resolves undefined and nothing runs.
    h.confirm.mockResolvedValue(true);
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  /** The 422 the server answers when a key names an operation it no longer has. */
  const unknownKey = {
    response: {
      status: 422,
      data: { errors: { graph_op_keys: ['This proposal has changed since you opened it.'] } },
    },
  };

  /** The 422 for a half-selected pair — same field, different meaning. */
  const splitPair = {
    response: {
      status: 422,
      data: {
        errors: { graph_op_keys: ['These describe one change (graph:0 + graph:1).'] },
      },
    },
  };

  function proposal(key: string) {
    return {
      kind: 'relation' as const,
      id: null,
      key,
      pair_with: null,
      replaces: null,
      op: 'create' as const,
      from: 'E1',
      to: 'E2',
      relation: null,
      from_title: 'Anna',
      to_title: 'Acme',
      relation_type: 'member_of' as const,
      description: null,
      properties: {},
      valid_from: null,
      valid_to: null,
      depends_on_draft: [],
    };
  }

  it('offers a RELOAD when the server no longer has the keys we held', async () => {
    // Tab A loaded these…
    h.store.fetchDraftRelations.mockResolvedValueOnce({
      proposed_relations: [proposal('graph:0'), proposal('graph:1')],
      proposed_entities: [],
    });
    h.store.acceptDrafts.mockRejectedValue(unknownKey);
    // …and the refetch on the error path finds tab B's refinement: renumbered, one operation.
    h.store.fetchDraftRelations.mockResolvedValueOnce({
      proposed_relations: [proposal('graph:7')],
      proposed_entities: [],
    });

    const wrapper = await mountView();
    await acceptAll(wrapper);

    // DIAG
    expect(wrapper.findComponent({ name: 'KnowledgeGraphUpdatesPanel' }).props('stale')).toBe(true);
    wrapper.unmount();
  });

  it('does NOT call it stale when the keys are still there — that is a different refusal', async () => {
    h.store.fetchDraftRelations.mockResolvedValue({
      proposed_relations: [proposal('graph:0'), proposal('graph:1')],
      proposed_entities: [],
    });
    h.store.acceptDrafts.mockRejectedValue(splitPair);

    const wrapper = await mountView();
    await acceptAll(wrapper);

    const panel = wrapper.findComponent({ name: 'KnowledgeGraphUpdatesPanel' });
    expect(panel.props('stale')).toBe(false);
    // The server's own sentence, which names WHICH operations belong together.
    expect(panel.props('error')).toContain('graph:0 + graph:1');
    wrapper.unmount();
  });

  it('claims nothing when the refetch itself fails', async () => {
    h.store.fetchDraftRelations.mockResolvedValueOnce({
      proposed_relations: [proposal('graph:0')],
      proposed_entities: [],
    });
    h.store.acceptDrafts.mockRejectedValue(unknownKey);
    h.store.fetchDraftRelations.mockRejectedValueOnce(new Error('offline'));

    const wrapper = await mountView();
    await acceptAll(wrapper);

    // Telling somebody to reload a thing we could not read either is a worse answer than the
    // server's own message.
    expect(wrapper.findComponent({ name: 'KnowledgeGraphUpdatesPanel' }).props('stale')).toBe(false);
    wrapper.unmount();
  });
});

// ------------------------------------------------------------------------------------------------
// THE RESPONSE NO LONGER CARRIES `updated`, AND THE VIEW MUST NOT NOTICE.
//
// The field was always empty — the applier set a key no branch ever wrote — and filling it would
// have duplicated `accepted`: publishing an amendment returns the LIVE TARGET and destroys the
// shadow, so the row that changed is already in `accepted` for every amendment. The concept moved
// too: the graph half no longer writes entry content at all, now that a change to an existing
// entry becomes a reviewable shadow draft.
//
// What survives is the DISTINCTION the field was standing in for — created versus changed — and it
// is derived from the draft that was sent, not from the response. That is the only place it can be
// derived: an amendment's accepted row carries the TARGET's id, so it cannot be matched back to
// the draft that produced it.
describe('an accept response with no `updated` field', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('en');
    vi.clearAllMocks();
    h.session.graph_ops.unresolved = [];
    h.session.resolution.omitted = [];
    h.session.notes = [];
    h.store.fetchDraftSession.mockResolvedValue(h.session);
    h.store.fetchDraftRelations.mockResolvedValue({
      proposed_relations: [],
      proposed_entities: [],
    });
    h.confirm.mockResolvedValue(true);
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  /** The shape the server sends now: four keys, no `updated`. */
  function accepted(over: Record<string, unknown> = {}) {
    return { accepted: [{ id: 'e1' }], relations: [], conflicts: [], skipped: [], ...over };
  }

  it('publishes a NEW draft without throwing on the missing key', async () => {
    h.session.drafts = [{ id: 'd1', title: 'Nowy', targets_entry: null }];
    h.store.acceptDrafts.mockResolvedValue(accepted());

    const wrapper = await mountView();
    await acceptDrafts(wrapper, ['d1']);

    // A bare `result.updated.length` would have thrown here and left the button looking inert.
    expect(h.toast.danger).not.toHaveBeenCalled();
    expect(h.toast.success).toHaveBeenCalledWith(t('knowledge.compose.acceptedToast'));
    wrapper.unmount();
  });

  it('still tells an AMENDMENT apart from a new entry', async () => {
    // Told apart by the card being a SHADOW, which is knowledge the client already had.
    h.session.drafts = [{ id: 'd1', title: 'Cennik', targets_entry: { id: 'e1', slug: 'cennik' } }];
    h.store.acceptDrafts.mockResolvedValue(accepted());

    const wrapper = await mountView();
    await acceptDrafts(wrapper, ['d1']);

    expect(h.toast.success).toHaveBeenCalledWith(t('knowledge.compose.acceptedAmendmentToast'));
    wrapper.unmount();
  });

  it('reports a mixed batch as ordinary acceptance rather than claiming they were all edits', async () => {
    h.session.drafts = [
      { id: 'd1', title: 'Nowy', targets_entry: null },
      { id: 'd2', title: 'Cennik', targets_entry: { id: 'e1', slug: 'cennik' } },
    ];
    h.store.acceptDrafts.mockResolvedValue(accepted());

    const wrapper = await mountView();
    await acceptDrafts(wrapper, ['d1', 'd2']);

    expect(h.toast.success).toHaveBeenCalledWith(t('knowledge.compose.acceptedToast'));
    wrapper.unmount();
  });

  it('survives a response missing `skipped` and `relations` too', async () => {
    // Defensive for the same reason: a field that goes away must degrade, not explode.
    h.session.drafts = [{ id: 'd1', title: 'Nowy', targets_entry: null }];
    h.store.acceptDrafts.mockResolvedValue({ accepted: [{ id: 'e1' }], conflicts: [] });

    const wrapper = await mountView();
    await acceptDrafts(wrapper, ['d1']);

    expect(h.toast.danger).not.toHaveBeenCalled();
    wrapper.unmount();
  });
});
