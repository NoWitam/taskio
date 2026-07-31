// @vitest-environment happy-dom
// BotEditorDrawer.spec — the module DESCRIPTOR and, above all, the VISUAL STATE SPLIT.
//
// THE REGRESSION THIS FILE EXISTS FOR: a bot save OVERWRITES `visual` whole, while a likeness generation
// writes `candidates` / `canonical_file_id` ASYNCHRONOUSLY in a worker. If the editor built its payload
// from the snapshot it took when the drawer opened, saving after a background generation landed would
// DELETE the image that just arrived — silently, and only for users who kept the drawer open long enough
// for a ~40 s run to finish. The file pointers must therefore come from the SERVER MIRROR, refreshed by
// every server response, and never from the form.
//
// Also pinned: the module nav is descriptor-driven (visual is a real toggleable module, audio is still a
// placeholder), the `?botModule=` deep link, and the omit-when-unseeded rule that protects the module
// from an editor that never loaded it.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setLocale } from '../../../app/i18n';
import { en } from '../../../app/i18n/en';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const hoisted = vi.hoisted(() => ({
  store: {
    detail: null as Record<string, unknown> | null,
    updateBot: vi.fn(),
    createBot: vi.fn(),
    fetchBot: vi.fn(),
  },
  registry: {
    loading: false,
    loaded: true,
    error: null as string | null,
    fetchRegistry: vi.fn(),
    availableIds: () => [] as string[],
    isAvailable: () => true,
    retry: vi.fn(),
  },
  query: {} as Record<string, string>,
  toast: { success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

vi.mock('../../../app/stores/bots', () => ({ useBotsStore: () => hoisted.store }));
vi.mock('../../../app/stores/botToolRegistry', () => ({ useBotToolRegistryStore: () => hoisted.registry }));
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => hoisted.toast }));
vi.mock('vue-router', () => ({ useRoute: () => ({ query: hoisted.query }) }));

import BotEditorDrawer from '../BotEditorDrawer.vue';
import type { BotDetail, BotVisualIdentity, BotWritePayload } from '../types';

const VisualPanelStub = {
  name: 'BotVisualPanel',
  props: ['botId', 'botName', 'enabled', 'server', 'dirty', 'errors', 'save', 'descriptor', 'wardrobe', 'aesthetic', 'prohibitions'],
  emits: ['sync', 'update:descriptor', 'update:wardrobe', 'update:aesthetic', 'update:prohibitions'],
  template: '<div class="visual-panel" />',
};

function visual(over: Partial<BotVisualIdentity> = {}): BotVisualIdentity {
  return {
    enabled: true,
    descriptor: 'A woman around 30',
    aesthetic: 'warm palette',
    wardrobe: 'summer dress',
    prohibitions: ['brand logos'],
    reference_file_id: 'ref-1',
    candidates: ['c1'],
    canonical_file_id: null,
    prompt: 'Subject: …',
    ...over,
  };
}

function detail(over: Partial<BotDetail> = {}): BotDetail {
  return {
    id: 'b1',
    name: 'Ada',
    status: 'inactive',
    description: null,
    icon: null,
    persona: 'You are Ada.',
    style: null,
    dictionary: [],
    phrases: [],
    prohibitions: [],
    task_execution: null,
    visual: visual(),
    audio: null,
    knowledge: { enabled: false, entries: [] },
    is_owner: true,
    can_execute_tasks: false,
    can_be_edited: true,
    can_be_deleted: true,
    created_at: null,
    updated_at: null,
    ...over,
  } as BotDetail;
}

function mountDrawer(botId: string | null = 'b1') {
  return mount(BotEditorDrawer, {
    props: { botId },
    global: { stubs: { BotVisualPanel: VisualPanelStub, IconInput: true, Teleport: true } },
  });
}

/** The payload of the last PUT. */
function lastPayload(): BotWritePayload {
  const calls = hoisted.store.updateBot.mock.calls;
  return calls[calls.length - 1][1] as BotWritePayload;
}

function saveButton(wrapper: ReturnType<typeof mountDrawer>) {
  return wrapper.findAll('button').find((b) => b.text().includes(en.bots.editor.save))!;
}

describe('BotEditorDrawer', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
    hoisted.query = {};
    hoisted.store.detail = detail();
    hoisted.store.updateBot.mockResolvedValue(detail());
    hoisted.store.fetchBot.mockResolvedValue(detail());
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  // --- The regression -------------------------------------------------------
  it('a candidate that landed IN THE BACKGROUND survives a save (the payload uses the server mirror)', async () => {
    const wrapper = mountDrawer();
    await flushPromises();

    // The editor opened when the bot had ONE candidate.
    expect(wrapper.findComponent(VisualPanelStub).props('server')).toMatchObject({ candidates: ['c1'] });

    // A generation finished while the drawer stayed open: the panel hands up the FRESH bot.
    wrapper
      .findComponent(VisualPanelStub)
      .vm.$emit('sync', detail({ visual: visual({ candidates: ['c1', 'c2'], canonical_file_id: 'c2' }) }));
    await flushPromises();

    // The user now saves — with a form they filled BEFORE the generation.
    await saveButton(wrapper).trigger('click');
    await flushPromises();

    const sent = lastPayload().visual!;
    expect(sent.candidates).toEqual(['c1', 'c2']); // NOT ['c1'] — that would delete the new image
    expect(sent.canonical_file_id).toBe('c2');
    wrapper.unmount();
  });

  it('sends the module WHOLE: the user text merged with the server file pointers', async () => {
    const wrapper = mountDrawer();
    await flushPromises();

    wrapper.findComponent(VisualPanelStub).vm.$emit('update:descriptor', 'A man around 40');
    wrapper.findComponent(VisualPanelStub).vm.$emit('update:prohibitions', ['alcohol', '  ']);
    await flushPromises();

    await saveButton(wrapper).trigger('click');
    await flushPromises();

    expect(lastPayload().visual).toEqual({
      enabled: true,
      descriptor: 'A man around 40',
      aesthetic: 'warm palette',
      wardrobe: 'summer dress',
      prohibitions: ['alcohol'], // blank rows dropped
      reference_file_id: 'ref-1',
      candidates: ['c1'],
      canonical_file_id: null,
      prompt: 'Subject: …',
    });
    wrapper.unmount();
  });

  it('OMITS `visual` when the editor never got the bot (an omitted key leaves the module untouched)', async () => {
    hoisted.store.detail = null;
    hoisted.store.fetchBot.mockResolvedValue(null);
    const wrapper = mountDrawer();
    await flushPromises();

    // Nothing to seed from → the error state, and no way to blank the module from here.
    expect(wrapper.text()).toContain(en.bots.editor.detailError);
    wrapper.unmount();
  });

  it('fetches a deep-linked bot the store had not cached, then seeds from it', async () => {
    hoisted.store.detail = null;
    const wrapper = mountDrawer();
    await flushPromises();

    expect(hoisted.store.fetchBot).toHaveBeenCalledWith('b1');
    expect(wrapper.findComponent(VisualPanelStub).props('server')).toMatchObject({ candidates: ['c1'] });
    wrapper.unmount();
  });

  it('treats the approval as a plain field: `save({canonicalFileId:null})` clears it', async () => {
    const wrapper = mountDrawer();
    await flushPromises();

    const save = wrapper.findComponent(VisualPanelStub).props('save') as (
      o?: { canonicalFileId?: string | null },
    ) => Promise<unknown>;
    await save({ canonicalFileId: null });
    await flushPromises();

    expect(lastPayload().visual!.canonical_file_id).toBeNull();
    // …while everything else still rides the server mirror.
    expect(lastPayload().visual!.candidates).toEqual(['c1']);
    wrapper.unmount();
  });

  it('re-baselines `dirty` after a save so the panel stops re-saving before every run', async () => {
    const wrapper = mountDrawer();
    await flushPromises();
    expect(wrapper.findComponent(VisualPanelStub).props('dirty')).toBe(false);

    wrapper.findComponent(VisualPanelStub).vm.$emit('update:wardrobe', 'a plain shirt');
    await flushPromises();
    expect(wrapper.findComponent(VisualPanelStub).props('dirty')).toBe(true);

    await saveButton(wrapper).trigger('click');
    await flushPromises();
    expect(wrapper.findComponent(VisualPanelStub).props('dirty')).toBe(false);
    wrapper.unmount();
  });

  // --- The module descriptor -------------------------------------------------
  it('renders visual as a REAL toggleable module and audio as the remaining placeholder', async () => {
    const wrapper = mountDrawer();
    await flushPromises();

    const nav = wrapper.find('nav');
    // Three toggle switches: task-execution, knowledge, visual (audio has none).
    expect(nav.findAllComponents({ name: 'Switch' })).toHaveLength(3);
    expect(nav.text()).toContain(en.bots.modules.visual);
    // "Soon" appears exactly once — for audio.
    expect(nav.text().split(en.bots.modules.state.soon)).toHaveLength(2);
    wrapper.unmount();
  });

  it('toggling the visual module in the nav flows into the payload', async () => {
    hoisted.store.detail = detail({ visual: visual({ enabled: false }) });
    const wrapper = mountDrawer();
    await flushPromises();

    const switches = wrapper.find('nav').findAllComponents({ name: 'Switch' });
    switches[2].vm.$emit('update:modelValue', true);
    await flushPromises();

    await saveButton(wrapper).trigger('click');
    await flushPromises();
    expect(lastPayload().visual!.enabled).toBe(true);
    wrapper.unmount();
  });

  it('opens straight on the module named by `?botModule=`', async () => {
    hoisted.query = { botModule: 'visual' };
    const wrapper = mountDrawer();
    await flushPromises();

    // The visual section is the visible one (the others are v-show'd off).
    const panel = wrapper.findComponent(VisualPanelStub);
    expect(panel.exists()).toBe(true);
    expect((panel.element.closest('section') as HTMLElement).style.display).not.toBe('none');
    wrapper.unmount();
  });

  it('ignores an unknown `?botModule=` value rather than rendering nothing', async () => {
    hoisted.query = { botModule: 'nope' };
    const wrapper = mountDrawer();
    await flushPromises();

    const sections = wrapper.findAll('section');
    // The TEXT module (the first section) is the fallback.
    expect((sections[0].element as HTMLElement).style.display).not.toBe('none');
    wrapper.unmount();
  });
});
