// The `/next` paths that render BEFORE there is a session.
//
// WHY THIS IS A LIST AND NOT A LOOKUP ON THE ROUTER. The router already marks these records
// `meta: { public: true }`, and the navigation guard reads that meta directly — it has the
// route in hand. The one consumer that does not is the api client's 401 interceptor, and it
// cannot get one: `app/router` imports the auth store, which imports the api client, so an
// import of the router from `lib/api` would close that cycle at module-evaluation time. A
// PATHNAME is the one thing both sides can name without it, and `window.location.pathname` is
// already what the interceptor reads to avoid its own redirect loop.
//
// THE DUPLICATION IS PINNED RATHER THAN TOLERATED: `__tests__/publicRoute401.spec.ts` walks
// the real route records and fails if a `meta.public` route is not recognised here — or if a
// path here turns out to be behind `requiresAuth`.
//
// WHAT IT IS FOR, CONCRETELY. A 401 answered by throwing the browser at the login screen is a
// recovery inside the app and a DATA LOSS outside it: `/next/reset-password` carries
// `?token=&email=` from a mailed link, that pair arrives exactly once, and
// `window.location.assign()` discards it — the person is left on a login form they cannot use
// (they came because they forgot the password) with no way back to the link except the mail.
// `/next/invitations/<token>` has the same shape and inherited the same exposure.

/**
 * Path PREFIXES, rooted at the `/next` base (the shape of `window.location.pathname`), so a
 * parameterised public route such as `/next/invitations/<token>` is covered by its parent.
 */
export const PUBLIC_PATHS = [
  '/next/login',
  '/next/forgot-password',
  '/next/reset-password',
  '/next/invitations',
  '/next/_styleguide',
] as const;

/**
 * Does this pathname render a screen that needs no session?
 *
 * Matches a prefix only at a segment boundary, so `/next/login-as-someone-else` (were it ever
 * added) would not inherit public treatment from `/next/login`.
 */
export function isPublicPath(pathname: string): boolean {
  const path = pathname.replace(/\/+$/, '') || '/';

  return PUBLIC_PATHS.some((publicPath) => path === publicPath || path.startsWith(`${publicPath}/`));
}
