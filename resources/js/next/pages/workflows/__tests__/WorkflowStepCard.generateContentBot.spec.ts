// @vitest-environment happy-dom
// WorkflowStepCard.generateContentBot.spec — the `generate_content` step's optional session AUTHOR
// (R2 sub-stage 3, workflow side): the step may delegate the session it creates to a bot, which
// lends it a VOICE in the copy and (when it has one) a LIKENESS on the images.
//
// Concentrated on the four things that would silently mislead or cost the author a 422:
//   1. the WIRE — an author rides as `config.bot_id`, and "no author" sends NO key at all (the
//      backend reads null/absent/'' the same, but anything else non-uuid is a 422),
//   2. the picker is NOT capability-filtered — a paused bot is a legal author (the run-time
//      resolver does not filter by status either), so `can_execute_tasks` must never be sent,
//   3. what the bot BRINGS is stated from FACTS, never guessed: the voice always, the likeness only
//      when its module is on AND an image is approved — the same wording the interactive
//      delegation uses,
//   4. a SAVED author renders by NAME (the wire carries only an id) and the server's per-field 422
//      lands on the field.
//
// The author's facts come from the shared `botDirectory` store, so `api` is stubbed (partial mock —
// the auth store the directory scopes by imports more than `api` from that module). The templates
// store is spied exactly as the sibling generate_content spec does; no real HTTP anywhere.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { h, nextTick, reactive } from 'vue';

vi.mock('../../../app/lib/api', async (importOriginal) => {
  const actual = await importOriginal<Record<string, unknown>>();
  return {
    ...actual,
    api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
  };
});

import { api } from '../../../app/lib/api';
import WorkflowStepCard from '../WorkflowStepCard.vue';
import BotSelect from '../../../ui/forms/BotSelect.vue';
import { buildStepConfig, emptyStepConfig, type StepDraft } from '../workflowEditorModel';
import { useTemplatesStore } from '../../../app/stores/templates';
import { useBotDirectoryStore } from '../../../app/stores/botDirectory';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { en } from '../../../app/i18n/en';
import type { WorkflowCatalog } from '../types';
import type { Template } from '../../generator/types';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

/** Real uuids — the directory refuses anything else WITHOUT a request. */
const BOT_A = '3f2a1c64-9f1e-4a7b-8c3d-2b6e5f0a1d94';
const BOT_B = 'c1e77a52-4d3b-4f10-9a86-71b0c9d2e345';

const CATALOG: WorkflowCatalog = {
  variables: [{ source: 'trigger', path: 'fields.topic', name: 'Topic', type: 'text' }],
  fields: [],
  operations: [],
};

const TEMPLATE: Template = {
  id: 'tpl-1',
  name: 'Launch post',
  description: null,
  content_type: 'post_with_image',
  slots: [],
  content: {},
  is_owner: true,
  can_be_edited: true,
  can_be_deleted: true,
  created_at: null,
  updated_at: null,
};

const MarkdownEditorStub = {
  name: 'MarkdownEditor',
  props: ['modelValue'],
  setup: () => () => h('textarea', { class: 'md-stub' }),
};
const FolderPickerStub = {
  name: 'FolderPickerPanel',
  props: ['modelValue', 'excludeId'],
  emits: ['update:modelValue'],
  setup: () => () => h('div', { class: 'folder-stub' }),
};

/** The `GET /bots/{id}` detail body, in the shape BotResource actually returns. */
function botDetail(
  id: string,
  name: string,
  visual: { enabled: boolean; canonical_file_id: string | null } | null,
  status: 'active' | 'inactive' = 'active',
) {
  return { data: { id, name, status, visual } };
}

/** The `GET /bots` page body (BotListResource rows) the picker's dropdown loads. */
const BOT_PAGE = {
  data: [
    { id: BOT_A, name: 'Marketing Maven', status: 'active' },
    { id: BOT_B, name: 'Support Sam', status: 'inactive' },
  ],
  meta: { next_cursor: null },
};

/** Route the two bot reads the field can make; anything else is an explicit test failure. */
function stubBots(detail: Record<string, unknown>): void {
  apiMock.get.mockImplementation(async (url: string) => {
    if (url.startsWith('/bots?') || url === '/bots') return BOT_PAGE;
    if (url.startsWith('/bots/')) return detail;
    throw new Error(`unexpected GET ${url}`);
  });
}

/** Every `/bots/<id>` detail URL requested so far (the list page is not one). */
function detailCalls(): string[] {
  return apiMock.get.mock.calls
    .map((c) => String(c[0]))
    .filter((url) => url.startsWith('/bots/'));
}

function gcStep(config: Record<string, unknown> = {}): StepDraft {
  return reactive({
    uid: 'g1',
    type: 'generate_content',
    key: 'content',
    config: { ...emptyStepConfig('generate_content'), ...config },
  });
}

function mountCard(step: StepDraft, overrides: Record<string, unknown> = {}) {
  vi.spyOn(useTemplatesStore(), 'fetchTemplate').mockResolvedValue(TEMPLATE);

  return mount(WorkflowStepCard, {
    attachTo: document.body,
    global: { stubs: { MarkdownEditor: MarkdownEditorStub, FolderPickerPanel: FolderPickerStub } },
    props: {
      step,
      index: 0,
      total: 1,
      catalog: CATALOG,
      triggerType: null,
      steps: [step],
      position: 0,
      errors: {},
      duplicateKey: false,
      expanded: true,
      ...overrides,
    },
  });
}

/** Let the template fetch + the directory lookup (and its possible forced re-read) settle. */
async function settle(): Promise<void> {
  for (let i = 0; i < 6; i += 1) {
    await nextTick();
    await Promise.resolve();
  }
  await nextTick();
}

const BRINGS = en.generator.sessions.delegate.brings;

describe('WorkflowStepCard — generate_content AUTHOR: the wire', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    installBrowserMocks();
    vi.clearAllMocks();
    stubBots(botDetail(BOT_A, 'Marketing Maven', { enabled: true, canonical_file_id: 'file-1' }));
  });
  afterEach(() => {
    restoreBrowserMocks();
    vi.restoreAllMocks();
  });

  it('sends NO bot_id key and asks nothing about bots when no author is chosen', async () => {
    const step = gcStep({ template_id: 'tpl-1' });
    const wrapper = mountCard(step);
    await settle();

    // The step is byte-identical to one from before this feature existed…
    expect(buildStepConfig(step)).toEqual({ template_id: 'tpl-1' });
    // …nothing was looked up…
    expect(detailCalls()).toEqual([]);
    // …and the field says nothing about what a bot would bring.
    expect(wrapper.text()).not.toContain(BRINGS.voice);
    wrapper.unmount();
  });

  it('picking a bot writes config.bot_id and emits it on the wire', async () => {
    const step = gcStep({ template_id: 'tpl-1' });
    const wrapper = mountCard(step);
    await settle();

    const picker = wrapper.findComponent(BotSelect);
    await picker.get('[role="combobox"]').trigger('click');
    await settle();

    const options = document.body.querySelectorAll<HTMLElement>('[role="option"]');
    expect(options.length).toBe(2);
    options[0].click();
    await settle();

    expect(step.config.bot_id).toBe(BOT_A);
    expect(buildStepConfig(step)).toEqual({ template_id: 'tpl-1', bot_id: BOT_A });
    // ONE detail read for the picked bot — the dropdown's own paging never multiplies it.
    expect(detailCalls()).toEqual([`/bots/${BOT_A}`]);
    wrapper.unmount();
  });

  it('clearing the picker drops the key entirely (never null / "")', async () => {
    const step = gcStep({ template_id: 'tpl-1', bot_id: BOT_A });
    const wrapper = mountCard(step);
    await settle();

    await wrapper
      .findComponent(BotSelect)
      .get('button[aria-label="Clear selection"]')
      .trigger('click');
    await settle();

    expect(step.config.bot_id).toBeNull();
    const out = buildStepConfig(step);
    expect('bot_id' in out).toBe(false);
    expect(out).toEqual({ template_id: 'tpl-1' });
    // …and the field stops promising anything.
    expect(wrapper.text()).not.toContain(BRINGS.voice);
    wrapper.unmount();
  });

  it('offers EVERY bot — a voice is authorial config, not an execution capability', async () => {
    // `can_execute_tasks=1` would hide inactive / non-executing bots, which the run-time author
    // resolver explicitly accepts ("BOT STATUS IS NOT A FILTER"). Sending it here would refuse
    // authors the server would happily use.
    const step = gcStep({ template_id: 'tpl-1' });
    const wrapper = mountCard(step);
    await settle();

    await wrapper.findComponent(BotSelect).get('[role="combobox"]').trigger('click');
    await settle();

    const listCalls = apiMock.get.mock.calls
      .map((c) => String(c[0]))
      .filter((url) => url === '/bots' || url.startsWith('/bots?'));
    expect(listCalls.length).toBeGreaterThan(0);
    expect(listCalls.every((url) => !url.includes('can_execute_tasks'))).toBe(true);
    // The inactive bot is offered, with its status.
    expect(document.body.textContent).toContain('Support Sam');
    wrapper.unmount();
  });

  it('surfaces the server 422 for the author on the field itself', async () => {
    const message = 'The selected bot is not available in this workspace.';
    const step = gcStep({ template_id: 'tpl-1', bot_id: BOT_A });
    const wrapper = mountCard(step, { errors: { 'config.bot_id': message } });
    await settle();

    expect(wrapper.text()).toContain(message);
    wrapper.unmount();
  });
});

describe('WorkflowStepCard — generate_content AUTHOR: hydration', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    installBrowserMocks();
    vi.clearAllMocks();
  });
  afterEach(() => {
    restoreBrowserMocks();
    vi.restoreAllMocks();
  });

  it('renders a SAVED author by NAME (the wire carries only an id), resolving it once', async () => {
    stubBots(botDetail(BOT_B, 'Support Sam', null, 'inactive'));
    const step = gcStep({ template_id: 'tpl-1', bot_id: BOT_B });
    const wrapper = mountCard(step);
    await settle();

    const picker = wrapper.findComponent(BotSelect);
    expect(picker.text()).toContain('Support Sam');
    expect(picker.text()).not.toContain(BOT_B);
    expect(detailCalls()).toEqual([`/bots/${BOT_B}`]);
    wrapper.unmount();
  });

  it('never falls back to the raw id while the lookup is in flight', async () => {
    let release: (body: unknown) => void = () => {};
    apiMock.get.mockImplementation((url: string) => {
      if (url.startsWith('/bots/')) return new Promise((resolve) => { release = resolve; });
      return Promise.resolve(BOT_PAGE);
    });

    const step = gcStep({ template_id: 'tpl-1', bot_id: BOT_B });
    const wrapper = mountCard(step);
    await settle();

    const picker = wrapper.findComponent(BotSelect);
    expect(picker.text()).not.toContain(BOT_B);
    expect(picker.text()).toContain(en.workflows.step.generate_content.botUnknownName);

    release(botDetail(BOT_B, 'Support Sam', null, 'inactive'));
    await settle();

    expect(picker.text()).toContain('Support Sam');
    wrapper.unmount();
  });

  it('learns the visual facts even when the id was only PRIMED elsewhere (name-only cache)', async () => {
    // The ai-text author panel primes {id,name,status} from a picker page — which knows nothing
    // about the visual module. Trusting that entry would silently drop the likeness badge.
    stubBots(botDetail(BOT_A, 'Marketing Maven', { enabled: true, canonical_file_id: 'file-1' }));
    useBotDirectoryStore().prime({ id: BOT_A, name: 'Marketing Maven', status: 'active' });

    const step = gcStep({ template_id: 'tpl-1', bot_id: BOT_A });
    const wrapper = mountCard(step);
    await settle();

    expect(detailCalls()).toEqual([`/bots/${BOT_A}`]);
    expect(wrapper.text()).toContain(BRINGS.likeness);
    wrapper.unmount();
  });
});

describe('WorkflowStepCard — generate_content AUTHOR: what the bot brings', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    installBrowserMocks();
    vi.clearAllMocks();
  });
  afterEach(() => {
    restoreBrowserMocks();
    vi.restoreAllMocks();
  });

  async function mountWithVisual(
    visual: { enabled: boolean; canonical_file_id: string | null } | null,
  ) {
    stubBots(botDetail(BOT_A, 'Marketing Maven', visual));
    const step = gcStep({ template_id: 'tpl-1', bot_id: BOT_A });
    const wrapper = mountCard(step);
    await settle();
    return wrapper;
  }

  it('module ON + an approved image → the VOICE and the LIKENESS, with no caveat', async () => {
    const wrapper = await mountWithVisual({ enabled: true, canonical_file_id: 'file-1' });

    expect(wrapper.text()).toContain(BRINGS.voice);
    expect(wrapper.text()).toContain(BRINGS.likeness);
    expect(wrapper.text()).not.toContain(BRINGS.noLikeness);
    expect(wrapper.text()).not.toContain(BRINGS.likenessOff);
    wrapper.unmount();
  });

  it('module ON but NO approved image → the voice only, and says the images have no character', async () => {
    const wrapper = await mountWithVisual({ enabled: true, canonical_file_id: null });

    expect(wrapper.text()).toContain(BRINGS.voice);
    expect(wrapper.text()).not.toContain(BRINGS.likeness);
    expect(wrapper.text()).toContain(BRINGS.noLikeness);
    wrapper.unmount();
  });

  it('an approved image but the module OFF → the voice only, and names the switched-off module', async () => {
    const wrapper = await mountWithVisual({ enabled: false, canonical_file_id: 'file-1' });

    expect(wrapper.text()).toContain(BRINGS.voice);
    expect(wrapper.text()).not.toContain(BRINGS.likeness);
    expect(wrapper.text()).toContain(BRINGS.likenessOff);
    expect(wrapper.text()).not.toContain(BRINGS.noLikeness);
    wrapper.unmount();
  });

  it('no visual module at all → the voice only, with the no-likeness caveat', async () => {
    const wrapper = await mountWithVisual(null);

    expect(wrapper.text()).toContain(BRINGS.voice);
    expect(wrapper.text()).not.toContain(BRINGS.likeness);
    expect(wrapper.text()).toContain(BRINGS.noLikeness);
    wrapper.unmount();
  });

  it('claims NOTHING about the likeness while the facts are still unknown', async () => {
    // A failed / pending lookup must not be rendered as "this bot has no likeness" — the VOICE is
    // true of every bot, the rest has to be known before it is said.
    apiMock.get.mockRejectedValue(new Error('offline'));
    const step = gcStep({ template_id: 'tpl-1', bot_id: BOT_A });
    const wrapper = mountCard(step);
    await settle();

    expect(wrapper.text()).toContain(BRINGS.voice);
    expect(wrapper.text()).not.toContain(BRINGS.likeness);
    expect(wrapper.text()).not.toContain(BRINGS.noLikeness);
    expect(wrapper.text()).not.toContain(BRINGS.likenessOff);
    wrapper.unmount();
  });
});

describe('WorkflowStepCard — generate_content AUTHOR: when the bot no longer resolves', () => {
  // The directory returns THREE different verdicts and the field must read differently for each:
  //   • resolved  → the promise ("this bot brings its voice", above),
  //   • missing   → a DEFINITIVE 404/403: the field is BROKEN. Saving is refused (the request
  //                 delegates the id to the run-time author resolver) and every run would fail,
  //                 so the "voice" promise must be GONE, not merely joined by a name placeholder.
  //   • unresolved→ a network blip: we do not KNOW. The voice stays (it is true of every bot) but
  //                 the failure is stated and retryable — never silently rendered as a real name.
  beforeEach(() => {
    setActivePinia(createPinia());
    installBrowserMocks();
    vi.clearAllMocks();
  });
  afterEach(() => {
    restoreBrowserMocks();
    vi.restoreAllMocks();
  });

  /** Fail every `/bots/{id}` detail read with `status`; the list page still answers. */
  function stubDetailFailure(error: unknown): void {
    apiMock.get.mockImplementation((url: string) => {
      if (url.startsWith('/bots?') || url === '/bots') return Promise.resolve(BOT_PAGE);
      if (url.startsWith('/bots/')) return Promise.reject(error);
      throw new Error(`unexpected GET ${url}`);
    });
  }

  it('a DELETED author (404) stops promising a voice and says the field is broken', async () => {
    stubDetailFailure({ response: { status: 404 } });
    const step = gcStep({ template_id: 'tpl-1', bot_id: BOT_A });
    const wrapper = mountCard(step);
    await settle();

    // The promise is withdrawn — a bot that does not exist brings nothing.
    expect(wrapper.text()).not.toContain(BRINGS.voice);
    expect(wrapper.text()).not.toContain(BRINGS.likeness);
    // …and the field says what is actually wrong, in the danger register.
    expect(wrapper.text()).toContain(en.workflows.step.generate_content.botMissing);
    // The trigger carries the same verdict as a badge (words + glyph, not colour alone).
    expect(wrapper.findComponent(BotSelect).text()).toContain(en.editor.aiText.authorMissingShort);
    // A definitive verdict is asked for ONCE — no retry loop on a 404.
    expect(detailCalls()).toEqual([`/bots/${BOT_A}`]);
    wrapper.unmount();
  });

  it('a 403 (not ours) reads exactly like a deletion — both are definitive', async () => {
    stubDetailFailure({ response: { status: 403 } });
    const step = gcStep({ template_id: 'tpl-1', bot_id: BOT_A });
    const wrapper = mountCard(step);
    await settle();

    expect(wrapper.text()).not.toContain(BRINGS.voice);
    expect(wrapper.text()).toContain(en.workflows.step.generate_content.botMissing);
    wrapper.unmount();
  });

  it('an INCONCLUSIVE lookup says so and offers a retry that recovers', async () => {
    let attempt = 0;
    apiMock.get.mockImplementation((url: string) => {
      if (url.startsWith('/bots?') || url === '/bots') return Promise.resolve(BOT_PAGE);
      if (url.startsWith('/bots/')) {
        attempt += 1;
        return attempt === 1
          ? Promise.reject(new Error('offline'))
          : Promise.resolve(botDetail(BOT_A, 'Marketing Maven', { enabled: true, canonical_file_id: 'file-1' }));
      }
      throw new Error(`unexpected GET ${url}`);
    });

    const step = gcStep({ template_id: 'tpl-1', bot_id: BOT_A });
    const wrapper = mountCard(step);
    await settle();

    // NOT "deleted": the voice stays (true of every bot), but the failure is named…
    expect(wrapper.text()).toContain(BRINGS.voice);
    expect(wrapper.text()).not.toContain(en.workflows.step.generate_content.botMissing);
    expect(wrapper.text()).toContain(en.editor.aiText.authorCheckFailed);
    // …and the name is NOT faked — neither the raw uuid nor a resolved-looking label.
    expect(wrapper.findComponent(BotSelect).text()).not.toContain(BOT_A);

    const retry = wrapper
      .findAll('button')
      .find((b) => b.text() === en.editor.aiText.authorRetry);
    expect(retry, 'the check-failed line must offer a retry').toBeTruthy();
    await retry!.trigger('click');
    await settle();

    expect(wrapper.text()).not.toContain(en.editor.aiText.authorCheckFailed);
    expect(wrapper.findComponent(BotSelect).text()).toContain('Marketing Maven');
    expect(wrapper.text()).toContain(BRINGS.likeness);
    expect(detailCalls()).toEqual([`/bots/${BOT_A}`, `/bots/${BOT_A}`]);
    wrapper.unmount();
  });
});
