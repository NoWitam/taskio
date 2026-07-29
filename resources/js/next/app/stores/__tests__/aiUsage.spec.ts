// @vitest-environment happy-dom
// Unit tests for the "next" AI-usage store (R2 sub-stage 4). fetchAiUsage GETs the summary and caches
// res.data; updateAiCap PATCHes { monthly_cost_cap } with the null/0/positive semantics intact and reconciles
// with the REFRESHED summary the endpoint returns. The api client is mocked so no real HTTP happens. Mirrors
// the workspaces store spec.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../lib/api', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}));

import { api } from '../../lib/api';
import { useAiUsageStore, type AiUsageSummary } from '../aiUsage';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  patch: ReturnType<typeof vi.fn>;
};

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
    can_manage: true,
    ...overrides,
  };
}

describe('next ai-usage store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  describe('fetchAiUsage', () => {
    it('GETs the workspace ai-usage endpoint and caches res.data', async () => {
      const store = useAiUsageStore();
      const data = summary({ cost_used: 7.5 });
      apiMock.get.mockResolvedValueOnce({ data });

      const result = await store.fetchAiUsage('w1');

      expect(apiMock.get).toHaveBeenCalledWith('/workspaces/w1/ai-usage');
      expect(result.cost_used).toBe(7.5);
      expect(store.summary?.cost_used).toBe(7.5);
      expect(store.loading).toBe(false);
      expect(store.errored).toBe(false);
    });

    it('sets errored and rejects when the GET fails', async () => {
      const store = useAiUsageStore();
      apiMock.get.mockRejectedValueOnce({ response: { status: 500 } });

      await expect(store.fetchAiUsage('w1')).rejects.toBeTruthy();
      expect(store.errored).toBe(true);
      expect(store.summary).toBeNull();
    });
  });

  describe('updateAiCap (null / 0 / positive semantics)', () => {
    it('PATCHes a positive cap and reconciles with the refreshed summary', async () => {
      const store = useAiUsageStore();
      const refreshed = summary({ cost_cap: 25, cap_source: 'workspace' });
      apiMock.patch.mockResolvedValueOnce({ data: refreshed });

      const result = await store.updateAiCap('w1', 25);

      expect(apiMock.patch).toHaveBeenCalledWith('/workspaces/w1/ai-usage/cap', { monthly_cost_cap: 25 });
      expect(result.cost_cap).toBe(25);
      expect(store.summary?.cost_cap).toBe(25);
      expect(store.saving).toBe(false);
    });

    it('sends 0 for explicit UNLIMITED', async () => {
      const store = useAiUsageStore();
      apiMock.patch.mockResolvedValueOnce({ data: summary({ cost_cap: 0, cap_source: 'unlimited', cost_remaining: null }) });

      const result = await store.updateAiCap('w1', 0);

      expect(apiMock.patch).toHaveBeenCalledWith('/workspaces/w1/ai-usage/cap', { monthly_cost_cap: 0 });
      expect(result.cap_source).toBe('unlimited');
      expect(store.summary?.cost_remaining).toBeNull();
    });

    it('sends null to CLEAR the override (inherit the platform default)', async () => {
      const store = useAiUsageStore();
      apiMock.patch.mockResolvedValueOnce({ data: summary({ cap_source: 'default' }) });

      await store.updateAiCap('w1', null);

      expect(apiMock.patch).toHaveBeenCalledWith('/workspaces/w1/ai-usage/cap', { monthly_cost_cap: null });
      expect(store.summary?.cap_source).toBe('default');
    });

    it('clears saving even when the PATCH rejects', async () => {
      const store = useAiUsageStore();
      apiMock.patch.mockRejectedValueOnce({ response: { status: 403 } });

      await expect(store.updateAiCap('w1', 5)).rejects.toBeTruthy();
      expect(store.saving).toBe(false);
    });
  });
});
