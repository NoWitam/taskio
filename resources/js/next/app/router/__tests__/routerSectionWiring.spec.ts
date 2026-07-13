// @vitest-environment happy-dom
// routerSectionWiring.spec.ts — the REAL router records for the bots/workflows
// detail sections (Batch 3). sectionRedirect.spec covers the pure function; this
// spec pins the WIRING that invokes it — the part a unit test cannot see: the
// legacy names sit on the bare `:id` records, named pushes land on the default
// section child, and legacy `?section=` deep links redirect onto the right child
// with every other query key preserved (hard constraint D4). A wrong namePrefix /
// fallback / sections list, or the legacy name moved onto a child, fails here.
//
// The auth store is mocked (authenticated + ready) so the global guard lets the
// pushes through; real navigation resolves the lazy route components, which is
// exactly the integration this spec exists to exercise.
import { describe, it, expect, vi } from 'vitest';
import { router } from '../index';

vi.mock('../../stores/auth', () => ({
  useAuthStore: () => ({
    ready: true,
    isAuthenticated: true,
    init: vi.fn().mockResolvedValue(undefined),
  }),
}));

describe('router — bots/workflows detail section wiring (Batch 3)', () => {
  it('a named push to next.workflows.detail lands on the overview child', async () => {
    await router.push({ name: 'next.workflows.detail', params: { id: 'w1' } });
    expect(router.currentRoute.value.name).toBe('next.workflows.detail.overview');
    expect(router.currentRoute.value.path).toBe('/workflows/w1/overview');
  });

  it('a legacy ?section=runs deep link redirects to the runs child, query preserved', async () => {
    await router.push('/workflows/w1?section=runs&run_detail=X&state=failed');
    expect(router.currentRoute.value.name).toBe('next.workflows.detail.runs');
    expect(router.currentRoute.value.query).toEqual({ run_detail: 'X', state: 'failed' });
    expect(router.currentRoute.value.params).toEqual({ id: 'w1' });
  });

  it('a named push to next.bots.detail lands on the inbox child', async () => {
    await router.push({ name: 'next.bots.detail', params: { id: 'b1' } });
    expect(router.currentRoute.value.name).toBe('next.bots.detail.inbox');
    expect(router.currentRoute.value.path).toBe('/bots/b1/inbox');
  });

  it('a legacy ?section=config deep link redirects to the config child, query preserved', async () => {
    await router.push('/bots/b1?section=config&bot=b2');
    expect(router.currentRoute.value.name).toBe('next.bots.detail.config');
    expect(router.currentRoute.value.query).toEqual({ bot: 'b2' });
  });

  it('an unknown legacy section falls back to the default child and strips the key', async () => {
    await router.push('/bots/b1?section=nonsense');
    expect(router.currentRoute.value.name).toBe('next.bots.detail.inbox');
    expect(router.currentRoute.value.query).toEqual({});
  });

  it('the section children resolve to distinct paths under the detail record', () => {
    const names = router
      .getRoutes()
      .map((r) => String(r.name ?? ''))
      .filter((n) => n.startsWith('next.bots.detail.') || n.startsWith('next.workflows.detail.'));
    expect(names.sort()).toEqual([
      'next.bots.detail.activity',
      'next.bots.detail.config',
      'next.bots.detail.inbox',
      'next.workflows.detail.overview',
      'next.workflows.detail.runs',
    ]);
  });
});
