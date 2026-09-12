// Password-reset screen logic (pure, unit-tested) for the two public reset pages.
//
// Same split as `pages/invitations/acceptInvite.ts`: everything that decides WHAT the
// screen says lives here, so it can be tested without mounting a component. The Vue files
// keep only markup + the two HTTP calls.
//
// Backend contract (VERIFIED against app/modules/Auth):
//   POST /auth/forgot-password  { email }
//        → ALWAYS 200 { message } for any well-formed address, known or not. 422 only for a
//          malformed address; 429 when the per-IP bucket is spent.
//   POST /auth/reset-password   { token, email, password, password_confirmation }
//        → 200 { message, token, user, permissions, workspaces, current_workspace }
//        → 422 { errors: { token: [...] } }    invalid / expired / already-used link
//        → 422 { errors: { password: [...] } } password rules
//
// NOTE ON ERROR MAPPING: we branch on WHICH FIELD carries the error, never on the server's
// sentence. The server answers in the user's language (SetUserLocale), so matching its prose
// would break the moment somebody switches language — and the frontend owns its own copy.

/** Minimum password length. Mirrors ResetPasswordRequest (`min:8`), which is also what the
 *  change-password and registration forms enforce — three doors onto one field, one rule. */
export const MIN_PASSWORD_LENGTH = 8;

/** The pair the reset form must post back, as carried by the mailed link's query string. */
export interface ResetLinkParams {
  token: string;
  email: string;
}

/** A router query as Vue Router hands it over. */
export type RouteQuery = Record<string, unknown>;

function readQueryString(query: RouteQuery, key: string): string | null {
  const raw = query[key];
  // A repeated parameter arrives as an array. Treat it as absent rather than guessing which
  // copy was meant: a half-read link should send the user back for a fresh one, not submit a
  // token nobody chose.
  return typeof raw === 'string' && raw.length > 0 ? raw : null;
}

/**
 * Read `?token=&email=` out of the reset link. Returns null when either half is missing, which
 * is the page's "this link is incomplete — request a new one" state. Both halves are required
 * because the broker verifies them together (the token is stored hashed, keyed by address).
 */
export function parseResetLink(query: RouteQuery): ResetLinkParams | null {
  const token = readQueryString(query, 'token');
  const email = readQueryString(query, 'email');

  return token !== null && email !== null ? { token, email } : null;
}

/** Where a reset error belongs on the form (null = the page-level Alert). */
export type ResetErrorField = 'token' | 'password' | 'email' | null;

export interface ResetError {
  field: ResetErrorField;
  /** i18n key for the message to render. */
  key: string;
}

interface ErrorShape {
  response?: {
    status?: number;
    data?: { errors?: Record<string, unknown> };
  };
}

function statusOf(err: unknown): number | undefined {
  return (err as ErrorShape)?.response?.status;
}

function fieldsOf(err: unknown): string[] {
  const errors = (err as ErrorShape)?.response?.data?.errors;
  return errors && typeof errors === 'object' ? Object.keys(errors) : [];
}

/**
 * Map a failed `POST /auth/reset-password` onto a message + its placement.
 *
 * The `token` case is the one that matters: the backend deliberately answers the SAME thing
 * for an invalid, an expired and an already-spent link (telling them apart would say whether
 * the address has an account), so the copy has to cover all three and offer the only useful
 * next step — ask for a new link.
 */
export function parseResetError(err: unknown): ResetError {
  const status = statusOf(err);

  if (status === 429) {
    return { field: null, key: 'auth.tooManyAttempts' };
  }

  if (status === 422) {
    const fields = fieldsOf(err);

    // Token first: it invalidates the whole attempt, whereas a password complaint is fixable
    // in place. If both arrive, the link is what has to be dealt with.
    if (fields.includes('token')) {
      return { field: 'token', key: 'auth.reset.errors.invalidLink' };
    }
    if (fields.includes('password')) {
      return { field: 'password', key: 'auth.reset.errors.rejected' };
    }
    if (fields.includes('email')) {
      return { field: 'email', key: 'auth.reset.errors.invalidLink' };
    }
  }

  return { field: null, key: 'auth.genericError' };
}

/**
 * Map a failed `POST /auth/forgot-password`.
 *
 * There is no "unknown address" case to map, by design: the endpoint answers 200 for every
 * well-formed address. Only a malformed one (422) and a spent rate-limit bucket (429) are
 * real outcomes here.
 */
export function parseForgotError(err: unknown): string {
  const status = statusOf(err);

  if (status === 429) return 'auth.tooManyAttempts';
  if (status === 422) return 'auth.forgot.errors.invalidEmail';

  return 'auth.genericError';
}

/**
 * Client-side check of the new password, mirroring the server's `min:8` + `confirmed`.
 *
 * Not a security control — the server re-checks both — but it keeps the two failures the user
 * can actually fix ("too short", "the two do not match") distinguishable. Once submitted, the
 * server reports them under one field, so telling them apart has to happen here.
 *
 * @returns the i18n key of the problem, or null when the pair is acceptable.
 */
export function validateNewPassword(password: string, confirmation: string): string | null {
  if (password.length < MIN_PASSWORD_LENGTH) return 'auth.reset.errors.tooShort';
  if (password !== confirmation) return 'auth.reset.errors.mismatch';

  return null;
}
