// Unit tests for the password-reset screen logic (pure module).
//
// The two properties worth pinning here are both about NOT KNOWING MORE THAN THE SERVER SAYS:
//   - an invalid, an expired and an already-used link are ONE message, because the backend
//     answers identically for all three (telling them apart would reveal whether the address
//     has an account),
//   - the forgot screen has no "unknown address" branch to map at all.
//
// Plus the mechanical half: reading `?token=&email=` out of the mailed link, and mirroring the
// server's password rules so the two fixable problems stay distinguishable.
import { describe, expect, it } from 'vitest';
import {
  MIN_PASSWORD_LENGTH,
  parseForgotError,
  parseResetError,
  parseResetLink,
  validateNewPassword,
  type RouteQuery,
} from '../passwordReset';

/** An axios-shaped rejection. */
function httpError(status: number, errors?: Record<string, string[]>): unknown {
  return { response: { status, data: errors ? { errors } : {} } };
}

describe('parseResetLink', () => {
  it('reads the token and email the mailed link carries', () => {
    const query: RouteQuery = { token: 'a-plaintext-token', email: 'user@example.com' };

    expect(parseResetLink(query)).toEqual({
      token: 'a-plaintext-token',
      email: 'user@example.com',
    });
  });

  it('returns null when either half is missing', () => {
    expect(parseResetLink({ token: 'only-a-token' })).toBeNull();
    expect(parseResetLink({ email: 'only@example.com' })).toBeNull();
    expect(parseResetLink({})).toBeNull();
  });

  it('treats an empty value as missing', () => {
    expect(parseResetLink({ token: '', email: 'user@example.com' })).toBeNull();
    expect(parseResetLink({ token: 'a-token', email: '' })).toBeNull();
  });

  it('treats a repeated parameter as missing rather than guessing which copy was meant', () => {
    // `?token=a&token=b` arrives as an array. Submitting one of them at random would spend a
    // token nobody chose; sending the user back for a fresh link is the honest outcome.
    expect(parseResetLink({ token: ['a', 'b'], email: 'user@example.com' })).toBeNull();
    expect(parseResetLink({ token: 'a-token', email: ['x@y.z', 'p@q.r'] })).toBeNull();
  });

  it('ignores anything else in the query', () => {
    const query: RouteQuery = {
      token: 'a-token',
      email: 'user@example.com',
      redirect: '/dashboard',
      utm_source: 'mail',
    };

    expect(parseResetLink(query)).toEqual({ token: 'a-token', email: 'user@example.com' });
  });
});

describe('parseResetError', () => {
  it('maps a token error to the one message that covers invalid, expired and used', () => {
    const parsed = parseResetError(httpError(422, { token: ['whatever the server said'] }));

    expect(parsed).toEqual({ field: 'token', key: 'auth.reset.errors.invalidLink' });
  });

  it('maps the token error WITHOUT reading the server sentence', () => {
    // The server answers in the user's language (SetUserLocale), so matching its prose would
    // break on a language switch. Two different sentences under the same field must map alike.
    const english = parseResetError(httpError(422, { token: ['This password reset token is invalid.'] }));
    const polish = parseResetError(httpError(422, { token: ['Ten link do zmiany hasła jest nieprawidłowy.'] }));

    expect(english).toEqual(polish);
  });

  it('places a password complaint on the password field', () => {
    const parsed = parseResetError(httpError(422, { password: ['too short'] }));

    expect(parsed).toEqual({ field: 'password', key: 'auth.reset.errors.rejected' });
  });

  it('prefers the token over the password when both arrive', () => {
    // A dead link invalidates the whole attempt; a password complaint is fixable in place.
    const parsed = parseResetError(httpError(422, { token: ['dead'], password: ['short'] }));

    expect(parsed.field).toBe('token');
  });

  it('treats an email complaint as a dead link', () => {
    // The pair is verified together, so a refused address means the link is not usable —
    // there is no email field on the form to correct.
    const parsed = parseResetError(httpError(422, { email: ['invalid'] }));

    expect(parsed).toEqual({ field: 'email', key: 'auth.reset.errors.invalidLink' });
  });

  it('maps a spent rate-limit bucket to the page level', () => {
    expect(parseResetError(httpError(429))).toEqual({
      field: null,
      key: 'auth.tooManyAttempts',
    });
  });

  it('falls back to the generic message for anything unrecognised', () => {
    expect(parseResetError(httpError(500))).toEqual({ field: null, key: 'auth.genericError' });
    expect(parseResetError(new Error('network'))).toEqual({
      field: null,
      key: 'auth.genericError',
    });
    expect(parseResetError(undefined)).toEqual({ field: null, key: 'auth.genericError' });
    // A 422 with no recognised field is still not a claim about the link.
    expect(parseResetError(httpError(422, { something_else: ['x'] }))).toEqual({
      field: null,
      key: 'auth.genericError',
    });
  });
});

describe('parseForgotError', () => {
  it('has no branch for an unknown address, because the endpoint has no such answer', () => {
    // Every well-formed address gets a 200. The only errors that can reach this mapper are a
    // malformed address and a spent bucket — if a third ever appears, the endpoint has started
    // telling callers something it must not.
    expect(parseForgotError(httpError(422))).toBe('auth.forgot.errors.invalidEmail');
    expect(parseForgotError(httpError(429))).toBe('auth.tooManyAttempts');
    expect(parseForgotError(httpError(500))).toBe('auth.genericError');
    expect(parseForgotError(new Error('network'))).toBe('auth.genericError');
  });
});

describe('validateNewPassword', () => {
  it('accepts a password at the minimum length that matches its confirmation', () => {
    const ok = 'x'.repeat(MIN_PASSWORD_LENGTH);

    expect(validateNewPassword(ok, ok)).toBeNull();
  });

  it('rejects one character below the minimum', () => {
    const short = 'x'.repeat(MIN_PASSWORD_LENGTH - 1);

    expect(validateNewPassword(short, short)).toBe('auth.reset.errors.tooShort');
  });

  it('mirrors the server rule (min:8), so the boundary is not invented here', () => {
    expect(MIN_PASSWORD_LENGTH).toBe(8);
  });

  it('reports a mismatch separately from a length problem', () => {
    expect(validateNewPassword('a-long-enough-password', 'a-different-password')).toBe(
      'auth.reset.errors.mismatch',
    );
  });

  it('reports the length problem first when both are wrong', () => {
    // Telling somebody their passwords do not match, when the real problem is that neither is
    // long enough, sends them fixing the wrong field.
    expect(validateNewPassword('short', 'other')).toBe('auth.reset.errors.tooShort');
  });

  it('rejects an empty pair', () => {
    expect(validateNewPassword('', '')).toBe('auth.reset.errors.tooShort');
  });
});
