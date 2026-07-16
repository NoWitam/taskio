// sectionRedirect — legacy `?section=` → child-route redirect target.
//
// The bots / workflows detail sections used to live in a `?section=` query key on
// a single detail record; they are child routes now (`next.<module>.detail.<section>`).
// The bare `:id` record keeps the old route NAME and redirects here so both named
// pushes (no section) and old `?section=` deep links land on the right child.
//
// The section value may arrive as an array (`?section=a&section=b`) — only the
// first entry counts. An unknown / missing section falls back to the module's
// default. Every OTHER query key (run, run_detail, state, origin, bot, workflow,
// fill, edit, …) is preserved verbatim; only `section` itself is dropped, since
// the child route now carries that information in its name.
import type { LocationQuery, RouteParams } from 'vue-router';

/** The slice of a route location the redirect needs (structural, test-friendly). */
export interface SectionRedirectSource {
  params: RouteParams;
  query: LocationQuery;
}

export function sectionRedirect(
  to: SectionRedirectSource,
  namePrefix: string,
  sections: readonly string[],
  fallback: string,
): { name: string; params: RouteParams; query: LocationQuery } {
  const raw = to.query.section;
  const value = Array.isArray(raw) ? raw[0] : raw;
  const section = typeof value === 'string' && sections.includes(value) ? value : fallback;
  const query: LocationQuery = { ...to.query };
  delete query.section;
  return { name: namePrefix + section, params: to.params, query };
}
