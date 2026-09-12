// @vitest-environment happy-dom
// A 401 ON A PUBLIC SCREEN MUST NOT NAVIGATE.
//
// The interceptor's job — send an expired session back to the login form — is right inside
// the app and wrong outside it. `/next/reset-password` holds `?token=&email=` from a mailed
// link: one copy, no way to re-derive it, and `window.location.assign()` discards it. The
// person who followed that link arrives with a token in localStorage that is EXPECTED to be
// dead (they forgot their password; the session behind it is old), `/auth/me` answers 401 on
// boot, and before this they were thrown onto a login form they could not use. Same shape,
// same loss, on `/next/invitations/<token>`.
//
// The request goes through the real client with a per-request adapter, so the interceptor
// wiring is what is under test (the same technique as apiLocaleHeader.spec).
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { AxiosError, AxiosResponse } from 'axios';
import { api, LOGIN_PATH } from '../api';
import { isPublicPath, PUBLIC_PATHS } from '../publicRoutes';
import { router } from '../../router';

const originalLocation = Object.getOwnPropertyDescriptor(window, 'location');

/** Pretend the browser is sitting on `pathname`, and capture any navigation away from it. */
function browserAt(pathname: string): { assign: ReturnType<typeof vi.fn> } {
  const assign = vi.fn();

  Object.defineProperty(window, 'location', {
    configurable: true,
    writable: true,
    value: { pathname, assign, href: `http://localhost${pathname}` },
  });

  return { assign };
}

/** Issue a real request whose adapter refuses with a 401, the way an expired token does. */
async function unauthorizedRequest(): Promise<void> {
  await api
    .get('/auth/me', {
      adapter: (config): Promise<AxiosResponse> => {
        const error = new Error('Unauthenticated.') as AxiosError;
        error.response = {
          status: 401,
          statusText: 'Unauthorized',
          data: { message: 'Unauthenticated.' },
          headers: {},
          config: config as never,
        };

        return Promise.reject(error);
      },
    })
    // The rejection is re-thrown by design — callers still see their own failure.
    .catch(() => undefined);
}

afterEach(() => {
  if (originalLocation) {
    Object.defineProperty(window, 'location', originalLocation);
  } else {
    delete (window as unknown as Record<string, unknown>).location;
  }
});

describe('api client — a 401 while on a public screen', () => {
  it('leaves the reset screen (and its mailed token) exactly where it is', async () => {
    const { assign } = browserAt('/next/reset-password');

    await unauthorizedRequest();

    expect(assign).not.toHaveBeenCalled();
  });

  it('leaves the forgot and invite screens alone too', async () => {
    for (const pathname of ['/next/forgot-password', '/next/invitations/an-invite-token']) {
      const { assign } = browserAt(pathname);

      await unauthorizedRequest();

      expect(assign, `navigated away from ${pathname}`).not.toHaveBeenCalled();
    }
  });

  it('does not loop on the login screen', async () => {
    // This used to be a special case of its own (`pathname.startsWith(LOGIN_PATH)`); it is
    // now covered by the same public-path check, so the old guard could be dropped.
    const { assign } = browserAt(LOGIN_PATH);

    await unauthorizedRequest();

    expect(assign).not.toHaveBeenCalled();
  });

  it('still sends an expired session inside the app back to the login form', async () => {
    // The behaviour worth keeping: without this, a dead token inside the shell would leave
    // the user staring at empty screens and failing requests.
    const { assign } = browserAt('/next/dashboard');

    await unauthorizedRequest();

    expect(assign).toHaveBeenCalledWith(LOGIN_PATH);
  });

  it('does not navigate on failures that are not a 401', async () => {
    const { assign } = browserAt('/next/dashboard');

    await api
      .get('/probe', {
        adapter: (config): Promise<AxiosResponse> => {
          const error = new Error('Server error') as AxiosError;
          error.response = {
            status: 500,
            statusText: 'Server Error',
            data: {},
            headers: {},
            config: config as never,
          };

          return Promise.reject(error);
        },
      })
      .catch(() => undefined);

    expect(assign).not.toHaveBeenCalled();
  });
});

// ─────────────────────────────────────────────────────────────────────────────────────────
// THE LIST AND THE ROUTER MUST AGREE
//
// `PUBLIC_PATHS` is a second statement of something the route records already say, for the
// one consumer that cannot import them (see publicRoutes.ts). A second statement can rot, so
// it is checked against the first here rather than trusted.
// ─────────────────────────────────────────────────────────────────────────────────────────

describe('publicRoutes — mirrored against the real route records', () => {
  const publicRoutePaths = [
    '/login',
    '/forgot-password',
    '/reset-password',
    '/invitations/an-invite-token',
    '/_styleguide',
  ];

  const guardedRoutePaths = [
    '/dashboard',
    '/tasks',
    '/publishing/publications',
    '/disk',
    '/settings/members',
  ];

  it.each(publicRoutePaths)('%s is public in the router and in PUBLIC_PATHS', (path) => {
    expect(router.resolve(path).meta.public, `${path} lost meta.public`).toBe(true);
    expect(isPublicPath(`/next${path}`), `${path} is public but the api client does not know`).toBe(
      true,
    );
  });

  it.each(guardedRoutePaths)('%s is behind the guard and never treated as public', (path) => {
    expect(router.resolve(path).meta.requiresAuth).toBe(true);
    expect(isPublicPath(`/next${path}`), `${path} would swallow its own 401`).toBe(false);
  });

  it('trusts no path the router does not serve publicly', () => {
    for (const publicPath of PUBLIC_PATHS) {
      // `/next/invitations` is a prefix of a parameterised record, so probe it with a token.
      const routerPath =
        publicPath === '/next/invitations'
          ? '/invitations/a-token'
          : publicPath.replace(/^\/next/, '');

      expect(
        router.resolve(routerPath).meta.public,
        `${publicPath} is trusted as public but the router does not mark it so`,
      ).toBe(true);
    }
  });

  it('matches prefixes only at a segment boundary', () => {
    expect(isPublicPath('/next/login')).toBe(true);
    expect(isPublicPath('/next/login/')).toBe(true);
    expect(isPublicPath('/next/invitations/abc')).toBe(true);
    expect(isPublicPath('/next/login-as-somebody-else')).toBe(false);
    expect(isPublicPath('/next/reset-passwords-of-everyone')).toBe(false);
  });
});
