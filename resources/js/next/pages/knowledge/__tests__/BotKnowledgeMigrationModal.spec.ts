// @vitest-environment happy-dom
// BotKnowledgeMigrationModal.spec — importing a bot's built-in knowledge into a real base.
//
// The two things this screen must never get wrong:
//   1. the PREVIEW has to match what the server will do — the same entry count (blank-titled rows
//      are dropped server-side) and the same base name — or the confirmation is a guess;
//   2. a 422 has to be shown VERBATIM and IN PLACE. The message names the entries that carry
//      template syntax, i.e. it is a to-do list; a toast would take it away after four seconds.
//
// The Modal TELEPORTS to <body>, so assertions read `document.body` rather than the wrapper —
// the same shape as DiskCopyModal.spec.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale, translate } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const h = vi.hoisted(() => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
  store: { migrateKnowledge: vi.fn() },
  toast: { success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

vi.mock('../../../app/lib/api', () => ({ api: h.api }));
vi.mock('../../../app/stores/bots', () => ({ useBotsStore: () => h.store }));
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => h.toast }));

import BotKnowledgeMigrationModal from '../BotKnowledgeMigrationModal.vue';

const t = translate;

/** A bot detail with three built-in entries, one of which has a blank title (server drops it). */
function botDetail(overrides: Record<string, unknown> = {}) {
  return {
    id: 'bot-1',
    name: 'Ola',
    knowledge: {
      enabled: false, // deliberately OFF: the migration reads the entries either way
      entries: [
        { title: 'Ton marki', content: 'a' },
        { title: 'Zwroty', content: 'b' },
        { title: '   ', content: 'ignored — no title' },
      ],
    },
    knowledge_binding: null,
    ...overrides,
  };
}

function httpError(status: number, data: unknown) {
  return { response: { status, data } };
}

/** Everything the teleported dialog renders. */
function bodyText(): string {
  return document.body.textContent ?? '';
}

function buttonByText(label: string): HTMLButtonElement | undefined {
  return Array.from(document.body.querySelectorAll('button')).find(
    (b) => (b.textContent ?? '').trim() === label,
  );
}

function flush(): Promise<void> {
  return new Promise((r) => setTimeout(r, 0));
}

function mountModal(props: Record<string, unknown> = {}) {
  return mount(BotKnowledgeMigrationModal, {
    attachTo: document.body,
    props: { open: true, ...props },
  });
}

describe('BotKnowledgeMigrationModal', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('pl');
    vi.clearAllMocks();
    h.api.get.mockResolvedValue({ data: botDetail() });
  });

  afterEach(() => {
    restoreBrowserMocks();
    document.body.innerHTML = '';
  });

  it('previews the base that WILL be created, counting entries the way the server does', async () => {
    const wrapper = mountModal({ botId: 'bot-1', botName: 'Ola' });
    await flush();
    await nextTick();

    expect(h.api.get).toHaveBeenCalledWith('/bots/bot-1');
    expect(bodyText()).toContain(t('knowledge.migrate.baseName', '', { bot: 'Ola' }));
    // Three rows, one with a blank title → the server carries TWO.
    expect(bodyText()).toContain(t('knowledge.migrate.entries', '', { count: 2 }));
    wrapper.unmount();
  });

  it('says the migration is ADDITIVE — the bot keeps its built-in entries', async () => {
    const wrapper = mountModal({ botId: 'bot-1' });
    await flush();
    await nextTick();

    expect(bodyText()).toContain(t('knowledge.migrate.additive'));
    wrapper.unmount();
  });

  it('migrates, toasts, closes and hands the result to the host', async () => {
    h.store.migrateKnowledge.mockResolvedValue({
      knowledge_base_id: 'base-9',
      name: 'Wiedza: Ola',
      entries_count: 2,
      mode: 'auto',
    });

    const wrapper = mountModal({ botId: 'bot-1' });
    await flush();
    await nextTick();

    buttonByText(t('knowledge.migrate.confirm'))?.click();
    await flush();
    await nextTick();

    expect(h.store.migrateKnowledge).toHaveBeenCalledWith('bot-1');
    expect(h.toast.success).toHaveBeenCalledWith(t('knowledge.migrate.done', '', { count: 2 }));
    expect(wrapper.emitted('migrated')?.[0]?.[0]).toMatchObject({ knowledge_base_id: 'base-9' });
    expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
    wrapper.unmount();
  });

  it('shows a 422 IN PLACE, verbatim — it names the entries that blocked the migration', async () => {
    const message = 'These entries contain template syntax and cannot be migrated: Ton marki; Zwroty.';
    h.store.migrateKnowledge.mockRejectedValue(httpError(422, { errors: { knowledge: [message] } }));

    const wrapper = mountModal({ botId: 'bot-1' });
    await flush();
    await nextTick();

    buttonByText(t('knowledge.migrate.confirm'))?.click();
    await flush();
    await nextTick();

    expect(bodyText()).toContain(message);
    // Stays OPEN: the message is a to-do list, and the modal is where it is readable.
    expect(wrapper.emitted('update:open')).toBeFalsy();
    expect(h.toast.danger).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  it('refuses to submit a bot with nothing to migrate, and says why', async () => {
    h.api.get.mockResolvedValue({ data: botDetail({ knowledge: { enabled: true, entries: [] } }) });

    const wrapper = mountModal({ botId: 'bot-1' });
    await flush();
    await nextTick();

    expect(bodyText()).toContain(t('knowledge.migrate.emptyBot'));
    expect(buttonByText(t('knowledge.migrate.confirm'))?.disabled).toBe(true);
    wrapper.unmount();
  });

  it('warns — but does not block — when the bot already reads a base', async () => {
    h.api.get.mockResolvedValue({
      data: botDetail({ knowledge_binding: { knowledge_base_id: 'base-1', mode: 'auto' } }),
    });

    const wrapper = mountModal({ botId: 'bot-1' });
    await flush();
    await nextTick();

    expect(bodyText()).toContain(t('knowledge.migrate.alreadyBound'));
    expect(buttonByText(t('knowledge.migrate.confirm'))?.disabled).toBe(false);
    wrapper.unmount();
  });

  it('offers a bot PICKER when the host did not fix one, and asks for nothing until one is picked', async () => {
    const wrapper = mountModal();
    await flush();
    await nextTick();

    expect(wrapper.findComponent({ name: 'BotSelect' }).exists()).toBe(true);
    expect(h.api.get).not.toHaveBeenCalled();
    expect(buttonByText(t('knowledge.migrate.confirm'))?.disabled).toBe(true);
    wrapper.unmount();
  });
});
