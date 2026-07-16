// pageContext — the dynamic page-context label (the open entity's name) the
// Navbar breadcrumb renders next to the module title (Batch 4+6 sets it, Batch 2
// consumes it).
//
// Why not `route.meta.contextLabel`: meta is merged from the route RECORDS at
// navigation time and vue-router's `useRoute()` proxy only tracks record-level
// changes — a label assigned onto the merged meta object after the entity loads
// would not reactively reach the app shell (and would leak into unrelated
// navigations to the same record). A module-level ref is the smallest reactive
// carrier that keeps AppLayout free of feature-store imports.
//
// Module layouts own the value: they SET it when their entity loads (an
// immediate watch, synchronous in setup) and CLEAR it in `onBeforeUnmount` —
// NOT `onUnmounted`: on a cross-module layout swap the outgoing layout's
// onUnmounted is deferred post-flush and would run AFTER the incoming layout's
// immediate watch, wiping a label that was just set for a cached detail.
// onBeforeUnmount is synchronous during unmount, which happens before the new
// layout's setup, so the set always wins.
import { ref } from 'vue';

export const pageContextLabel = ref<string | null>(null);

export function setPageContextLabel(label: string | null): void {
  pageContextLabel.value = label;
}
