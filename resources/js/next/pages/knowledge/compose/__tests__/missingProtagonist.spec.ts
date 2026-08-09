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

describe('somebody the material keeps talking about has no entry', () => {
  // THE DOMINANT DEFECT IN MEASUREMENT, and it does not look like one defect from below.
  //
  // A source about a woman it only ever called "influencerka" produced seven entries — every city
  // she visited, the man she met, the contest she ran — and none for her. Everything downstream
  // then broke in ways that read as separate problems: the cities became the travellers, three
  // relations were refused as place-to-place, the incident had no subject to hang an edge on, and
  // `[[influencerka]]` went red. One cause, four symptoms, none pointing back at it.
  beforeEach(() => {
    installBrowserMocks();
    setLocale('en');
    vi.clearAllMocks();
    h.session.graph_ops.unresolved = [];
    h.session.resolution.omitted = [];
    h.session.notes = [];
    h.session.facts = [];
    h.session.drafts = [];
    h.store.fetchDraftSession.mockResolvedValue(h.session);
    h.store.fetchDraftRelations.mockResolvedValue({ proposed_relations: [], proposed_entities: [] });
    h.confirm.mockResolvedValue(true);
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('is called out, loudly, by name', async () => {
    h.session.notes = [
      { code: 'protagonist_without_entry', title: 'Influencerka', description: 'Influencerka' },
    ];

    const wrapper = await mountView();
    const alert = wrapper.find('[data-missing-protagonist="Influencerka"]');

    expect(alert.exists()).toBe(true);
    expect(alert.text()).toContain('Influencerka');
    wrapper.unmount();
  });

  it('reads as an OBSERVATION to check, not as a verdict', async () => {
    // The server matches slugified titles, so an entry covering the subject under another name
    // trips it. "No entry carries that name" is true either way; "an entry is missing" would not be.
    h.session.notes = [
      { code: 'protagonist_without_entry', title: 'Influencerka', description: 'Influencerka' },
    ];

    const wrapper = await mountView();
    const text = wrapper.find('[data-missing-protagonist="Influencerka"]').text();

    expect(text).toContain(
      t('knowledge.compose.facts.protagonistTitle', '', { title: 'Influencerka' }),
    );
    expect(text).toContain(t('knowledge.compose.facts.protagonistHint'));
    wrapper.unmount();
  });

  it('calls out each of them when the run names several', async () => {
    h.session.notes = [
      { code: 'protagonist_without_entry', title: 'Influencerka', description: '' },
      { code: 'protagonist_without_entry', title: 'Organizator', description: '' },
    ];

    const wrapper = await mountView();

    expect(wrapper.findAll('[data-missing-protagonist]')).toHaveLength(2);
    wrapper.unmount();
  });

  it('is NOT repeated as a bare code in the run-notes list', async () => {
    // One situation described twice makes the reviewer reconcile two accounts of it.
    h.session.notes = [
      { code: 'protagonist_without_entry', title: 'Influencerka', description: '' },
    ];

    const wrapper = await mountView();

    expect(wrapper.find('[data-note="protagonist_without_entry"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('leaves nothing protruding on a run that is fine', async () => {
    const wrapper = await mountView();

    expect(wrapper.find('[data-missing-protagonist]').exists()).toBe(false);
    wrapper.unmount();
  });
});
