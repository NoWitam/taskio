// @vitest-environment happy-dom
// BotVisualPanel.spec — the "Wygląd" panel's ASYNC flow and its refusals.
//
// The load-bearing behaviours:
//   • GENERATE SAVES FIRST when the form is dirty — the server composes the prompt from the PERSISTED
//     module, so skipping the save would draw the previous description;
//   • a save that fails STOPS the run (no provider spend on a bot that was refused);
//   • the follow-up wait must NOT keep the image (the candidate is filed server-side; the panel refetches);
//   • ONE 429 means three different things and each needs a different fix — a route throttle, an
//     exhausted monthly $ budget (with an owner-only CTA), or the daily image cap;
//   • an OFF module is fully operable (the toggle gates USE in sessions, not authoring);
//   • a moderation refusal points at the WARDROBE, because that is the field that changes the answer.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setLocale } from '../../../app/i18n';
import { en } from '../../../app/i18n/en';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const hoisted = vi.hoisted(() => ({
  botsStore: {
    generateVisual: vi.fn(),
    approveVisualCandidate: vi.fn(),
    deleteVisualCandidate: vi.fn(),
    fetchBot: vi.fn(),
  },
  aiUsage: { summary: null as null | Record<string, unknown>, fetchAiUsage: vi.fn() },
  // Ref-SHAPED plain objects: `vi.hoisted` runs before the `vue` import, and the panel only reads
  // `.value` off these (the live status line is not what this spec asserts).
  job: {
    status: { value: 'idle' as string },
    error: { value: null as string | null },
    errorCode: { value: null as string | null },
    busy: { value: false },
    startedAt: { value: null as number | null },
    start: vi.fn(),
    cancel: vi.fn(),
    reset: vi.fn(),
  },
  push: vi.fn(),
  confirm: vi.fn(),
  toast: { success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

vi.mock('../../../app/stores/bots', () => ({ useBotsStore: () => hoisted.botsStore }));
vi.mock('../../../app/stores/aiUsage', () => ({ useAiUsageStore: () => hoisted.aiUsage }));
vi.mock('../../../app/stores/auth', () => ({ useAuthStore: () => ({ currentWorkspaceId: 'ws-1' }) }));
vi.mock('../../../app/composables/useAiImageJob', () => ({ useAiImageJob: () => hoisted.job }));
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => hoisted.confirm }));
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => hoisted.toast }));
vi.mock('vue-router', () => ({ useRouter: () => ({ push: hoisted.push }) }));

import BotVisualPanel from '../BotVisualPanel.vue';
import type { BotVisualIdentity } from '../types';

const stubs = {
  BotVisualImage: { name: 'BotVisualImage', props: ['fileId', 'alt', 'fit'], template: '<img />' },
  DiskFilePickerModal: { name: 'DiskFilePickerModal', props: ['open'], template: '<div />' },
  FileDropzone: { name: 'FileDropzone', props: ['modelValue'], template: '<div class="dropzone" />' },
};

function server(over: Partial<BotVisualIdentity> = {}): BotVisualIdentity {
  return {
    enabled: true,
    descriptor: 'A woman around 30',
    aesthetic: null,
    wardrobe: null,
    prohibitions: [],
    reference_file_id: null,
    candidates: [],
    canonical_file_id: null,
    prompt: null,
    ...over,
  };
}

function bot(visual: BotVisualIdentity | null) {
  return { id: 'b1', name: 'Ada', visual } as never;
}

function mountPanel(props: Record<string, unknown> = {}) {
  const save = (props.save as () => Promise<unknown>) ?? vi.fn(async () => bot(server()));
  return mount(BotVisualPanel, {
    props: {
      botId: 'b1',
      botName: 'Ada',
      enabled: true,
      server: server(),
      dirty: false,
      descriptor: 'A woman around 30',
      wardrobe: '',
      aesthetic: '',
      prohibitions: [],
      ...props,
      save,
    },
    global: { stubs },
  });
}

function generateButton(wrapper: ReturnType<typeof mountPanel>) {
  return wrapper.findAll('button').find((b) => b.text().includes(en.bots.editor.visual.generate))!;
}

describe('BotVisualPanel', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
    hoisted.aiUsage.summary = null;
    hoisted.job.status.value = 'idle';
    hoisted.botsStore.generateVisual.mockResolvedValue('job-1');
    hoisted.botsStore.fetchBot.mockResolvedValue(bot(server({ candidates: ['new'] })));
    hoisted.job.start.mockResolvedValue({ status: 'done' });
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('saves the bot BEFORE generating when the form is dirty (the prompt reads the saved module)', async () => {
    const save = vi.fn(async () => bot(server()));
    const wrapper = mountPanel({ dirty: true, save });

    await generateButton(wrapper).trigger('click');
    await flushPromises();

    expect(save).toHaveBeenCalledTimes(1);
    expect(hoisted.botsStore.generateVisual).toHaveBeenCalledTimes(1);
    // The save order matters, so assert it, not just that both happened.
    expect(save.mock.invocationCallOrder[0]).toBeLessThan(
      hoisted.botsStore.generateVisual.mock.invocationCallOrder[0],
    );
    // The user is told the click will save, before they click.
    expect(wrapper.text()).toContain(en.bots.editor.visual.generateSavesHint);
    wrapper.unmount();
  });

  it('does NOT spend when the pre-flight save was refused', async () => {
    const save = vi.fn(async () => null);
    const wrapper = mountPanel({ dirty: true, save });

    await generateButton(wrapper).trigger('click');
    await flushPromises();

    expect(hoisted.botsStore.generateVisual).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  it('skips the save when nothing changed, then follows the job WITHOUT keeping the image', async () => {
    const save = vi.fn(async () => bot(server()));
    const wrapper = mountPanel({ dirty: false, save });

    await generateButton(wrapper).trigger('click');
    await flushPromises();

    expect(save).not.toHaveBeenCalled();
    // No `keepImage` — the produced PNG is already a candidate server-side.
    expect(hoisted.job.start).toHaveBeenCalledWith('job-1');
    // …so the panel REFETCHES the bot and hands it up.
    expect(hoisted.botsStore.fetchBot).toHaveBeenCalledWith('b1');
    expect(wrapper.emitted('sync')).toBeTruthy();
    wrapper.unmount();
  });

  it('sends the mode + a Disk-picked reference id straight through (no copy-to-temp)', async () => {
    const wrapper = mountPanel({ server: server({ reference_file_id: 'disk-file-9' }) });

    await generateButton(wrapper).trigger('click');
    await flushPromises();

    expect(hoisted.botsStore.generateVisual).toHaveBeenCalledWith('b1', {
      mode: 'reference',
      reference: null,
      reference_file_id: 'disk-file-9',
      instruction: null,
    });
    wrapper.unmount();
  });

  it('blocks generating with nothing to draw from, and says so', () => {
    const wrapper = mountPanel({ descriptor: '', wardrobe: '', aesthetic: '' });
    expect(generateButton(wrapper).attributes('disabled')).toBeDefined();
    expect(wrapper.text()).toContain(en.bots.editor.visual.generateNeedsMaterial);
    wrapper.unmount();
  });

  it('blocks generating for an unsaved bot, and says so', () => {
    const wrapper = mountPanel({ botId: null });
    expect(generateButton(wrapper).attributes('disabled')).toBeDefined();
    expect(wrapper.text()).toContain(en.bots.editor.visual.generateNeedsBot);
    wrapper.unmount();
  });

  it('warns BEFORE the click that a full strip will lose its oldest image', () => {
    const wrapper = mountPanel({ server: server({ candidates: ['a', 'b', 'c', 'd', 'e', 'f'] }) });
    expect(wrapper.text()).toContain('You have all 6 likenesses');
    wrapper.unmount();
  });

  it('stays fully operable while the module is OFF, and explains the difference', () => {
    const wrapper = mountPanel({ enabled: false });
    expect(wrapper.text()).toContain(en.bots.editor.visual.offHint);
    // NOT greyed out: `enabled` gates USE in sessions, not authoring.
    expect(generateButton(wrapper).attributes('disabled')).toBeUndefined();
    wrapper.unmount();
  });

  // --- The three meanings of one 429 -----------------------------------------
  it('reads a THROTTLE 429 off the rate-limit headers', async () => {
    hoisted.botsStore.generateVisual.mockRejectedValue({
      response: { status: 429, headers: { 'retry-after': '30' } },
    });
    const wrapper = mountPanel();

    await generateButton(wrapper).trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain(en.bots.editor.visual.errors.throttled);
    // A throttle is not a budget problem — no usage refresh, no CTA.
    expect(hoisted.aiUsage.fetchAiUsage).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  it('reads a BUDGET 429 off the usage summary and offers the owner a way to fix it', async () => {
    hoisted.botsStore.generateVisual.mockRejectedValue({ response: { status: 429, headers: {} } });
    hoisted.aiUsage.fetchAiUsage.mockImplementation(async () => {
      hoisted.aiUsage.summary = { blocked: true, can_manage: true };
    });
    const wrapper = mountPanel();

    await generateButton(wrapper).trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain(en.bots.editor.visual.errors.budget);
    const cta = wrapper.findAll('button').find((b) => b.text().includes(en.bots.editor.visual.errors.budgetManage));
    expect(cta).toBeTruthy();
    await cta!.trigger('click');
    expect(hoisted.push).toHaveBeenCalledWith({ name: 'next.settings.aiUsage' });
    wrapper.unmount();
  });

  it('tells a NON-owner to ask the workspace owner instead of offering a control they lack', async () => {
    hoisted.botsStore.generateVisual.mockRejectedValue({ response: { status: 429, headers: {} } });
    hoisted.aiUsage.fetchAiUsage.mockImplementation(async () => {
      hoisted.aiUsage.summary = { blocked: true, can_manage: false };
    });
    const wrapper = mountPanel();

    await generateButton(wrapper).trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain(en.bots.editor.visual.errors.budgetContactOwner);
    expect(
      wrapper.findAll('button').some((b) => b.text().includes(en.bots.editor.visual.errors.budgetManage)),
    ).toBe(false);
    wrapper.unmount();
  });

  it('falls back to the DAILY CAP for a 429 that is neither a throttle nor an exhausted budget', async () => {
    hoisted.botsStore.generateVisual.mockRejectedValue({ response: { status: 429, headers: {} } });
    hoisted.aiUsage.fetchAiUsage.mockImplementation(async () => {
      hoisted.aiUsage.summary = { blocked: false, can_manage: true };
    });
    const wrapper = mountPanel();

    await generateButton(wrapper).trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain(en.bots.editor.visual.errors.dailyCap);
    wrapper.unmount();
  });

  it('names the creator-only rule on a 403, and the connection on no response at all', async () => {
    hoisted.botsStore.generateVisual.mockRejectedValue({ response: { status: 403 } });
    const forbidden = mountPanel();
    await generateButton(forbidden).trigger('click');
    await flushPromises();
    expect(forbidden.text()).toContain(en.bots.editor.visual.errors.forbidden);
    forbidden.unmount();

    hoisted.botsStore.generateVisual.mockRejectedValue(new Error('offline'));
    const offline = mountPanel();
    await generateButton(offline).trigger('click');
    await flushPromises();
    expect(offline.text()).toContain(en.bots.editor.visual.errors.network);
    offline.unmount();
  });

  it('promotes a MODERATION refusal to its own message and points at the wardrobe field', async () => {
    hoisted.job.start.mockResolvedValue({
      status: 'failed',
      error: 'generic provider prose',
      errorCode: 'safety_rejected',
      reason: 'failed',
    });
    const wrapper = mountPanel();

    await generateButton(wrapper).trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain(en.bots.editor.visual.errors.safety);
    expect(wrapper.text()).not.toContain('generic provider prose');
    // The wardrobe is highlighted AND wired to the notice for assistive tech.
    expect(wrapper.html()).toContain('ring-next-warning');
    expect(wrapper.find('textarea[aria-describedby*="bot-visual-safety-notice"]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('approves a candidate through the store and hands the fresh bot up', async () => {
    hoisted.botsStore.approveVisualCandidate.mockResolvedValue(bot(server({ canonical_file_id: 'f1' })));
    const wrapper = mountPanel({ server: server({ candidates: ['f1'] }) });

    wrapper.findComponent({ name: 'BotVisualCandidates' }).vm.$emit('approve', 'f1');
    await flushPromises();

    expect(hoisted.botsStore.approveVisualCandidate).toHaveBeenCalledWith('b1', 'f1');
    expect(wrapper.emitted('sync')).toBeTruthy();
    wrapper.unmount();
  });

  it('confirms before deleting a candidate, and does nothing when the user backs out', async () => {
    hoisted.confirm.mockResolvedValue(false);
    const wrapper = mountPanel({ server: server({ candidates: ['f1'] }) });

    wrapper.findComponent({ name: 'BotVisualCandidates' }).vm.$emit('remove', 'f1');
    await flushPromises();

    expect(hoisted.confirm).toHaveBeenCalled();
    expect(hoisted.botsStore.deleteVisualCandidate).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  it('clears an approval through a normal SAVE (there is no endpoint for it)', async () => {
    hoisted.confirm.mockResolvedValue(true);
    const save = vi.fn(async () => bot(server({ canonical_file_id: null })));
    const wrapper = mountPanel({ server: server({ candidates: ['f1'], canonical_file_id: 'f1' }), save });

    const unapprove = wrapper
      .findAll('button')
      .find((b) => b.text().includes(en.bots.editor.visual.candidates.unapprove))!;
    await unapprove.trigger('click');
    await flushPromises();

    expect(save).toHaveBeenCalledWith({ canonicalFileId: null });
    wrapper.unmount();
  });
});
