// @vitest-environment happy-dom
// composeAmendment.spec — the SHADOW surface (B15b).
//
// An amendment is the one draft kind that can fail for a reason the reviewer did not cause: the
// entry it rewrites moved underneath it. Everything here is about making that visible EARLY and
// recoverable HONESTLY:
//   • the stale badge shows BEFORE the accept button is pressed (finding out afterwards is one
//     disappointment too late);
//   • a conflict offers exactly two routes, and each states its PRICE — rebase is free, revising
//     spends;
//   • a rebase must not call anything that costs money (pinned by asserting no AI-spending store
//     action is touched);
//   • partial acceptance is reported as the partial success it is.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale, translate } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const h = vi.hoisted(() => {
  const target = {
    id: 'e-target',
    title: 'Polityka zwrotów',
    slug: 'polityka-zwrotow',
    current_revision_id: 'r-new',
  };

  const shadow = (overrides: Record<string, unknown> = {}) => ({
    id: 'd-shadow',
    knowledge_base_id: 'b1',
    // The SYNTHETIC title the agent produced — the card must NOT show this one.
    title: 'Zmiana polityki',
    slug: 'zmiana-polityki',
    content: 'Nowa treść zwrotów.',
    metadata: {},
    status: 'draft',
    stale_at: null,
    is_stale: false,
    position: 0,
    current_revision_id: 'r-draft',
    index: { status: null, chunks_count: null, indexed_chunks_count: null, needs_indexing: false, can_retry: false },
    draft_session_id: 's1',
    targets_entry: target,
    target_revision_id: 'r-old',
    target_revision_stale: false,
    creator: null,
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    can_be_purged: true,
    created_at: null,
    updated_at: null,
    deleted_at: null,
    ...overrides,
  });

  const session = (drafts: unknown[]) => ({
    id: 's1',
    knowledge_base_id: 'b1',
    status: 'ready',
    failure_reason: null,
    source_text: 'raw',
    prompt_history: [],
    seed_slug: null,
    seed_title: null,
    context_expanded_at: null,
    drafts,
    duplicates: {},
    creator: null,
    created_at: null,
    updated_at: null,
  });

  return {
    target,
    shadow,
    session,
    router: { push: vi.fn(), replace: vi.fn() },
    route: { params: { baseId: 'b1', session: 's1' }, query: {} as Record<string, string> },
    toast: { success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() },
    confirm: vi.fn(),
    aiUsage: { summary: null, fetchAiUsage: vi.fn() },
    store: {
      openBase: { id: 'b1', name: 'Marka', entries_count: 3, metadata_schema: [] },
      composeAvailability: {
        can_compose: true,
        reason: null,
        budget: { cost_used: 1, cost_cap: 10, cost_remaining: 9, warn_reached: false, blocked: false, period: null },
        limits: { source_max_chars: 20000, prompt_max_chars: 2000, max_entries_per_session: 8 },
      },
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
      // The two AI-SPENDING actions. A rebase must touch NEITHER.
      refineDraftSession: vi.fn(),
      expandDraftContext: vi.fn(),
      acceptDrafts: vi.fn(),
      rejectDraft: vi.fn(),
      rebaseDraft: vi.fn(),
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
  onBeforeRouteLeave: () => {},
}));
vi.mock('../../../app/stores/knowledge', () => ({ useKnowledgeStore: () => h.store }));
vi.mock('../../../app/stores/aiUsage', () => ({ useAiUsageStore: () => h.aiUsage }));
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => h.toast }));
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => h.confirm }));
vi.mock('../compose/useComposeSettle', () => ({
  useComposeSettle: () => ({ waitForSettle: vi.fn().mockResolvedValue({ settled: true }), dispose: vi.fn() }),
}));

import KnowledgeComposeView from '../KnowledgeComposeView.vue';

const t = translate;

function mountView() {
  return mount(KnowledgeComposeView, { attachTo: document.body });
}

describe('the shadow (amendment) surface', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('pl');
    vi.clearAllMocks();
    h.confirm.mockResolvedValue(true);
    h.route.params = { baseId: 'b1', session: 's1' };
    h.store.session = h.session([h.shadow()]);
    h.store.fetchDraftSession.mockResolvedValue(h.store.session);
    h.store.fetchDraftDiff.mockResolvedValue({
      baseline: 'target',
      target_revision_stale: false,
      has_baseline: true,
      from: { revision_id: 'r-old', title: 'Polityka zwrotów', content: 'Stara treść.', created_at: null },
      to: { revision_id: 'r-draft', title: 'Polityka zwrotów', content: 'Nowa treść zwrotów.', created_at: null },
    });
    // A NON-EMPTY preview: the panel's empty state has no actions, so an unmocked fetch would hide
    // the very button these tests are about.
    h.store.fetchDraftRelations.mockResolvedValue({
      center: null,
      nodes: [
        {
          id: 'e-target',
          slug: 'polityka-zwrotow',
          title: 'Polityka zwrotów',
          status: 'approved',
          is_stale: false,
          degree: 0,
          distance: null,
          is_draft: false,
          amended_by: [{ draft_id: 'd-shadow' }],
        },
      ],
      edges: [],
      ghosts: [],
      truncated: { hidden_nodes: 0, hidden_edges: 0 },
      duplicates: {},
      vector_skipped: null,
    });
  });

  afterEach(() => {
    restoreBrowserMocks();
    document.body.innerHTML = '';
  });

  // --- The card ------------------------------------------------------------

  it('shows the TARGET’s title and slug, never the agent’s synthetic one', async () => {
    const wrapper = mountView();
    await nextTick();
    await nextTick();

    const card = wrapper.find('[data-draft-id="d-shadow"]');
    expect(card.find('h3').text()).toBe('Polityka zwrotów');
    expect(card.text()).toContain('polityka-zwrotow');
    // The reviewer judges "what will exist", so the invented name must not appear as the heading.
    expect(card.find('h3').text()).not.toBe('Zmiana polityki');
    wrapper.unmount();
  });

  it('names what it amends', async () => {
    const wrapper = mountView();
    await nextTick();
    await nextTick();

    expect(wrapper.find('[data-shadow-badge]').text()).toContain('Polityka zwrotów');
    wrapper.unmount();
  });

  // --- N9: the stale badge lands BEFORE the click --------------------------

  it('warns that the baseline is out of date BEFORE anything is accepted', async () => {
    h.store.session = h.session([h.shadow({ target_revision_stale: true })]);

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    // Present on first render — not after an acceptance was refused.
    expect(wrapper.find('[data-stale-baseline]').exists()).toBe(true);
    expect(h.store.acceptDrafts).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  it('does not warn when the target has not moved', async () => {
    const wrapper = mountView();
    await nextTick();
    await nextTick();

    expect(wrapper.find('[data-stale-baseline]').exists()).toBe(false);
    wrapper.unmount();
  });

  // --- The third baseline ---------------------------------------------------

  it('opens a shadow’s diff on the STORED version — the question a reviewer is asking', async () => {
    const wrapper = mountView();
    await nextTick();
    await nextTick();

    await wrapper.find('[data-draft-id="d-shadow"]').findAll('button')
      .find((b) => b.text() === t('knowledge.compose.diff'))!
      .trigger('click');
    await nextTick();
    await nextTick();

    expect(h.store.fetchDraftDiff).toHaveBeenCalledWith('d-shadow', 'target');
    wrapper.unmount();
  });

  it('offers all THREE baselines on a shadow', async () => {
    const wrapper = mountView();
    await nextTick();
    await nextTick();

    await wrapper.find('[data-draft-id="d-shadow"]').findAll('button')
      .find((b) => b.text() === t('knowledge.compose.diff'))!
      .trigger('click');
    await nextTick();

    const labels = wrapper.find('[data-diff-panel]').findAll('[role="radio"]').map((r) => r.text());
    expect(labels[0]).toContain(t('knowledge.compose.diffStored'));
    expect(labels.join(' ')).toContain(t('knowledge.compose.diffOriginal'));
    expect(labels.join(' ')).toContain(t('knowledge.compose.diffPrevious'));
    wrapper.unmount();
  });

  // --- Rebase: free, and it says so ----------------------------------------

  it('rebases WITHOUT spending anything on AI', async () => {
    h.store.session = h.session([h.shadow({ target_revision_stale: true })]);
    h.store.rebaseDraft.mockResolvedValue(h.shadow({ target_revision_stale: false }));

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    await wrapper.find('[data-draft-id="d-shadow"]').findAll('button')
      .find((b) => b.text() === t('knowledge.compose.diff'))!
      .trigger('click');
    await nextTick();
    await nextTick();

    await wrapper.find('[data-diff-rebase]').trigger('click');
    await nextTick();
    await nextTick();

    expect(h.store.rebaseDraft).toHaveBeenCalledWith('s1', 'd-shadow');
    // THE PIN: neither AI-spending action was touched. A rebase only re-points the comparison.
    expect(h.store.refineDraftSession).not.toHaveBeenCalled();
    expect(h.store.expandDraftContext).not.toHaveBeenCalled();
    // And the panel is reloaded, so the diff now shows the version it claims to.
    expect(h.store.fetchDraftSession).toHaveBeenCalled();
    wrapper.unmount();
  });

  // --- 409 on acceptance ----------------------------------------------------

  it('turns a conflict into a card with TWO priced routes, and keeps the successes', async () => {
    h.store.session = h.session([h.shadow(), { ...h.shadow({ id: 'd-plain' }), targets_entry: null }]);
    h.store.fetchDraftSession.mockResolvedValue(h.store.session);
    // Partial: the plain draft published, the shadow conflicted.
    h.store.acceptDrafts.mockResolvedValue({
      accepted: [{ id: 'd-plain' }],
      conflicts: [{ entry_id: 'd-shadow', targets_entry_id: 'e-target', current_revision_id: 'r-new' }],
    });

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    const card = wrapper.find('[data-draft-id="d-shadow"]');
    await card.findAll('button').find((b) => b.text() === t('knowledge.compose.accept'))!.trigger('click');
    await nextTick();
    await nextTick();

    const conflict = wrapper.find('[data-draft-conflict]');
    expect(conflict.exists()).toBe(true);
    // Two routes, each with its price stated — free vs. costs.
    expect(conflict.find('[data-conflict-rebase]').exists()).toBe(true);
    expect(conflict.find('[data-conflict-refine]').exists()).toBe(true);
    expect(conflict.text()).toContain(t('knowledge.compose.conflict.freeHint'));
    expect(conflict.text()).toContain(t('knowledge.compose.conflict.costHint'));

    // The draft that DID publish is still reported as a success.
    expect(h.toast.success).toHaveBeenCalledWith(t('knowledge.compose.acceptedToast'));
    wrapper.unmount();
  });

  it('clears the conflict once the proposal has been rebased', async () => {
    h.store.session = h.session([h.shadow()]);
    h.store.fetchDraftSession.mockResolvedValue(h.store.session);
    h.store.acceptDrafts.mockResolvedValue({
      accepted: [],
      conflicts: [{ entry_id: 'd-shadow', targets_entry_id: 'e-target', current_revision_id: 'r-new' }],
    });
    h.store.rebaseDraft.mockResolvedValue(h.shadow());

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    await wrapper.find('[data-draft-id="d-shadow"]').findAll('button')
      .find((b) => b.text() === t('knowledge.compose.accept'))!
      .trigger('click');
    await nextTick();
    await nextTick();
    expect(wrapper.find('[data-draft-conflict]').exists()).toBe(true);

    await wrapper.find('[data-conflict-rebase]').trigger('click');
    await nextTick();
    await nextTick();

    expect(wrapper.find('[data-draft-conflict]').exists()).toBe(false);
    wrapper.unmount();
  });

  // TWO proposals against the SAME entry, accepted in bulk (B16).
  //
  // Reachable in practice: two people compose against one base and each is offered an amendment of
  // the same entry. The first to be applied wins; the second's optimistic-lock token is stale the
  // instant it does.
  //
  // Two decisions are pinned, and both are about not lying to the reviewer:
  //   • the bulk accept sends ONE id per call and STOPS at the first refusal. It does not push on
  //     and report a second failure the user cannot yet interpret — the details are on the card
  //     that conflicted, and burying them under a second identical panel helps nobody;
  //   • the second draft is therefore neither accepted nor marked as conflicted, because nothing
  //     was attempted for it. A card that showed a conflict it never had would send the reviewer
  //     to rebase a proposal that may not need it.
  it('stops a bulk acceptance at the first conflicting amendment and marks only that card', async () => {
    const second = { ...h.shadow({ id: 'd-shadow-2', title: 'Inna zmiana polityki' }) };
    h.store.session = h.session([h.shadow(), second]);
    h.store.fetchDraftSession.mockResolvedValue(h.store.session);
    h.store.acceptDrafts.mockResolvedValue({
      accepted: [],
      conflicts: [{ entry_id: 'd-shadow', targets_entry_id: 'e-target', current_revision_id: 'r-new' }],
    });

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    // Select every draft (the board's select-all is the first checkbox on the page).
    await wrapper.findAll('input[type="checkbox"]')[0].setValue(true);
    await nextTick();

    await wrapper.find('[data-accept-selected]').trigger('click');
    await nextTick();
    await nextTick();
    await nextTick();

    // ONE call, for the first draft only — the loop broke on its refusal.
    expect(h.store.acceptDrafts).toHaveBeenCalledTimes(1);
    expect(h.store.acceptDrafts).toHaveBeenCalledWith('s1', ['d-shadow'], 'approved');

    // The conflicted card grows the two-route panel; the untouched one stays clean.
    const conflicts = wrapper.findAll('[data-draft-conflict]');
    expect(conflicts).toHaveLength(1);
    expect(wrapper.find('[data-draft-id="d-shadow"]').find('[data-draft-conflict]').exists()).toBe(true);
    expect(wrapper.find('[data-draft-id="d-shadow-2"]').find('[data-draft-conflict]').exists()).toBe(false);

    // Nothing published, so nothing is announced as published.
    expect(h.toast.success).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  // --- expand-context: an explicit, metered spend --------------------------

  it('widens the context, refreshes the meter, and says so', async () => {
    h.store.expandDraftContext.mockResolvedValue(h.session([h.shadow()]));

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    // Open the relations panel, which is where the action lives.
    const relationsToggle = wrapper.find('.next-accordion-item__header');
    await relationsToggle.trigger('click');
    await nextTick();
    await nextTick();

    await wrapper.find('[data-expand-context]').trigger('click');
    await nextTick();
    await nextTick();

    expect(h.store.expandDraftContext).toHaveBeenCalledWith('s1');
    expect(h.toast.success).toHaveBeenCalledWith(t('knowledge.compose.expandContextDone'));
    // Every spend refreshes the meter, or the budget signal goes stale until a page reload.
    expect(h.aiUsage.fetchAiUsage).toHaveBeenCalled();
    wrapper.unmount();
  });

  it('goes SPENT after widening — the second click cannot buy the same thing twice', async () => {
    h.store.expandDraftContext.mockResolvedValue(h.session([h.shadow()]));

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    const relationsToggle = wrapper.find('.next-accordion-item__header');
    await relationsToggle.trigger('click');
    await nextTick();
    await nextTick();

    await wrapper.find('[data-expand-context]').trigger('click');
    await nextTick();
    await nextTick();
    expect(h.store.expandDraftContext).toHaveBeenCalledWith('s1');

    // The SESSION now carries the flag (the server sets it; the store just adopts the response).
    // Read from there, so the action is off for everyone looking at this session and survives a
    // reload — the effect is invisible until the next revision, so it has to say what happened
    // rather than sit there looking clickable.
    h.store.session = { ...h.session([h.shadow()]), context_expanded_at: '2026-08-01T10:00:00Z' };
    const after = mountView();
    await nextTick();
    await nextTick();
    await after.find('.next-accordion-item__header').trigger('click');
    await nextTick();
    await nextTick();

    const button = after.find('[data-expand-context]');
    expect(button.attributes('disabled')).toBeDefined();
    expect(after.find('[data-expand-context-done]').text()).toBe(
      t('knowledge.compose.expandContextReady'),
    );

    // THE PIN: clicking again spends nothing.
    h.store.expandDraftContext.mockClear();
    await button.trigger('click');
    await nextTick();
    expect(h.store.expandDraftContext).not.toHaveBeenCalled();

    wrapper.unmount();
    after.unmount();
  });

  it('offers the widening again once a revision has consumed it', async () => {
    // A revision USES the widened context, and the server returns the session with the flag
    // already cleared — so a null field is the whole re-arm.
    h.store.session = { ...h.session([h.shadow()]), context_expanded_at: null };

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    await wrapper.find('.next-accordion-item__header').trigger('click');
    await nextTick();
    await nextTick();

    const button = wrapper.find('[data-expand-context]');
    expect(button.attributes('disabled')).toBeUndefined();
    expect(wrapper.find('[data-expand-context-done]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('treats "already widened" as the STATE it is — refetch, explain, no red toast', async () => {
    // The race: a second reviewer (or a reloaded tab) presses it after someone else already did.
    // Nothing was spent, and the state the user wanted is the state that exists.
    h.store.expandDraftContext.mockRejectedValue({
      response: { status: 422, data: { code: 'knowledge_context_already_expanded' } },
    });

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    await wrapper.find('.next-accordion-item__header').trigger('click');
    await nextTick();
    await nextTick();

    h.store.fetchDraftSession.mockClear();
    await wrapper.find('[data-expand-context]').trigger('click');
    await nextTick();
    await nextTick();

    // Refetched, so `context_expanded_at` arrives and the button goes off here too...
    expect(h.store.fetchDraftSession).toHaveBeenCalledWith('s1');
    // ...explained as a state, not reported as a failure.
    expect(h.toast.info).toHaveBeenCalledWith(t('knowledge.compose.expandContextAlready'));
    expect(h.toast.danger).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  it('treats a 429 from expand-context as a BUDGET STATE, not a red error', async () => {
    h.store.expandDraftContext.mockRejectedValue({ response: { status: 429, data: { code: 'ai_budget_exceeded' } } });

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    const relationsToggle = wrapper.find('.next-accordion-item__header');
    await relationsToggle.trigger('click');
    await nextTick();
    await nextTick();

    h.store.fetchComposeAvailability.mockClear();
    await wrapper.find('[data-expand-context]').trigger('click');
    await nextTick();
    await nextTick();

    // Re-ask availability so the screen swaps to the explanation (with its reset date) — a generic
    // danger toast would read as a bug rather than as "the workspace is out of budget".
    expect(h.store.fetchComposeAvailability).toHaveBeenCalledWith('b1');
    expect(h.toast.danger).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  it('sends "revise again" through the REFINE path, which is the one that costs', async () => {
    h.store.session = h.session([h.shadow()]);
    h.store.fetchDraftSession.mockResolvedValue(h.store.session);
    h.store.acceptDrafts.mockResolvedValue({
      accepted: [],
      conflicts: [{ entry_id: 'd-shadow', targets_entry_id: 'e-target', current_revision_id: 'r-new' }],
    });

    const wrapper = mountView();
    await nextTick();
    await nextTick();

    await wrapper.find('[data-draft-id="d-shadow"]').findAll('button')
      .find((b) => b.text() === t('knowledge.compose.accept'))!
      .trigger('click');
    await nextTick();
    await nextTick();

    await wrapper.find('[data-conflict-refine]').trigger('click');
    await nextTick();

    // It PREFILLS a scoped instruction rather than firing a run behind the user's back — spending
    // is always a deliberate second click. (The board's only textarea is the refine bar: the
    // source form is gone once a session exists.)
    const refineBox = wrapper.find('textarea').element as HTMLTextAreaElement;
    expect(refineBox.value).toContain('Polityka zwrotów');
    expect(h.store.refineDraftSession).not.toHaveBeenCalled();
    wrapper.unmount();
  });
});
