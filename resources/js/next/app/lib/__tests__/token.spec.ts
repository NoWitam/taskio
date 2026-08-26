// token.spec — "is anyone logged in on this client?", the question `app/i18n` asks before it PUTs a
// language preference for somebody who may be an anonymous visitor on the invite-accept screen.
//
// `hasAuthToken()` was made public for that caller and had no direct pin. It was exercised only
// through mocks — every i18n/store spec replaces `lib/token` wholesale with a `vi.mock` factory — so
// the REAL reader, the one that actually runs in a browser, was never executed by the suite at all.
// The whole point of the function is what it does when storage misbehaves, and that was untested.
//
// The environment matters. This file stays on the repo default (`node`), which has no `localStorage`
// global — precisely the unavailable-storage case the function must survive. The module reads the
// global lazily inside a try/catch rather than capturing it at import time, so stubbing the global
// per test is enough to drive every branch.
import { afterEach, describe, expect, it, vi } from 'vitest';
import { authToken, hasAuthToken, TOKEN_KEY } from '../token';
import { TOKEN_KEY as TOKEN_KEY_VIA_API } from '../api';

/** A minimal `localStorage` stand-in backed by a plain record. */
function fakeStorage(entries: Record<string, string> = {}): Storage {
  return {
    getItem: (key: string): string | null => (key in entries ? entries[key] : null),
    setItem: (key: string, value: string): void => {
      entries[key] = value;
    },
    removeItem: (key: string): void => {
      delete entries[key];
    },
    clear: (): void => {
      for (const key of Object.keys(entries)) delete entries[key];
    },
    key: (index: number): string | null => Object.keys(entries)[index] ?? null,
    get length(): number {
      return Object.keys(entries).length;
    },
  } as Storage;
}

type GlobalWithStorage = { localStorage?: unknown };

/** Remove the global entirely — an environment that never defines it (SSR, a worker, node). */
function removeStorage(): void {
  delete (globalThis as GlobalWithStorage).localStorage;
}

/** Private mode: touching `localStorage` at all throws, before any method is reached. */
function throwOnStorageAccess(error: Error): void {
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    get() {
      throw error;
    },
  });
}

const ORIGINAL_DESCRIPTOR = Object.getOwnPropertyDescriptor(globalThis, 'localStorage');

afterEach(() => {
  vi.unstubAllGlobals();
  // The throwing-accessor case installs its own descriptor, which `unstubAllGlobals` knows nothing
  // about; restore whatever this environment started with.
  if (ORIGINAL_DESCRIPTOR) Object.defineProperty(globalThis, 'localStorage', ORIGINAL_DESCRIPTOR);
  else removeStorage();
});

describe('hasAuthToken — asking "is anyone logged in?" without importing the HTTP client', () => {
  it('answers "no" when the environment has no localStorage at all', () => {
    removeStorage();

    // Reading an undeclared identifier throws a ReferenceError; the guard catches it and answers the
    // safe direction — the speculative request is skipped, never forced.
    expect(hasAuthToken()).toBe(false);
    expect(authToken()).toBeNull();
  });

  it('answers "no" when the global is present but undefined', () => {
    vi.stubGlobal('localStorage', undefined);

    expect(hasAuthToken()).toBe(false);
    expect(authToken()).toBeNull();
  });

  it('answers "no" when reaching storage throws (private mode)', () => {
    throwOnStorageAccess(new Error('SecurityError: The operation is insecure.'));

    expect(hasAuthToken()).toBe(false);
    expect(authToken()).toBeNull();
  });

  it('answers "no" when getItem itself throws (quota / disabled storage)', () => {
    vi.stubGlobal('localStorage', {
      getItem: () => {
        throw new Error('SecurityError');
      },
    });

    expect(hasAuthToken()).toBe(false);
    expect(authToken()).toBeNull();
  });

  it('answers "yes" when a token is stored, and hands the token itself to authToken()', () => {
    vi.stubGlobal('localStorage', fakeStorage({ [TOKEN_KEY]: 'a-real-looking|token' }));

    expect(hasAuthToken()).toBe(true);
    expect(authToken()).toBe('a-real-looking|token');
  });

  it('answers "no" when nothing is stored under its own key, however full storage is', () => {
    // Other keys the app writes must not be mistaken for a login — `next-locale` in particular is
    // written for anonymous visitors, who are exactly the people this guard protects.
    vi.stubGlobal('localStorage', fakeStorage({ 'next-locale': 'pl', 'next-theme': 'dark' }));

    expect(hasAuthToken()).toBe(false);
    expect(authToken()).toBeNull();
  });

  it('follows storage live rather than caching the first answer', () => {
    const entries: Record<string, string> = {};
    vi.stubGlobal('localStorage', fakeStorage(entries));

    expect(hasAuthToken()).toBe(false);

    entries[TOKEN_KEY] = 'logged-in-now';
    expect(hasAuthToken(), 'a login during the session must be visible').toBe(true);

    delete entries[TOKEN_KEY];
    expect(hasAuthToken(), 'and so must a logout').toBe(false);
  });

  /**
   * AN EMPTY STRING IS NOT A TOKEN — and the two readers have to agree about that.
   *
   * `getItem` answers `''`, not `null`, for a key stored empty. `api.ts` already treats an empty
   * bearer as absent (`if (bearer)`), so "present" here would mean firing exactly the request this
   * guard exists to prevent: `PUT /user/locale` with no `Authorization`, a 401, and the interceptor
   * navigating an anonymous visitor off the invite page they were sent.
   *
   * `authToken()` still reports what storage actually holds — the raw read stays faithful; only the
   * question "is anyone logged in?" takes a view.
   */
  it('does not count an empty stored value as a login', () => {
    vi.stubGlobal('localStorage', fakeStorage({ [TOKEN_KEY]: '' }));

    expect(authToken(), 'the raw read stays faithful to storage').toBe('');
    expect(hasAuthToken(), 'an empty token cannot authenticate anything').toBe(false);
  });
});

describe('the storage key is the verified backend auth contract', () => {
  it('is the literal every mock in this suite hardcodes', () => {
    // ~5 spec files stub `lib/token` with this literal; changing it here without changing them would
    // leave those suites green while the app could no longer find its own login.
    expect(TOKEN_KEY).toBe('taskio_token');
  });

  it('is RE-EXPORTED by lib/api rather than declared twice', () => {
    // `stores/auth` and `lib/echo` import it from `lib/api`; a second literal would drift silently.
    expect(TOKEN_KEY_VIA_API).toBe(TOKEN_KEY);
  });
});
