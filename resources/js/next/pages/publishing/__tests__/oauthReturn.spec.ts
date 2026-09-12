// oauthReturn.spec — reading the callback's answer, and the catalog that has to answer it.
//
// TWO FAILURES THIS PINS, BOTH OF WHICH WOULD SHIP LOOKING FINE:
//
//   1. Watching the wrong query key. The controller's constant is `RESULT_KEY = 'connection'`
//      — not `connect`. A screen reading `connect` shows NOTHING after a successful consent
//      flow, and the only symptom is silence at the end of a journey through Google.
//
//   2. A missing translation. Before B3 only four of the fourteen reasons had a sentence
//      while the controller could already report all fourteen, so ten would have rendered as
//      their own raw key on the one screen a person lands on after a failed consent flow.
//      The test below walks every reason in BOTH catalogs and refuses a key-shaped answer.
import { describe, it, expect } from 'vitest';
import { readOAuthReturn } from '../oauthReturn';
import { OAUTH_REASONS, oauthReasonKey } from '../publishingMeta';
import { en } from '../../../app/i18n/en';
import { pl } from '../../../app/i18n/pl';

describe('readOAuthReturn', () => {
  it('reads the `connection` key — not `connect`', () => {
    expect(readOAuthReturn({ connection: 'connected', platform: 'youtube' })).toEqual({
      result: 'connected',
      platform: 'youtube',
      reason: null,
    });
    // The key the specification's own prose slipped on. Nothing here may answer to it.
    expect(readOAuthReturn({ connect: 'connected', platform: 'youtube' })).toBeNull();
  });

  it('reads a failure with its reason', () => {
    expect(
      readOAuthReturn({ connection: 'failed', platform: 'facebook', reason: 'access_denied' }),
    ).toEqual({ result: 'failed', platform: 'facebook', reason: 'access_denied' });
  });

  it('works with NO platform — the controller filters a null one', () => {
    // Exactly what `unknown_platform` produces.
    const result = readOAuthReturn({ connection: 'failed', reason: 'unknown_platform' });
    expect(result).toEqual({ result: 'failed', platform: null, reason: 'unknown_platform' });
  });

  it('keeps an unrecognised reason VERBATIM', () => {
    // Any raw platform error passes through, truncated to 64 chars, and is not validated.
    const result = readOAuthReturn({
      connection: 'failed',
      platform: 'youtube',
      reason: 'server_error',
    });
    expect(result?.reason).toBe('server_error');
  });

  it('drops an unrecognised platform rather than trusting it', () => {
    const result = readOAuthReturn({ connection: 'connected', platform: 'myspace' });
    expect(result?.platform).toBeNull();
  });

  it('takes the first entry when a key arrives repeated', () => {
    const result = readOAuthReturn({ connection: ['failed', 'connected'], reason: ['a', 'b'] });
    expect(result?.result).toBe('failed');
    expect(result?.reason).toBe('a');
  });

  it('is null for a navigation that is not a return at all', () => {
    expect(readOAuthReturn({})).toBeNull();
    expect(readOAuthReturn({ connection: '' })).toBeNull();
    // A third outcome nobody produces must not become a banner about an event that did not
    // happen.
    expect(readOAuthReturn({ connection: 'maybe' })).toBeNull();
    expect(readOAuthReturn({ platform: 'youtube' })).toBeNull();
  });
});

/** Walk a dot path into a catalog, returning undefined for a miss. */
function lookup(catalog: unknown, path: string): unknown {
  return path.split('.').reduce<unknown>((node, key) => {
    if (node && typeof node === 'object' && key in (node as Record<string, unknown>)) {
      return (node as Record<string, unknown>)[key];
    }
    return undefined;
  }, catalog);
}

describe('every reason has a real sentence in both languages', () => {
  const catalogs: Array<[string, unknown]> = [
    ['en', en],
    ['pl', pl],
  ];

  for (const [name, catalog] of catalogs) {
    it(`${name}: all fourteen named reasons resolve to prose`, () => {
      for (const reason of OAUTH_REASONS) {
        const value = lookup(catalog, oauthReasonKey(reason));
        expect(typeof value, `${name}/${reason}`).toBe('string');
        const sentence = String(value);
        expect(sentence.length, `${name}/${reason}`).toBeGreaterThan(20);
        // A key-shaped answer is exactly the failure mode this guards: a dotted identifier
        // rendered where a sentence belongs.
        expect(sentence, `${name}/${reason}`).not.toContain('publishing.oauth');
      }
    });

    it(`${name}: an unrecognised platform code still gets a sentence`, () => {
      const value = lookup(catalog, oauthReasonKey('server_error'));
      expect(typeof value).toBe('string');
      expect(String(value).length).toBeGreaterThan(20);
    });
  }
});

describe('every failure code has a real sentence in both languages', () => {
  // The publication's own eight, plus the hold codes and the four connection codes. The
  // resource carries no `failure_label`, so these are the frontend's faithful copies — and a
  // missing one would print a key beside a failure.
  const keys = [
    'publishing.failures.title_missing',
    'publishing.failures.publish_outcome_unknown',
    'publishing.failures.reconciled_absent',
    'publishing.failures.dispatch_failed',
    'publishing.failures.publish_worker_failed',
    'publishing.failures.reaper_stale',
    'publishing.failures.connection_needs_reauth',
    'publishing.failures.connection_disconnected',
    'publishing.failures.unknown',
    'publishing.connectionFailures.refresh_failed',
    'publishing.connectionFailures.refresh_unsupported',
    'publishing.connectionFailures.credentials_unreadable',
    'publishing.connectionFailures.disconnected_by_user',
  ];

  for (const [name, catalog] of [
    ['en', en],
    ['pl', pl],
  ] as Array<[string, unknown]>) {
    it(`${name}: resolves every code`, () => {
      for (const key of keys) {
        const value = lookup(catalog, key);
        expect(typeof value, `${name}/${key}`).toBe('string');
        expect(String(value).length, `${name}/${key}`).toBeGreaterThan(15);
      }
    });
  }
});
