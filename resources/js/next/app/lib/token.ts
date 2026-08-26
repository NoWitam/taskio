// The persisted login token — in its own module ON PURPOSE.
//
// It lives here rather than in `api.ts` because callers outside the HTTP client need to ask "is anyone
// logged in?" without importing the client itself. `app/i18n` is the case that forced it: it must not
// PUT a language preference for an anonymous visitor (the api client's 401 interceptor navigates to the
// login page, and the invite-accept screen is a `public: true` route carrying a language switcher).
//
// Importing that question from `lib/api` looked equivalent and was not. Dozens of specs replace
// `lib/api` with a partial `vi.mock` factory listing only the HTTP methods they need, so a second
// export read at runtime came back `undefined` and threw inside a language switch — 195 tests, none of
// them about locale. A module with nothing worth mocking cannot be half-mocked.
//
// The key itself is the one the verified backend auth contract uses; `api.ts` re-exports it so the
// existing importers (`stores/auth`, `lib/echo`) are unaffected.

/** localStorage key of the login token. */
export const TOKEN_KEY = 'taskio_token';

/** The persisted bearer token, or null when storage is unavailable or empty. */
export function authToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
}

/**
 * Is anyone logged in on this client?
 *
 * For callers that must not fire an authenticated request speculatively. Storage being unreadable
 * (private mode) answers "no", which is the safe direction: the request is skipped, never forced.
 *
 * AN EMPTY STRING IS NOT A TOKEN. `getItem` returns `''`, not `null`, for a key stored empty, and
 * `api.ts` already treats an empty bearer as absent (`if (bearer)`) — so answering "logged in" here
 * would send exactly the unauthenticated request this function exists to prevent: a PUT with no
 * `Authorization`, a 401, and the interceptor navigating an anonymous visitor away from the page they
 * were invited to. No production path writes an empty token; the two readers agreeing is the point.
 */
export function hasAuthToken(): boolean {
  const token = authToken();

  return token !== null && token !== '';
}
