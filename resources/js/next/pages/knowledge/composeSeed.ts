// composeSeed — where "write this entry" goes, in ONE place.
//
// After the AI-only pivot (spec §25) an entry is never created by hand: every affordance that used
// to open the editor in create mode now opens the COMPOSER. There were ten such affordances across
// three screens, and `createGhost` was already copy-pasted into two of them (R17) — so the target
// location is built here and imported, rather than re-typed at each call site where one of them
// will eventually be missed.
//
// A route LOCATION, not a navigation: the caller pushes it. That keeps this module free of the
// router instance and makes every target assertable in a test without mounting anything.
import type { RouteLocationRaw } from 'vue-router';

/** The composer's route name — the single spelling of it in the module. */
export const COMPOSE_ROUTE = 'next.knowledge.base.compose';

/**
 * The composer, seeded or not.
 *
 * `seed` is a SLUG that was linked to but never written (a red link, an unknown reader URL, a ghost
 * node in the graph). The composer turns it into a starting sentence and explains where the user
 * came from — which is why the slug travels in the query rather than being silently dropped.
 *
 * `title` is optional and exists for one case: a name the composer could not place ("Kasia") is a
 * DISPLAY NAME, not a slug. The server normalises `seed` with `Str::slug()`, so passing the name
 * alone would work and would lose its capitalisation — the entry would be born as "kasia". Sending
 * the title alongside keeps the human spelling while the slug stays the address.
 */
export function composeLocation(
  baseId: string,
  seed?: string | null,
  title?: string | null,
): RouteLocationRaw {
  return {
    name: COMPOSE_ROUTE,
    params: { baseId },
    query: seed ? { seed, ...(title ? { seedTitle: title } : {}) } : {},
  };
}

/**
 * The composer, opened to AMEND an existing entry ("propose a change with AI").
 *
 * A different query key from `seed` on purpose: seeding starts from a slug that does NOT exist,
 * amending starts from an entry that does. Collapsing them would make the composer guess which of
 * the two it was handed.
 */
export function amendLocation(baseId: string, entryId: string): RouteLocationRaw {
  return { name: COMPOSE_ROUTE, params: { baseId }, query: { amend: entryId } };
}
