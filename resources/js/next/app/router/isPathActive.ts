// isPathActive — prefix-aware active-route matching for nav items.
//
// A sidebar module link (e.g. '/bots') should stay highlighted while the user is
// on one of its child routes (e.g. '/bots/123', '/forms/5/submissions'). A bare
// `route.path === item.to` check drops the highlight the moment you leave the
// module index, so we prefix-match instead.
//
// The guard against false prefixes matters: '/forms' must NOT light up on
// '/formsX' — only on '/forms' itself or paths under the '/forms/' segment. We
// normalize a trailing slash on `to` so '/forms' and '/forms/' behave the same.
//
// Root ('/') is special-cased to an exact match: as a prefix it would match every
// path, so a '/'-target link only activates on '/' itself.
export function isPathActive(currentPath: string, to: string): boolean {
  if (currentPath === to) return true;
  // Root would prefix-match everything — only ever active on an exact '/'.
  if (to === '/') return false;
  // Normalize a trailing slash so the segment boundary is checked once.
  const base = to.endsWith('/') ? to.slice(0, -1) : to;
  if (currentPath === base) return true;
  return currentPath.startsWith(base + '/');
}
