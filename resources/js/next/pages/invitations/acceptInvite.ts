// Accept-invitation state machine (pure, unit-tested) for the public accept page.
//
// The public AcceptInvite page renders ONE of a few mutually-exclusive UIs driven
// entirely by the verified `GET /invitations/{token}` preview plus the viewer's
// auth state. This module isolates that branch resolution (and the structured-422
// `kind` → message-key mapping) from the Vue component so both can be tested
// without mounting anything.
//
// Backend preview contract (VERIFIED):
//   { workspace_name, invited_by_name, email, status, valid, account_exists }
//   unknown token → 404 (handled by the page as `not_found`, NOT here).

/** The public invitation preview (`GET /invitations/{token}`). */
export interface InvitationPreview {
  workspace_name: string | null;
  invited_by_name: string | null;
  email: string;
  /** The invitation lifecycle status (pending/accepted/revoked/expired). */
  status: string;
  /** True only when the invite is pending AND not expired (acceptable). */
  valid: boolean;
  /** Whether a user account already exists for the invited email. */
  account_exists: boolean;
}

/**
 * The resolved accept-page state.
 *   - invalid           : preview.valid === false (show a status message)
 *   - accept_only       : valid + authed user whose email matches → just "Accept"
 *   - login             : valid + an account exists (and not the authed match) → password
 *   - register          : valid + no account → name + password (email bound)
 */
export type AcceptStep = 'invalid' | 'accept_only' | 'login' | 'register';

/** Minimal auth view the resolver needs (decoupled from the Pinia store). */
export interface ViewerAuth {
  isAuthenticated: boolean;
  email: string | null | undefined;
}

/** Case-insensitive, trimmed email equality (the backend compares lower-cased). */
function emailsMatch(a: string | null | undefined, b: string | null | undefined): boolean {
  if (!a || !b) return false;
  return a.trim().toLowerCase() === b.trim().toLowerCase();
}

/**
 * Resolve which accept UI to render from the preview + the viewer's auth state.
 * Mirrors the backend's accept branching so the form we show matches the branch
 * the server will take (authed-match → no body; existing account → password;
 * new account → name+password).
 */
export function resolveAcceptStep(preview: InvitationPreview, viewer: ViewerAuth): AcceptStep {
  if (!preview.valid) return 'invalid';

  // An authenticated viewer whose email matches the invite joins with no body.
  if (viewer.isAuthenticated && emailsMatch(viewer.email, preview.email)) {
    return 'accept_only';
  }

  // Otherwise the branch depends purely on whether an account already exists.
  return preview.account_exists ? 'login' : 'register';
}

/**
 * The structured-422 `kind`s the accept endpoint can return, keyed by the field
 * the backend attaches them to (`email` | `password` | `token`). Exposed so the
 * page can pick the field-level vs. page-level placement.
 */
export type AcceptErrorKind =
  | 'email_mismatch'
  | 'login_required'
  | 'invalid_credentials'
  | 'registration_required'
  | 'expired'
  | 'invalid_invitation'
  | 'already_used';

/** A parsed accept error: the `kind` + the field it belongs on (for placement). */
export interface AcceptError {
  kind: AcceptErrorKind | 'generic';
  /** The validation field the kind was attached to, when known. */
  field: 'email' | 'password' | 'token' | null;
}

const FIELDS: Array<'email' | 'password' | 'token'> = ['email', 'password', 'token'];

const KNOWN_KINDS = new Set<string>([
  'email_mismatch',
  'login_required',
  'invalid_credentials',
  'registration_required',
  'expired',
  'invalid_invitation',
  'already_used',
]);

/**
 * Parse an accept 422 into a structured {@link AcceptError}. The backend attaches
 * exactly one raw key under one of `email`/`password`/`token`; we find the first
 * known key across those fields. Non-422 / unknown shapes → `{ kind: 'generic' }`.
 */
export function parseAcceptError(err: unknown): AcceptError {
  const res = (err as { response?: { status?: number; data?: { errors?: Record<string, string[]> } } })
    ?.response;
  if (res?.status === 422 && res.data?.errors) {
    for (const field of FIELDS) {
      const messages = res.data.errors[field] ?? [];
      const hit = messages.find((m) => KNOWN_KINDS.has(m));
      if (hit) return { kind: hit as AcceptErrorKind, field };
    }
  }
  return { kind: 'generic', field: null };
}

/** Map an {@link AcceptErrorKind} (or generic) to its i18n message key. */
export function acceptErrorMessageKey(kind: AcceptErrorKind | 'generic'): string {
  return `acceptInvite.errors.${kind}`;
}
