// @vitest-environment happy-dom
// botDetailSection.spec — BotDetailView's route-name → section mapping (Batch 3).
// The detail sections became CHILD ROUTES (`next.bots.detail.<section>`) sharing
// one component; this spec pins which section renders for each route name (and
// the defensive inbox fallback). The heavy section bodies (BotInbox /
// BotActionTimeline own their fetches) are stubbed; the config section asserts on
// the REAL i18n module copy.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import BotDetailView from '../BotDetailView.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { en } from '../../../app/i18n/en';
import type { BotDetail } from '../types';

// --- Store + toast + router mocks -------------------------------------------
const detailRef = ref<BotDetail | null>(null);
const fetchBot = vi.fn();

vi.mock('../../../app/stores/bots', () => ({
  useBotsStore: () => ({
    get detail() {
      return detailRef.value;
    },
    fetchBot,
    setStatus: vi.fn(),
  }),
}));

vi.mock('../../../app/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() }),
}));

const routeName = ref<string>('next.bots.detail.inbox');
vi.mock('vue-router', () => ({
  useRoute: () => ({
    get name() {
      return routeName.value;
    },
    params: { id: 'bot-1' },
    query: {},
  }),
  useRouter: () => ({ push: vi.fn() }),
}));

function makeBot(overrides: Partial<BotDetail> = {}): BotDetail {
  return {
    id: 'bot-1',
    name: 'Bot',
    status: 'active',
    description: null,
    icon: null,
    persona: 'A helpful persona.',
    style: null,
    dictionary: [],
    phrases: [],
    prohibitions: [],
    task_execution: null,
    visual: null,
    audio: null,
    knowledge: { enabled: false, entries: [] },
    is_owner: true,
    can_execute_tasks: false,
    can_be_edited: true,
    can_be_deleted: true,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

async function mountDetail(name: string) {
  routeName.value = name;
  detailRef.value = makeBot();
  const wrapper = mount(BotDetailView, {
    attachTo: document.body,
    global: { stubs: { BotInbox: true, BotActionTimeline: true } },
  });
  await flushPromises();
  return wrapper;
}

beforeEach(() => {
  installBrowserMocks();
  detailRef.value = null;
  fetchBot.mockReset();
});

afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('BotDetailView — route name → rendered section (Batch 3)', () => {
  it('next.bots.detail.inbox → the inbox section', async () => {
    const wrapper = await mountDetail('next.bots.detail.inbox');
    expect(wrapper.findComponent({ name: 'BotInbox' }).exists()).toBe(true);
    expect(wrapper.findComponent({ name: 'BotActionTimeline' }).exists()).toBe(false);
    expect(wrapper.text()).not.toContain(en.bots.modules.text);
  });

  it('the PageHeader h1 names the SECTION purpose (identity lives in the aside, not here)', async () => {
    const wrapper = await mountDetail('next.bots.detail.inbox');
    const headings = wrapper.findAll('h1');
    expect(headings).toHaveLength(1);
    expect(headings[0].text()).toBe(en.bots.detail.tabInbox);
    expect(wrapper.text()).toContain(en.bots.detail.sectionDescriptions.inbox);
    // Neither the bot's name-as-title nor its StatusBadge render on the page.
    expect(headings[0].text()).not.toContain('Bot');
    expect(wrapper.findComponent({ name: 'StatusBadge' }).exists()).toBe(false);
  });

  it('next.bots.detail.activity → the activity timeline', async () => {
    const wrapper = await mountDetail('next.bots.detail.activity');
    expect(wrapper.findComponent({ name: 'BotActionTimeline' }).exists()).toBe(true);
    expect(wrapper.findComponent({ name: 'BotInbox' }).exists()).toBe(false);
  });

  it('next.bots.detail.config → the module-preview panels', async () => {
    const wrapper = await mountDetail('next.bots.detail.config');
    expect(wrapper.text()).toContain(en.bots.modules.text);
    expect(wrapper.text()).toContain('A helpful persona.');
    expect(wrapper.findComponent({ name: 'BotInbox' }).exists()).toBe(false);
    expect(wrapper.findComponent({ name: 'BotActionTimeline' }).exists()).toBe(false);
  });

  it('an unexpected route name falls back to the inbox section', async () => {
    const wrapper = await mountDetail('next.bots.detail');
    expect(wrapper.findComponent({ name: 'BotInbox' }).exists()).toBe(true);
  });
});
