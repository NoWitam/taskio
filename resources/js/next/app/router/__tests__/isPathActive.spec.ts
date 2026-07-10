// isPathActive.spec.ts — prefix-aware active matching: exact hits, child routes
// keeping the module lit, false-prefix protection ('/formsX'), and the root guard.
import { describe, it, expect } from 'vitest';
import { isPathActive } from '../isPathActive';

describe('isPathActive', () => {
  it('matches an exact path', () => {
    expect(isPathActive('/bots', '/bots')).toBe(true);
  });

  it('keeps the module active on a child route', () => {
    expect(isPathActive('/bots/123/inbox', '/bots')).toBe(true);
    expect(isPathActive('/forms/5/submissions', '/forms')).toBe(true);
  });

  it('does not match a false prefix', () => {
    expect(isPathActive('/formsX', '/forms')).toBe(false);
  });

  it('does not match an unrelated path', () => {
    expect(isPathActive('/dashboard', '/bots')).toBe(false);
  });

  it('matches root only on an exact "/"', () => {
    expect(isPathActive('/', '/')).toBe(true);
    expect(isPathActive('/anything', '/')).toBe(false);
  });

  it('normalizes a trailing slash on the target', () => {
    expect(isPathActive('/forms', '/forms/')).toBe(true);
    expect(isPathActive('/forms/5', '/forms/')).toBe(true);
    expect(isPathActive('/formsX', '/forms/')).toBe(false);
  });
});
