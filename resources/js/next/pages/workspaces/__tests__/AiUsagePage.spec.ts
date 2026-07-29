// @vitest-environment happy-dom
// AiUsagePage.spec — the $-first usage meter + owner cap editor (R2 sub-stage 4). Asserts the four meter
// states (ok / warn / blocked / uncapped), the owner-editor vs member-read-only gate driven by can_manage,
// the per-channel StatCards + the per-actor breakdown (incl. the display_name:null per-type fallback and the
// "others" bucket), and that an owner save confirms then PATCHes the cap. The api client + confirm/toast are
// mocked so the test is isolated from HTTP.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

vi.mock('../../../app/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
  TOKEN_KEY: 'taskio_token',
  WORKSPACE_KEY: 'taskio_workspace',
}));

const confirmMock = vi.fn().mockResolvedValue(true);
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => confirmMock }));

const toast = { success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn(), show: vi.fn(), dismiss: vi.fn(), clear: vi.fn() };
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => toast }));

import { api } from '../../../app/lib/api';
import { useAuthStore } from '../../../app/stores/auth';
import type { AiUsageSummary } from '../../../app/stores/aiUsage';
import AiUsagePage from '../AiUsagePage.vue';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn>; patch: ReturnType<typeof vi.fn> };

function summary(overrides: Partial<AiUsageSummary> = {}): AiUsageSummary {
  return {
    currency: 'USD',
    estimated: true,
    cost_used: 4,
    cost_cap: 10,
    cost_remaining: 6,
    cap_source: 'workspace',
    warn_ratio: 0.8,
    warn_reached: false,
    blocked: false,
    period: { month: '2026-07', resets_at: '2026-08-01T00:00:00Z' },
    tokens_used: 1234,
    per_channel: [],
    per_actor: [],
    can_manage: false,
    ...overrides,
  };
}

async function mountPage(data: AiUsageSummary) {
  apiMock.get.mockResolvedValue({ data });
  const auth = useAuthStore();
  auth.currentWorkspaceId = 'w1';
  const wrapper = mount(AiUsagePage, { attachTo: document.body });
  await flushPromises();
  return wrapper;
}

describe('AiUsagePage (R2 sub-stage 4)', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
    confirmMock.mockResolvedValue(true);
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('ok state: shows $ figures + the "estimated" caveat + an on-track status', async () => {
    const wrapper = await mountPage(summary({ cost_used: 4, cost_cap: 10, cost_remaining: 6 }));
    expect(wrapper.text()).toContain('$4.00');
    expect(wrapper.text()).toContain('$10.00');
    expect(wrapper.text()).toContain('$6.00');
    expect(wrapper.text()).toContain('estimates'); // the always-visible caveat
    expect(wrapper.text()).toContain('On track');
    wrapper.unmount();
  });

  it('warn state: surfaces the approaching-limit status', async () => {
    const wrapper = await mountPage(summary({ cost_used: 8.5, warn_reached: true }));
    expect(wrapper.text()).toContain('Approaching limit');
    wrapper.unmount();
  });

  it('blocked state: surfaces the limit-reached status', async () => {
    const wrapper = await mountPage(summary({ cost_used: 10, warn_reached: true, blocked: true }));
    expect(wrapper.text()).toContain('Limit reached');
    wrapper.unmount();
  });

  it('uncapped state: shows a clean "no limit" (no scary meter)', async () => {
    const wrapper = await mountPage(
      summary({ cap_source: 'unlimited', cost_cap: 0, cost_remaining: null }),
    );
    expect(wrapper.text()).toContain('No limit');
    expect(wrapper.find('[role="progressbar"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('member (can_manage=false): read-only, NO cap editor', async () => {
    const wrapper = await mountPage(summary({ can_manage: false }));
    expect(wrapper.find('[role="radiogroup"]').exists()).toBe(false);
    expect(wrapper.text()).not.toContain('Set a limit');
    wrapper.unmount();
  });

  it('owner (can_manage=true): the cap editor is shown', async () => {
    const wrapper = await mountPage(summary({ can_manage: true }));
    expect(wrapper.find('[role="radiogroup"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Set a limit');
    wrapper.unmount();
  });

  it('owner save confirms then PATCHes the cap', async () => {
    apiMock.patch.mockResolvedValue({ data: summary({ can_manage: true, cost_cap: 10 }) });
    const wrapper = await mountPage(summary({ can_manage: true, cost_cap: 10, cap_source: 'workspace' }));

    await wrapper.find('form').trigger('submit');
    await flushPromises();

    expect(confirmMock).toHaveBeenCalled();
    expect(apiMock.patch).toHaveBeenCalledWith('/workspaces/w1/ai-usage/cap', { monthly_cost_cap: 10 });
    expect(toast.success).toHaveBeenCalled();
    wrapper.unmount();
  });

  it('per-channel StatCards render $ per channel', async () => {
    const wrapper = await mountPage(
      summary({
        per_channel: [
          { channel: 'ai_text', cost: 2.5, tokens: 900 },
          { channel: 'ai_image_generate', cost: 1.5, tokens: 0 },
        ],
      }),
    );
    expect(wrapper.text()).toContain('Text');
    expect(wrapper.text()).toContain('$2.50');
    expect(wrapper.text()).toContain('Image generation');
    wrapper.unmount();
  });

  it('per-actor: resolves names, falls back per-type for null, labels the others bucket', async () => {
    const wrapper = await mountPage(
      summary({
        per_actor: [
          { actor_type: 'user', actor_id: 'u1', display_name: 'Ada Lovelace', icon: null, cost: 3, tokens: 100 },
          { actor_type: 'workflow_run', actor_id: 'r1', display_name: null, icon: null, cost: 2, tokens: 80 },
          { actor_type: 'others', actor_id: null, display_name: null, icon: null, cost: 1, tokens: 40 },
        ],
      }),
    );
    expect(wrapper.text()).toContain('Ada Lovelace');
    expect(wrapper.text()).toContain('Automation'); // display_name:null → per-type fallback
    expect(wrapper.text()).toContain('Others'); // the synthetic bucket
    wrapper.unmount();
  });
});
