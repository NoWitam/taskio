// Unit tests for the accept-invitation state machine (pure module). It resolves
// which UI the public accept page renders from the preview + the viewer's auth
// state (mirroring the backend's accept branching), and parses the structured 422
// `kind` (+ its field) so the page can place each message correctly.
import { describe, expect, it } from 'vitest';
import {
  resolveAcceptStep,
  parseAcceptError,
  acceptErrorMessageKey,
  type InvitationPreview,
} from '../acceptInvite';

function preview(overrides: Partial<InvitationPreview> = {}): InvitationPreview {
  return {
    workspace_name: 'Acme',
    invited_by_name: 'Owner',
    email: 'invitee@example.com',
    status: 'pending',
    valid: true,
    account_exists: false,
    ...overrides,
  };
}

describe('resolveAcceptStep', () => {
  it('returns "invalid" when the preview is not valid', () => {
    const step = resolveAcceptStep(preview({ valid: false, status: 'expired' }), {
      isAuthenticated: false,
      email: null,
    });
    expect(step).toBe('invalid');
  });

  it('returns "accept_only" for an authed viewer whose email matches (case-insensitive)', () => {
    const step = resolveAcceptStep(preview({ email: 'Invitee@Example.com' }), {
      isAuthenticated: true,
      email: 'invitee@example.com',
    });
    expect(step).toBe('accept_only');
  });

  it('returns "login" when an account exists and the authed viewer is NOT the match', () => {
    // Authed but a different email → falls through to the account-exists branch.
    const step = resolveAcceptStep(preview({ account_exists: true }), {
      isAuthenticated: true,
      email: 'someone-else@example.com',
    });
    expect(step).toBe('login');
  });

  it('returns "login" for an unauthenticated viewer when an account exists', () => {
    const step = resolveAcceptStep(preview({ account_exists: true }), {
      isAuthenticated: false,
      email: null,
    });
    expect(step).toBe('login');
  });

  it('returns "register" for an unauthenticated viewer with no account', () => {
    const step = resolveAcceptStep(preview({ account_exists: false }), {
      isAuthenticated: false,
      email: null,
    });
    expect(step).toBe('register');
  });

  it('does not treat an authed viewer with a missing email as a match', () => {
    const step = resolveAcceptStep(preview({ account_exists: true }), {
      isAuthenticated: true,
      email: undefined,
    });
    expect(step).toBe('login');
  });
});

describe('parseAcceptError', () => {
  it('parses email_mismatch from the email field', () => {
    const parsed = parseAcceptError({
      response: { status: 422, data: { errors: { email: ['email_mismatch'] } } },
    });
    expect(parsed).toEqual({ kind: 'email_mismatch', field: 'email' });
  });

  it.each([
    ['login_required'],
    ['invalid_credentials'],
    ['registration_required'],
  ])('parses %s from the password field', (kind) => {
    const parsed = parseAcceptError({
      response: { status: 422, data: { errors: { password: [kind] } } },
    });
    expect(parsed).toEqual({ kind, field: 'password' });
  });

  it.each([['expired'], ['invalid_invitation'], ['already_used']])(
    'parses %s from the token field',
    (kind) => {
      const parsed = parseAcceptError({
        response: { status: 422, data: { errors: { token: [kind] } } },
      });
      expect(parsed).toEqual({ kind, field: 'token' });
    },
  );

  it('returns generic for a non-422', () => {
    expect(parseAcceptError({ response: { status: 500 } })).toEqual({ kind: 'generic', field: null });
  });

  it('returns generic for a 422 with no known kind', () => {
    expect(
      parseAcceptError({ response: { status: 422, data: { errors: { email: ['weird'] } } } }),
    ).toEqual({ kind: 'generic', field: null });
  });
});

describe('acceptErrorMessageKey', () => {
  it('namespaces the kind under acceptInvite.errors', () => {
    expect(acceptErrorMessageKey('email_mismatch')).toBe('acceptInvite.errors.email_mismatch');
    expect(acceptErrorMessageKey('generic')).toBe('acceptInvite.errors.generic');
  });
});
