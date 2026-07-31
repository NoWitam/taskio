// @vitest-environment happy-dom
// WorkflowStepCard.assigneeBot.spec — the `create_task` step's BOT assignee, on the one point where
// it can lie to the author: WHAT THE TRIGGER SHOWS for a saved id.
//
// The picker is `executable-only` (it offers only bots that can actually run a task — assigning any
// other bot is a silent server-side no-op). That filter is deliberate and NOT under test here. Its
// side effect is: a bot that later loses the task-execution module — or is simply off the current
// async page — is never returned by `GET /bots?can_execute_tasks=1`, so Select has no label for the
// saved id and falls back to labelling the value with the VALUE itself: a raw uuid on screen.
//
// The generate_content AUTHOR field already solved exactly this (a directory-resolved name behind a
// `#value` slot, with a neutral placeholder while the lookup is in flight). This spec pins the same
// contract for the assignee: NEVER a raw uuid.
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
import { emptyStepConfig, type StepDraft } from '../workflowEditorModel';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { en } from '../../../app/i18n/en';
import type { WorkflowCatalog } from '../types';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

/** A real uuid — the directory refuses anything else WITHOUT a request. */
const BOT_A = '3f2a1c64-9f1e-4a7b-8c3d-2b6e5f0a1d94';

const CATALOG: WorkflowCatalog = { variables: [], fields: [], operations: [] };

const MarkdownEditorStub = {
  name: 'MarkdownEditor',
  props: ['modelValue'],
  setup: () => () => h('textarea', { class: 'md-stub' }),
};

/**
 * The `/bots` page the EXECUTABLE-ONLY picker sees: empty of BOT_A, which is the whole point —
 * the saved assignee lost the task-execution module (or sits on a later page), so the picker's own
 * data can never label it.
 */
const EXECUTABLE_PAGE = { data: [], meta: { next_cursor: null } };

function taskStep(config: Record<string, unknown> = {}): StepDraft {
  return reactive({
    uid: 't1',
    type: 'create_task',
    key: 'task',
    config: { ...emptyStepConfig('create_task'), ...config },
  });
}

function mountCard(step: StepDraft) {
  return mount(WorkflowStepCard, {
    attachTo: document.body,
    global: { stubs: { MarkdownEditor: MarkdownEditorStub } },
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
    },
  });
}

async function settle(): Promise<void> {
  for (let i = 0; i < 6; i += 1) {
    await nextTick();
    await Promise.resolve();
  }
  await nextTick();
}

describe('WorkflowStepCard — create_task BOT assignee: the saved id never renders raw', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    installBrowserMocks();
    vi.clearAllMocks();
  });
  afterEach(() => {
    restoreBrowserMocks();
    vi.restoreAllMocks();
  });

  it('renders a saved bot assignee by NAME even when the executable-only list cannot offer it', async () => {
    apiMock.get.mockImplementation((url: string) => {
      if (url.startsWith('/bots?') || url === '/bots') return Promise.resolve(EXECUTABLE_PAGE);
      if (url.startsWith('/bots/')) {
        return Promise.resolve({ data: { id: BOT_A, name: 'Marketing Maven', status: 'active', visual: null } });
      }
      throw new Error(`unexpected GET ${url}`);
    });

    const step = taskStep({ assignee_type: 'bot', assignee_id: BOT_A });
    const wrapper = mountCard(step);
    await settle();

    const picker = wrapper.findComponent(BotSelect);
    expect(picker.text()).not.toContain(BOT_A);
    expect(picker.text()).toContain('Marketing Maven');
    wrapper.unmount();
  });

  it('shows a neutral placeholder — never the uuid — while the name is still unknown', async () => {
    // A pending / failed lookup is the worst case: there is nothing to show BUT the id, which is
    // precisely what must not leak.
    apiMock.get.mockImplementation((url: string) => {
      if (url.startsWith('/bots?') || url === '/bots') return Promise.resolve(EXECUTABLE_PAGE);
      return new Promise(() => {}); // never settles
    });

    const step = taskStep({ assignee_type: 'bot', assignee_id: BOT_A });
    const wrapper = mountCard(step);
    await settle();

    const picker = wrapper.findComponent(BotSelect);
    expect(picker.text()).not.toContain(BOT_A);
    expect(picker.text()).toContain(en.workflows.step.generate_content.botUnknownName);
    wrapper.unmount();
  });

  it('asks nothing about bots when the assignee is a USER (no stray lookup)', async () => {
    apiMock.get.mockResolvedValue(EXECUTABLE_PAGE);

    const step = taskStep({ assignee_type: 'user', assignee_id: 'user-1' });
    const wrapper = mountCard(step);
    await settle();

    const detailCalls = apiMock.get.mock.calls
      .map((c) => String(c[0]))
      .filter((url) => url.startsWith('/bots/'));
    expect(detailCalls).toEqual([]);
    wrapper.unmount();
  });
});
