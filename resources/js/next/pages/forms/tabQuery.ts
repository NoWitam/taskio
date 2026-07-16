// tabQuery — the bucket-tab (Active/Trash) ↔ `?tab=` sync helpers shared by the
// FormSubmissionsView + FormReportsView sub-views. Mirrors the FormsView pattern:
// hydrate the tab from the URL on mount (unknown → default), and serialize it back
// while PRESERVING every other query key (drawer overlays like ?fill / ?edit) and
// OMITTING the default value 'active' so a plain URL stays clean.
import type { LocationQuery, LocationQueryRaw } from 'vue-router';

export type BucketTab = 'active' | 'trash';

// Read a single query value (Vue Router hands arrays for repeated keys).
function str(v: unknown): string {
  return Array.isArray(v) ? String(v[0] ?? '') : String(v ?? '');
}

// Hydrate the bucket tab from the current query; any unknown value → 'active'.
export function hydrateTab(query: LocationQuery): BucketTab {
  return str(query.tab) === 'trash' ? 'trash' : 'active';
}

// Serialize the tab back onto a copy of the existing query: keep ALL other keys,
// set `tab=trash` only for the non-default bucket (drop the key otherwise).
export function serializeTabQuery(query: LocationQuery, tab: BucketTab): LocationQueryRaw {
  const next: LocationQueryRaw = { ...query };
  if (tab === 'trash') next.tab = 'trash';
  else delete next.tab;
  return next;
}
