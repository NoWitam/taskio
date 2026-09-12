// @vitest-environment happy-dom
// The WIRING of the two reset screens into the router — the half a unit test cannot see.
//
// WHAT `meta: { public: true }` ACTUALLY DOES, because the first version of this file said
// something else and was wrong about it: the global guard gates on `requiresAuth`, so a route
// that FORGOT `public` would still have rendered — nothing bounced. `public` was decoration.
//
// It is load-bearing now, and this is what it decides: THE GUARD DOES NOT HYDRATE A SESSION
// BEFORE A PUBLIC ROUTE. `auth.init()` calls `/auth/me`, and on these screens the stored token
// is EXPECTED to be dead — a stale token is the normal state of somebody who came to reset a
// forgotten password. Waiting on that round-trip delays a form that needs no session, and the
// 401 it produces used to be answered by the api interceptor with a hard
// `location.assign('/next/login')` — which discards `?token=&email=`, the one copy of a
// credential that arrives by mail. The interceptor half is pinned in
// `app/lib/__tests__/publicRoute401.spec.ts`; this file pins the guard half and the records.
//
// The auth store is mocked NOT READY and signed out — the state these screens are actually
// reached in, and the only state in which the guard would reach for `init()` at all.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { router } from '../../../app/router';

const init = vi.fn().mockResolvedValue(undefined);

vi.mock('../../../app/stores/auth', () => ({
  useAuthStore: () => ({
    ready: false,
    isAuthenticated: false,
    init,
  }),
}));

beforeEach(() => {
  init.mockClear();
});

describe('router — password reset screens', () => {
  it('/forgot-password is a public route', () => {
    const resolved = router.resolve('/forgot-password');

    expect(resolved.name).toBe('next.password.forgot');
    expect(resolved.meta.public).toBe(true);
    expect(resolved.meta.requiresAuth).toBeUndefined();
  });

  it('/reset-password is a public route', () => {
    const resolved = router.resolve('/reset-password');

    expect(resolved.name).toBe('next.password.reset');
    expect(resolved.meta.public).toBe(true);
    expect(resolved.meta.requiresAuth).toBeUndefined();
  });

  it('a signed-out viewer reaches the forgot screen instead of being bounced to login', async () => {
    await router.push('/forgot-password');

    expect(router.currentRoute.value.name).toBe('next.password.forgot');
  });

  it('the mailed link keeps its token and email in the query', async () => {
    // The pair travels in the QUERY, not the path — so it survives the navigation intact and
    // the page's own parser is the only thing that reads it.
    await router.push('/reset-password?token=a-plaintext-token&email=user%40example.com');

    expect(router.currentRoute.value.name).toBe('next.password.reset');
    expect(router.currentRoute.value.query).toEqual({
      token: 'a-plaintext-token',
      email: 'user@example.com',
    });
  });

  it('does not hydrate a session on the way to a public screen', async () => {
    await router.push('/reset-password?token=a-plaintext-token&email=user%40example.com');
    expect(init).not.toHaveBeenCalled();

    await router.push('/forgot-password');
    expect(init).not.toHaveBeenCalled();

    // …and the invite screen, which carries the same shape of credential in its URL.
    await router.push('/invitations/an-invite-token');
    expect(init).not.toHaveBeenCalled();
  });

  it('still hydrates on the way to a screen that needs the session', async () => {
    // The other half of the same condition: skipping `init()` everywhere would leave the app
    // shell unable to tell a returning user from a signed-out one.
    await router.push('/dashboard');

    expect(init).toHaveBeenCalledOnce();
    // Mocked signed-out, so the guard then does its job and bounces.
    expect(router.currentRoute.value.name).toBe('next.login');
  });
});
