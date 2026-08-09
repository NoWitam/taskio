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

describe('relations must never be lost in silence', () => {
  // THE OWNER'S BUG, twice over.
  //
  // Accepting drafts ONE CARD AT A TIME never ran the graph half — only the bulk button reached
  // it — so a reviewer published every entry and lost every relation, with the relations still
  // ticked on screen and nothing saying they had not been written. And once the board emptied,
  // `acceptedHandles` answered "still blocked" for every published entity, so the proposals became
  // permanently unselectable: correct, waiting, and unreachable.
  const proposal = (key: string, over: Record<string, unknown> = {}) => ({
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
    ...over,
  });

  const entity = { id: null, handle: 'E1', title: 'Anna', slug: null, entry_type: 'person' as const, is_draft: true as const };

  beforeEach(() => {
    installBrowserMocks();
    setLocale('en');
    vi.clearAllMocks();
    h.session.graph_ops.unresolved = [];
    h.session.resolution.omitted = [];
    h.session.notes = [];
    h.session.drafts = [];
    h.store.fetchDraftSession.mockResolvedValue(h.session);
    h.store.acceptDrafts.mockResolvedValue({ accepted: [], relations: [], conflicts: [], skipped: [] });
    h.confirm.mockResolvedValue(true);
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('OFFERS A WAY to write the relations, on a session whose entries are all published', async () => {
    // The owner's exact state: `ready`, every entry published, board empty, four operations that
    // never ran. Before this, the panel showed them ticked and there was no control anywhere.
    h.store.fetchDraftRelations.mockResolvedValue({
      proposed_relations: [proposal('graph:0'), proposal('graph:1', { depends_on_draft: ['E1'] })],
      proposed_entities: [entity],
    });

    const wrapper = await mountView();

    const bar = wrapper.find('[data-apply-bar]');
    expect(bar.exists(), 'the pending-relations bar must be offered').toBe(true);
    expect(bar.text()).toContain(t('knowledge.relations.pendingAction'));
    wrapper.unmount();
  });

  it('does not treat a PUBLISHED entity as a draft that is still being waited for', async () => {
    // The second half: with the board empty, `depends_on_draft` named an entity whose draft was
    // gone, and that used to read as "still blocked" rather than "already there".
    h.store.fetchDraftRelations.mockResolvedValue({
      proposed_relations: [proposal('graph:0', { depends_on_draft: ['E1'] })],
      proposed_entities: [entity],
    });

    const wrapper = await mountView();

    expect(wrapper.findComponent({ name: 'KnowledgeGraphUpdatesPanel' }).vm.selectedKeys).toEqual([
      'graph:0',
    ]);
    wrapper.unmount();
  });

  it('writes them in ONE call with no entries, exactly as the bulk path does', async () => {
    h.store.fetchDraftRelations.mockResolvedValue({
      proposed_relations: [proposal('graph:0'), proposal('graph:1')],
      proposed_entities: [],
    });

    const wrapper = await mountView();
    await wrapper.find('[data-apply-graph]').trigger('click');
    for (let i = 0; i < 6; i += 1) {
      await flush();
      await nextTick();
    }

    // The graph proposal is one transaction on the server; splitting it across card acceptances is
    // what the previous round removed, so the fix must not reintroduce it.
    expect(h.store.acceptDrafts).toHaveBeenCalledWith('s1', [], 'approved', ['graph:0', 'graph:1']);
    wrapper.unmount();
  });

  it('stands the bar down once they are written', async () => {
    h.store.fetchDraftRelations.mockResolvedValue({
      proposed_relations: [proposal('graph:0')],
      proposed_entities: [],
    });

    const wrapper = await mountView();
    expect(wrapper.find('[data-apply-bar]').exists()).toBe(true);

    await wrapper.find('[data-apply-graph]').trigger('click');
    for (let i = 0; i < 6; i += 1) {
      await flush();
      await nextTick();
    }

    expect(wrapper.find('[data-apply-bar]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('shows no bar when the run proposed no relations at all', async () => {
    h.store.fetchDraftRelations.mockResolvedValue({ proposed_relations: [], proposed_entities: [] });

    const wrapper = await mountView();

    expect(wrapper.find('[data-apply-bar]').exists()).toBe(false);
    wrapper.unmount();
  });
});
