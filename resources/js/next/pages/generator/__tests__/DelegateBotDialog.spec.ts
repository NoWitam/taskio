// @vitest-environment happy-dom
// DelegateBotDialog.spec — the "Delegate to bot" picker (R2 sub-stage 3). Asserts it lists the workspace
// bots, that the "Auto-generate after filling" toggle DEFAULTS OFF (the gate-before-spend), that the
// click-time FILL MODE defaults to the non-destructive 'gaps' and re-asserts it on every open, that
// confirming emits { botId, autoGenerate, fillMode }, and that with no bots an empty state routes the human
// to Bots. The bots store + router are mocked; Modal is stubbed to render its slots inline (skip the
// teleport/focus-trap).
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { h as vh, type VNode } from 'vue';
import { setLocale } from '../../../app/i18n';
import { en } from '../../../app/i18n/en';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const hoisted = vi.hoisted(() => ({
  store: {
    items: [] as Array<Record<string, unknown>>,
    loading: false,
    loadingMore: false,
    errored: false,
    fetchBots: vi.fn(),
  },
  push: vi.fn(),
}));

vi.mock('../../../app/stores/bots', () => ({ useBotsStore: () => hoisted.store }));
vi.mock('vue-router', () => ({ useRouter: () => ({ push: hoisted.push }) }));

import DelegateBotDialog from '../session/DelegateBotDialog.vue';

const ModalStub = {
  name: 'Modal',
  props: ['open'],
  emits: ['update:open'],
  setup(_p: unknown, { slots }: { slots: Record<string, ((arg?: unknown) => VNode[]) | undefined> }) {
    return () =>
      vh('div', { class: 'modal' }, [
        slots.title ? slots.title() : null,
        slots.description ? slots.description() : null,
        slots.default ? slots.default() : null,
        slots.footer ? slots.footer({ close: () => {} }) : null,
      ]);
  },
};

function bot(overrides: Record<string, unknown> = {}) {
  return {
    id: 'b1',
    name: 'Copy Bot',
    status: 'active',
    description: 'Witty short copy',
    icon: null,
    has_text_module: true,
    task_execution_enabled: false,
    visual_enabled: false,
    visual_has_image: false,
    is_owner: true,
    created_at: null,
    ...overrides,
  };
}

function mountDialog() {
  return mount(DelegateBotDialog, {
    props: { open: true },
    global: { stubs: { Modal: ModalStub } },
    attachTo: document.body,
  });
}

describe('DelegateBotDialog', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    hoisted.store.items = [];
    hoisted.store.loading = false;
    hoisted.store.errored = false;
    hoisted.store.fetchBots.mockClear();
    hoisted.push.mockClear();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('lists the workspace bots with name + status', () => {
    hoisted.store.items = [bot(), bot({ id: 'b2', name: 'Formal Bot', status: 'inactive' })];
    const wrapper = mountDialog();
    const radios = wrapper.findAll('button[role="radio"]');
    expect(radios).toHaveLength(2);
    expect(wrapper.text()).toContain('Copy Bot');
    expect(wrapper.text()).toContain('Formal Bot');
    expect(wrapper.text()).toContain('Active');
    wrapper.unmount();
  });

  it('the auto-generate toggle DEFAULTS OFF (gate-before-spend)', () => {
    hoisted.store.items = [bot()];
    const wrapper = mountDialog();
    const toggle = wrapper.find('[role="switch"]');
    expect(toggle.attributes('aria-checked')).toBe('false');
    wrapper.unmount();
  });

  it('the fill mode DEFAULTS to "gaps" (the non-destructive option) and shows both options', () => {
    hoisted.store.items = [bot()];
    const wrapper = mountDialog();

    expect(wrapper.text()).toContain('Fill only the empty inputs');
    expect(wrapper.text()).toContain('Propose everything fresh');
    // The destructive option must say that undo brings the human's values back.
    expect(wrapper.text()).toContain('Undoing the delegation restores your values.');

    const gaps = wrapper.find('input[data-radio-value="gaps"]').element as HTMLInputElement;
    const fresh = wrapper.find('input[data-radio-value="fresh"]').element as HTMLInputElement;
    expect(gaps.checked).toBe(true);
    expect(fresh.checked).toBe(false);
    wrapper.unmount();
  });

  it('re-asserts the "gaps" default on every open (a prior "fresh" pick never leaks forward)', async () => {
    hoisted.store.items = [bot()];
    const wrapper = mountDialog();

    await wrapper.find('input[data-radio-value="fresh"]').trigger('change');
    expect((wrapper.find('input[data-radio-value="fresh"]').element as HTMLInputElement).checked).toBe(true);

    // Close, then reopen — the dialog must reset to the non-destructive mode.
    await wrapper.setProps({ open: false });
    await wrapper.setProps({ open: true });

    expect((wrapper.find('input[data-radio-value="gaps"]').element as HTMLInputElement).checked).toBe(true);
    expect((wrapper.find('input[data-radio-value="fresh"]').element as HTMLInputElement).checked).toBe(false);
    wrapper.unmount();
  });

  it('confirm is disabled until a bot is selected, then emits { botId, autoGenerate:false, fillMode:"gaps" } by default', async () => {
    hoisted.store.items = [bot()];
    const wrapper = mountDialog();

    const confirmBtn = wrapper.findAll('button').find((b) => b.text() === 'Delegate')!;
    expect(confirmBtn.attributes('disabled')).toBeDefined();

    await wrapper.find('button[role="radio"]').trigger('click');
    expect(confirmBtn.attributes('disabled')).toBeUndefined();

    await confirmBtn.trigger('click');
    expect(wrapper.emitted('confirm')?.[0]).toEqual([
      { botId: 'b1', autoGenerate: false, fillMode: 'gaps' },
    ]);
    wrapper.unmount();
  });

  it('picking "Propose everything fresh" carries fillMode:"fresh" into the confirm payload', async () => {
    hoisted.store.items = [bot()];
    const wrapper = mountDialog();

    await wrapper.find('button[role="radio"]').trigger('click');
    await wrapper.find('input[data-radio-value="fresh"]').trigger('change');
    await wrapper.findAll('button').find((b) => b.text() === 'Delegate')!.trigger('click');

    expect(wrapper.emitted('confirm')?.[0]).toEqual([
      { botId: 'b1', autoGenerate: false, fillMode: 'fresh' },
    ]);
    wrapper.unmount();
  });

  it('ticking the toggle carries autoGenerate:true into the confirm payload', async () => {
    hoisted.store.items = [bot()];
    const wrapper = mountDialog();

    await wrapper.find('button[role="radio"]').trigger('click');
    await wrapper.find('[role="switch"]').trigger('click');
    await wrapper.findAll('button').find((b) => b.text() === 'Delegate')!.trigger('click');

    expect(wrapper.emitted('confirm')?.[0]).toEqual([
      { botId: 'b1', autoGenerate: true, fillMode: 'gaps' },
    ]);
    wrapper.unmount();
  });

  // --- What the bot BRINGS (R2 sub-stage 3 · appearance) --------------------
  // Delegating hands over two things. A user who only knows about the voice is startled when the
  // pictures start showing a person — so every row states both contributions.
  it('every row says the bot brings its VOICE; only a ready appearance also brings the LIKENESS', () => {
    hoisted.store.items = [
      bot({ id: 'ready', name: 'Ready Bot', visual_enabled: true, visual_has_image: true }),
      bot({ id: 'plain', name: 'Plain Bot', visual_enabled: false, visual_has_image: false }),
    ];
    const wrapper = mountDialog();
    const rows = wrapper.findAll('button[role="radio"]');

    expect(rows[0].text()).toContain(en.generator.sessions.delegate.brings.voice);
    expect(rows[0].text()).toContain(en.generator.sessions.delegate.brings.likeness);
    expect(rows[1].text()).toContain(en.generator.sessions.delegate.brings.voice);
    expect(rows[1].text()).not.toContain(en.generator.sessions.delegate.brings.likeness);
    wrapper.unmount();
  });

  it('keeps the half-states OUT of the rows and states them once, for the SELECTED bot', async () => {
    hoisted.store.items = [
      bot({ id: 'noimg', visual_enabled: true, visual_has_image: false }),
      bot({ id: 'off', visual_enabled: false, visual_has_image: true }),
    ];
    const wrapper = mountDialog();

    // Nothing shouted before a choice is made.
    expect(wrapper.text()).not.toContain(en.generator.sessions.delegate.brings.noLikeness);

    await wrapper.findAll('button[role="radio"]')[0].trigger('click');
    expect(wrapper.text()).toContain(en.generator.sessions.delegate.brings.noLikeness);

    await wrapper.findAll('button[role="radio"]')[1].trigger('click');
    expect(wrapper.text()).toContain(en.generator.sessions.delegate.brings.likenessOff);
    expect(wrapper.text()).not.toContain(en.generator.sessions.delegate.brings.noLikeness);
    wrapper.unmount();
  });

  it('says nothing extra when the selected bot brings everything', async () => {
    hoisted.store.items = [bot({ visual_enabled: true, visual_has_image: true })];
    const wrapper = mountDialog();
    await wrapper.find('button[role="radio"]').trigger('click');

    expect(wrapper.text()).not.toContain(en.generator.sessions.delegate.brings.noLikeness);
    expect(wrapper.text()).not.toContain(en.generator.sessions.delegate.brings.likenessOff);
    wrapper.unmount();
  });

  it('with no bots shows the empty state and routes to Bots', async () => {
    hoisted.store.items = [];
    const wrapper = mountDialog();
    expect(wrapper.text()).toContain('No bots yet');

    const goBtn = wrapper.findAll('button').find((b) => b.text() === 'Go to Bots')!;
    await goBtn.trigger('click');
    expect(hoisted.push).toHaveBeenCalledWith({ name: 'next.bots' });
    wrapper.unmount();
  });
});
