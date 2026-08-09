// @vitest-environment happy-dom
// BotKnowledgeBindingPanel.spec — which knowledge base a bot reads, and how.
//
// What is pinned here is the part that decides whether the bot knows anything at all:
//   • the PUT body carries both the base and the mode (a bind with the wrong mode is a bot that
//     silently truncates its own knowledge);
//   • the binding is a SERVER MIRROR — the panel re-seeds from the prop and never authors it;
//   • unbinding CONFIRMS first, because it changes what the bot knows on its next run;
//   • both mutations hand the fresh bot back, which is how the built-in section learns it went
//     inactive.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { h as vh, nextTick } from 'vue';
import { setLocale, translate } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const h = vi.hoisted(() => ({
  store: { bindKnowledgeBase: vi.fn(), unbindKnowledgeBase: vi.fn() },
  toast: { success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() },
  confirm: vi.fn(),
}));

vi.mock('../../../app/stores/bots', () => ({ useBotsStore: () => h.store }));
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => h.toast }));
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => h.confirm }));
// The picker owns async cursor pages + a combobox; this spec is about the BINDING, so it is
// replaced with a plain input that drives the same v-model.
vi.mock('../../../ui/forms/KnowledgeBaseSelect.vue', () => ({
  default: {
    name: 'KnowledgeBaseSelect',
    props: ['modelValue', 'seed', 'disabled', 'placeholder', 'ariaLabel'],
    emits: ['update:modelValue', 'update:selected'],
    // A render function, not a runtime `template`: the runtime compiler is the JS one, so TS
    // syntax inside a template string does not parse.
    setup(props: Record<string, unknown>, { emit }: { emit: (e: string, v: unknown) => void }) {
      return () =>
        vh('input', {
          'data-base-select': '',
          value: props.modelValue ?? '',
          onInput: (event: Event) => emit('update:modelValue', (event.target as HTMLInputElement).value),
        });
    },
  },
}));

import BotKnowledgeBindingPanel from '../BotKnowledgeBindingPanel.vue';

const t = translate;

function bot(overrides: Record<string, unknown> = {}) {
  return { id: 'bot-1', name: 'Ola', knowledge_binding: null, visual: null, ...overrides };
}

function mountPanel(props: Record<string, unknown> = {}) {
  return mount(BotKnowledgeBindingPanel, {
    attachTo: document.body,
    props: { botId: 'bot-1', binding: null, ...props },
  });
}

function buttonByText(wrapper: ReturnType<typeof mountPanel>, label: string) {
  return wrapper.findAll('button').find((b) => b.text() === label);
}

describe('BotKnowledgeBindingPanel', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('pl');
    vi.clearAllMocks();
    h.confirm.mockResolvedValue(true);
  });

  afterEach(() => {
    restoreBrowserMocks();
    document.body.innerHTML = '';
  });

  it('binds with BOTH the base and the mode, and hands the fresh bot to the host', async () => {
    const fresh = bot({ knowledge_binding: { knowledge_base_id: 'base-7', mode: 'auto' } });
    h.store.bindKnowledgeBase.mockResolvedValue(fresh);

    const wrapper = mountPanel();
    await wrapper.find('[data-base-select]').setValue('base-7');
    await nextTick();

    await buttonByText(wrapper, t('bots.editor.knowledge.binding.bind'))!.trigger('click');
    await nextTick();

    expect(h.store.bindKnowledgeBase).toHaveBeenCalledWith('bot-1', {
      knowledge_base_id: 'base-7',
      mode: 'auto', // the recommended default, not an empty choice
    });
    expect(wrapper.emitted('sync')?.[0]?.[0]).toBe(fresh);
    expect(h.toast.success).toHaveBeenCalledWith(t('bots.editor.knowledge.binding.saved'));
    wrapper.unmount();
  });

  it('sends the mode the user picked', async () => {
    h.store.bindKnowledgeBase.mockResolvedValue(bot());

    const wrapper = mountPanel();
    await wrapper.find('[data-base-select]').setValue('base-7');

    // The three modes are real, distinct choices — pick the one that never calls the AI.
    // Selected by ROLE and position (auto · inline · rag), not by label text: `auto`'s own
    // description contains the word "inline" uses, so a text match would hit the wrong card.
    const modes = wrapper.findAll('[role="radio"]');
    expect(modes).toHaveLength(3);
    await modes[1].trigger('click');
    await nextTick();
    expect(modes[1].attributes('aria-checked')).toBe('true');

    await buttonByText(wrapper, t('bots.editor.knowledge.binding.bind'))!.trigger('click');
    await nextTick();

    expect(h.store.bindKnowledgeBase).toHaveBeenCalledWith(
      'bot-1',
      expect.objectContaining({ mode: 'inline' }),
    );
    wrapper.unmount();
  });

  it('CONFIRMS before unbinding, then unbinds and syncs', async () => {
    const fresh = bot();
    h.store.unbindKnowledgeBase.mockResolvedValue(fresh);

    const wrapper = mountPanel({ binding: { knowledge_base_id: 'base-7', mode: 'rag' } });
    await nextTick();

    await buttonByText(wrapper, t('bots.editor.knowledge.binding.unbind'))!.trigger('click');
    await nextTick();

    expect(h.confirm).toHaveBeenCalled();
    expect(h.store.unbindKnowledgeBase).toHaveBeenCalledWith('bot-1');
    expect(wrapper.emitted('sync')?.[0]?.[0]).toBe(fresh);
    wrapper.unmount();
  });

  it('does NOT unbind when the confirmation is declined', async () => {
    h.confirm.mockResolvedValue(false);

    const wrapper = mountPanel({ binding: { knowledge_base_id: 'base-7', mode: 'auto' } });
    await buttonByText(wrapper, t('bots.editor.knowledge.binding.unbind'))!.trigger('click');
    await nextTick();

    expect(h.store.unbindKnowledgeBase).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  it('says whether a base is the ACTIVE source, in words', async () => {
    const unbound = mountPanel();
    expect(unbound.text()).toContain(t('bots.editor.knowledge.binding.inactive'));
    unbound.unmount();

    const bound = mountPanel({ binding: { knowledge_base_id: 'base-7', mode: 'auto' } });
    expect(bound.text()).toContain(t('bots.editor.knowledge.binding.active'));
    bound.unmount();
  });

  it('re-seeds the draft when the SERVER binding changes underneath (e.g. a migration)', async () => {
    const wrapper = mountPanel();
    expect((wrapper.find('[data-base-select]').element as HTMLInputElement).value).toBe('');

    await wrapper.setProps({ binding: { knowledge_base_id: 'base-9', mode: 'rag' } });
    await nextTick();

    expect((wrapper.find('[data-base-select]').element as HTMLInputElement).value).toBe('base-9');
    // ...and the panel now offers the change/unbind pair rather than a fresh bind.
    expect(buttonByText(wrapper, t('bots.editor.knowledge.binding.rebind'))).toBeTruthy();
    wrapper.unmount();
  });

  it('cannot bind a bot that was never saved, and says why', () => {
    const wrapper = mountPanel({ botId: null });

    expect(wrapper.text()).toContain(t('bots.editor.knowledge.binding.saveFirst'));
    expect(wrapper.find('[data-base-select]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('offers the migration route only when there is something to migrate and no base bound', async () => {
    const withEntries = mountPanel({ builtInCount: 3 });
    const migrate = buttonByText(withEntries, t('bots.editor.knowledge.binding.migrate'));
    expect(migrate).toBeTruthy();
    await migrate!.trigger('click');
    expect(withEntries.emitted('migrate')).toBeTruthy();
    withEntries.unmount();

    const alreadyBound = mountPanel({ builtInCount: 3, binding: { knowledge_base_id: 'b', mode: 'auto' } });
    expect(buttonByText(alreadyBound, t('bots.editor.knowledge.binding.migrate'))).toBeFalsy();
    alreadyBound.unmount();

    const nothingToMove = mountPanel({ builtInCount: 0 });
    expect(buttonByText(nothingToMove, t('bots.editor.knowledge.binding.migrate'))).toBeFalsy();
    nothingToMove.unmount();
  });

  it('reports a failed bind without pretending it worked', async () => {
    h.store.bindKnowledgeBase.mockRejectedValue(new Error('nope'));

    const wrapper = mountPanel();
    await wrapper.find('[data-base-select]').setValue('base-7');
    await buttonByText(wrapper, t('bots.editor.knowledge.binding.bind'))!.trigger('click');
    await nextTick();

    expect(h.toast.danger).toHaveBeenCalledWith(t('bots.editor.knowledge.binding.saveError'));
    expect(wrapper.emitted('sync')).toBeFalsy();
    wrapper.unmount();
  });
});
