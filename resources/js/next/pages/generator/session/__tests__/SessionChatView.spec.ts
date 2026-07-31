// @vitest-environment happy-dom
// SessionChatView.spec — the delegation UNDO gating regression (R2 sub-stage 3).
//
// The defect: the FE gated BOTH "Delegate" and "Undo delegation" on `can_delegate`, which is FALSE on a
// `failed` session — so after a bot delegation whose auto-generate run FAILED, the human was STUCK with a
// bot author + bot-overwritten inputs and could not revert (the Undo item was permanently disabled). The
// fix gates ONLY undo on the new server flag `can_undo_delegation` (owner && delegated && NOT mid-run), so a
// failed delegated session stays revertible; delegate keeps gating on `can_delegate`.
//
// These tests assert: a FAILED delegated session (`can_undo_delegation: true`) exposes an ACTIONABLE Undo
// item that calls `store.undoDelegation` on click; a GENERATING one (`can_undo_delegation: false`) has it
// disabled. The first case FAILS on the old `!canDelegate` gate (undo disabled on `failed`) and PASSES after.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { h as vh, nextTick, type VNode } from 'vue';
import { setLocale } from '../../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../../__tests__/helpers/dom';
import type { Session } from '../../sessionTypes';

// Inline stubs so the teleport-free menu item is queryable (mirrors SessionsView.spec).
const DropdownMenuStub = {
  name: 'DropdownMenu',
  setup(_p: unknown, { slots }: { slots: Record<string, ((arg?: unknown) => VNode[]) | undefined> }) {
    return () =>
      vh('div', { class: 'dm' }, [
        slots.trigger ? slots.trigger({ props: {} }) : null,
        vh('ul', { class: 'dm-list' }, slots.default ? slots.default() : []),
      ]);
  },
};
const DropdownMenuItemStub = {
  name: 'DropdownMenuItem',
  props: ['icon', 'label', 'disabled'],
  emits: ['select'],
  setup(
    props: Record<string, unknown>,
    { emit }: { emit: (e: string) => void },
  ) {
    return () =>
      vh('button', {
        class: 'dm-item',
        'data-label': props.label,
        disabled: props.disabled ? true : undefined,
        onClick: () => emit('select'),
      });
  },
};
// PageHeader stub that renders the #meta + #actions slots (the menu lives in #actions).
const PageHeaderStub = {
  name: 'PageHeader',
  setup(_p: unknown, { slots }: { slots: Record<string, (() => VNode[]) | undefined> }) {
    return () =>
      vh('div', { class: 'ph' }, [slots.meta ? slots.meta() : null, slots.actions ? slots.actions() : null]);
  },
};
const SlotPassthroughStub = {
  name: 'SlotPassthrough',
  setup(_p: unknown, { slots }: { slots: Record<string, (() => VNode[]) | undefined> }) {
    return () => vh('div', {}, slots.default ? slots.default() : []);
  },
};

const base: Session = {
  id: 's1',
  name: 'Spring promo',
  template_id: 't1',
  content_type: 'post_with_image',
  status: 'failed',
  slot_values: {},
  results: null,
  // DETAIL-only on the wire; null here so the direction turn stays out of these delegation assertions.
  creative_direction: null,
  part_history: {},
  last_op_status: null,
  last_op_error: null,
  creator: null,
  is_owner: true,
  can_generate: false,
  can_edit: false,
  can_be_deleted: true,
  bot_author: { id: 'b1', name: 'Marketing Bot', icon: null },
  is_delegated: true,
  has_character_image: false,
  can_delegate: false,
  can_undo_delegation: true,
  unfilled_required_slots: [],
  is_archived: false,
  can_archive: true,
  archived_at: null,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
};

const h = vi.hoisted(() => ({
  store: {
    detail: null as unknown,
    fetchSession: vi.fn(),
    fetchContentTypes: vi.fn().mockResolvedValue([]),
    fetchSourceTemplate: vi.fn().mockResolvedValue({ slots: [] }),
    undoDelegation: vi.fn(),
    generate: vi.fn(),
    patchSession: vi.fn(),
  },
  toast: { success: vi.fn(), danger: vi.fn(), warning: vi.fn() },
  confirm: vi.fn().mockResolvedValue(true),
  settle: { waitForSettle: vi.fn().mockResolvedValue({ session: null, settled: true }), dispose: vi.fn() },
  // The shared AI-usage store — `summary` is set per-test to drive the budget-gating (R2 sub-stage 4).
  aiUsage: { summary: null as unknown, fetchAiUsage: vi.fn().mockResolvedValue(undefined) },
}));

vi.mock('../../../../app/stores/sessions', () => ({
  useSessionsStore: () => h.store,
  // The budget-error recognizer is covered in the sessions store spec; here it never fires (no budget error).
  isBudgetError: () => false,
}));
vi.mock('../../../../app/composables/useToast', () => ({ useToast: () => h.toast }));
vi.mock('../../../../app/composables/useConfirm', () => ({ useConfirm: () => h.confirm }));
vi.mock('../useSessionSettle', () => ({ useSessionSettle: () => h.settle }));
// The AI-budget signals (R2 sub-stage 4) read the auth + ai-usage stores; stub them to a no-cap workspace so
// the chip/banner stay inert and this delegation-gating test is unaffected.
vi.mock('../../../../app/stores/auth', () => ({ useAuthStore: () => ({ currentWorkspaceId: null }) }));
vi.mock('../../../../app/stores/aiUsage', () => ({ useAiUsageStore: () => h.aiUsage }));
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: 's1' } }),
  useRouter: () => ({ push: vi.fn() }),
}));

import SessionChatView from '../SessionChatView.vue';

function mountView(session: Session) {
  h.store.detail = session;
  h.store.undoDelegation.mockResolvedValue({ ...session, is_delegated: false, bot_author: null });
  return mount(SessionChatView, {
    attachTo: document.body,
    global: {
      stubs: {
        DropdownMenu: DropdownMenuStub,
        DropdownMenuItem: DropdownMenuItemStub,
        PageHeader: PageHeaderStub,
        Tooltip: SlotPassthroughStub,
        // The rest of the heavy chat surface is irrelevant to the undo-gating assertion.
        StatusBadge: true,
        Badge: true,
        Button: true,
        Icon: true,
        EmptyState: true,
        Skeleton: true,
        Card: true,
        SessionTurn: true,
        SessionSlotSetupCard: true,
        SessionResultCard: true,
        SessionComposer: true,
        FinalPostPane: true,
        FinalPostBody: true,
        SessionSaveToDiskModal: true,
        DelegateBotDialog: true,
        SessionFillReportPanel: true,
        BotAuthorChip: true,
      },
    },
  });
}

async function flush() {
  await nextTick();
  await Promise.resolve();
  await nextTick();
}

describe('SessionChatView — delegation undo gating', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
    h.store.fetchContentTypes.mockResolvedValue([]);
    h.store.fetchSourceTemplate.mockResolvedValue({ slots: [] });
    h.confirm.mockResolvedValue(true);
    h.settle.waitForSettle.mockResolvedValue({ session: null, settled: true });
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('a FAILED delegated session keeps Undo delegation ACTIONABLE and undoes on click', async () => {
    const wrapper = mountView({ ...base, status: 'failed', is_delegated: true, can_undo_delegation: true });
    await flush();

    const undo = wrapper.find('.dm-item[data-label="Undo delegation"]');
    expect(undo.exists()).toBe(true);
    // Actionable — NOT disabled — even though the session is failed (can_delegate would be false here).
    expect(undo.attributes('disabled')).toBeUndefined();

    await undo.trigger('click');
    await flush();

    expect(h.confirm).toHaveBeenCalledTimes(1);
    expect(h.store.undoDelegation).toHaveBeenCalledWith('s1', 'b1');

    wrapper.unmount();
  });

  it('a GENERATING delegated session disables Undo delegation', async () => {
    const wrapper = mountView({
      ...base,
      status: 'generating',
      is_delegated: true,
      can_undo_delegation: false,
    });
    await flush();

    const undo = wrapper.find('.dm-item[data-label="Undo delegation"]');
    expect(undo.exists()).toBe(true);
    expect(undo.attributes('disabled')).toBe('');

    await undo.trigger('click');
    await flush();

    expect(h.store.undoDelegation).not.toHaveBeenCalled();

    wrapper.unmount();
  });
});

// --- AI budget gating (R2 sub-stage 4 hardening) ----------------------------
// A real <button> Button stub (renders the label + reflects :disabled) so the Generate affordance is queryable
// by its text, and a SessionComposer stub that exposes its :disabled prop as a data attribute.
const ButtonStub = {
  name: 'Button',
  props: ['disabled', 'loading', 'variant', 'leadingIcon', 'menuItems', 'menuAriaLabel'],
  emits: ['click', 'menu-select'],
  setup(props: Record<string, unknown>, { slots, emit }: { slots: Record<string, (() => VNode[]) | undefined>; emit: (e: string) => void }) {
    return () =>
      vh(
        'button',
        { class: 'btn', disabled: props.disabled ? true : undefined, onClick: () => emit('click') },
        slots.default ? slots.default() : [],
      );
  },
};
const ComposerStub = {
  name: 'SessionComposer',
  props: ['targets', 'disabled'],
  setup(props: Record<string, unknown>) {
    return () => vh('div', { class: 'composer', 'data-disabled': props.disabled ? 'true' : 'false' });
  },
};

/** A CAPPED, over-budget summary (the pre-emptive `blocked` signal the affordances must reflect). */
const blockedSummary = {
  currency: 'USD',
  estimated: true,
  cost_used: 12,
  cost_cap: 10,
  cost_remaining: 0,
  cap_source: 'workspace',
  warn_ratio: 0.8,
  warn_reached: true,
  blocked: true,
  period: { month: '2026-07', resets_at: '2026-08-01T00:00:00Z' },
  tokens_used: 0,
  per_channel: [],
  per_actor: [],
  can_manage: true,
};

function mountBudgetView(session: Session) {
  h.store.detail = session;
  return mount(SessionChatView, {
    attachTo: document.body,
    global: {
      stubs: {
        DropdownMenu: DropdownMenuStub,
        DropdownMenuItem: DropdownMenuItemStub,
        PageHeader: PageHeaderStub,
        Tooltip: SlotPassthroughStub,
        Button: ButtonStub,
        SessionComposer: ComposerStub,
        StatusBadge: true,
        Badge: true,
        Icon: true,
        EmptyState: true,
        Skeleton: true,
        Card: true,
        SessionTurn: true,
        SessionSlotSetupCard: true,
        SessionResultCard: true,
        FinalPostPane: true,
        FinalPostBody: true,
        SessionSaveToDiskModal: true,
        DelegateBotDialog: true,
        SessionFillReportPanel: true,
        BotAuthorChip: true,
      },
    },
  });
}

/** The whole-session Generate button, found by its label text among the rendered Button stubs. */
function generateButton(wrapper: ReturnType<typeof mountBudgetView>) {
  return wrapper.findAll('button.btn').find((b) => b.text() === 'Generate' || b.text() === 'Regenerate all');
}

describe('SessionChatView — AI budget gating', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
    h.aiUsage.summary = null;
    h.store.fetchContentTypes.mockResolvedValue([]);
    h.store.fetchSourceTemplate.mockResolvedValue({ slots: [] });
    h.settle.waitForSettle.mockResolvedValue({ session: null, settled: true });
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
    h.aiUsage.summary = null;
  });

  it('DISABLES Generate + the composer when the workspace is over budget (blocked)', async () => {
    h.aiUsage.summary = blockedSummary;
    const wrapper = mountBudgetView({
      ...base,
      status: 'draft',
      can_generate: true,
      is_delegated: false,
      bot_author: null,
    });
    await flush();

    const gen = generateButton(wrapper);
    expect(gen?.exists()).toBe(true);
    expect(gen?.attributes('disabled')).toBe('');

    expect(wrapper.find('.composer').attributes('data-disabled')).toBe('true');

    wrapper.unmount();
  });

  it('ENABLES Generate + the composer when NOT blocked (uncapped workspace)', async () => {
    h.aiUsage.summary = null; // uncapped → budgetBlocked false
    const wrapper = mountBudgetView({
      ...base,
      status: 'draft',
      can_generate: true,
      is_delegated: false,
      bot_author: null,
    });
    await flush();

    const gen = generateButton(wrapper);
    expect(gen?.exists()).toBe(true);
    expect(gen?.attributes('disabled')).toBeUndefined();

    expect(wrapper.find('.composer').attributes('data-disabled')).toBe('false');

    wrapper.unmount();
  });

  it('a whole-generate settle that failed with a budget last_op_error shows THAT message (not the generic one)', async () => {
    h.settle.waitForSettle.mockResolvedValue({
      session: { ...base, status: 'failed', last_op_error: 'AI budget reached — generation paused' },
      settled: true,
    });
    // A session that lands mid-run auto-settles on load → the settle path surfaces last_op_error.
    const wrapper = mountBudgetView({ ...base, status: 'generating', is_delegated: false, bot_author: null });
    await flush();
    await flush();

    expect(h.toast.danger).toHaveBeenCalledWith('AI budget reached — generation paused');

    wrapper.unmount();
  });
});

// --- Character likeness chip (R2 sub-stage 3) --------------------------------
// A delegated session that froze a LIKENESS draws the SAME person on every image. That is a different
// statement from "a bot wrote this" (the author chip), and without it a reader has no way to know why the
// pictures show a recurring face — so it rides right after the author chip, with a title that says it.
describe('SessionChatView — the character likeness chip', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
    h.store.fetchContentTypes.mockResolvedValue([]);
    h.store.fetchSourceTemplate.mockResolvedValue({ slots: [] });
    h.settle.waitForSettle.mockResolvedValue({ session: null, settled: true });
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  /** The header chips carrying the character glyph (StatusBadge / BotAuthorChip are their own stubs). */
  function characterChips(wrapper: ReturnType<typeof mountView>) {
    return wrapper.findAll('badge-stub').filter((b) => b.attributes('icon') === 'user');
  }

  it('shows the chip for a delegated session that froze a likeness', async () => {
    const wrapper = mountView({ ...base, has_character_image: true });
    await flush();
    expect(characterChips(wrapper)).toHaveLength(1);
    wrapper.unmount();
  });

  it('stays quiet without a likeness — and a stale flag cannot resurrect it on an undelegated session', async () => {
    const noLikeness = mountView({ ...base, has_character_image: false });
    await flush();
    expect(characterChips(noLikeness)).toHaveLength(0);
    noLikeness.unmount();

    const undelegated = mountView({
      ...base,
      is_delegated: false,
      bot_author: null,
      has_character_image: true,
    });
    await flush();
    expect(characterChips(undelegated)).toHaveLength(0);
    undelegated.unmount();
  });
});
