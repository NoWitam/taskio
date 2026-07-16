# Composable: useFilterTabs — Saved Views

File: `resources/js/next/app/composables/useFilterTabs.ts`

`useFilterTabs` bridges the `filterTabs` Pinia store (server state) with a page's
live filter refs. The page stays the single owner of its filter shape; it supplies
four pure adapter functions so the composable remains domain-agnostic.

---

## Signature

```ts
function useFilterTabs(
  context: string,
  adapters: UseFilterTabsAdapters,
): UseFilterTabsReturn
```

- `context` — a stable string identifier for the list screen, e.g. `"tasks"`.
  Must match the value sent to the backend `GET /api/filter-tabs?context=...`.

---

## The four adapters

### `serialize(): FilterSnapshot`

Build the snapshot object to persist for the current filter state.

Called by:
- `dirty` computed (every reactive tick to compare against the active view).
- `saveActive()` / `saveAs()` to produce the `filters` payload for the API.

The page is responsible for:
- Encoding date endpoints per the D1 convention (see below).
- Excluding the bucket (Active/Archive/Trash) — the bucket is view-state, not a
  saved filter (D3).
- Excluding null/empty/false values — absent keys are treated as "not set" by
  `normalize`.

**D1 date encoding.** A concrete deadline endpoint is persisted as one of:

```ts
// Fixed calendar day:
{ mode: 'absolute', date: 'YYYY-MM-DD' }

// Offset from today (whole-day local, computed at serialize time):
{ mode: 'relative', offset: <integer days> }
```

`offset` is computed as `Math.round((targetDate - today()) / 86_400_000)`. "Whole-day
local" means `today()` is midnight in the local timezone; fractional hours are
rounded. Presets (e.g. `"this_week"`) are stored as `date_preset: string` — no
`CodedDate` wrapper.

**D2 search.** `search` is serialized as a plain scalar string, not an array.

Example output (TasksView):

```ts
{
  search: 'refactor',
  priority: 'high',
  user_id: ['uuid-alice', 'uuid-bob'],
  labels: ['uuid-bug'],
  labelOperator: 'AND',
  date_from: { mode: 'relative', offset: 0 },   // today
  date_to:   { mode: 'absolute', date: '2026-12-31' },
}
```

---

### `apply(snapshot: FilterSnapshot): void`

Restore the page's filter refs from a persisted snapshot.

Called by `applyTab()` when the user activates a saved view.

The page is responsible for:
- Expanding relative dates: `{ mode: 'relative', offset: N }` → `toIsoDate(addDays(today(), N))`.
- Validating values before writing to refs (e.g. checking that a stored priority
  string is still a valid `TaskPriority`).
- Triggering seed resolution for multi-value fields (assignees, labels) so chips
  show real names immediately.

---

### `normalize(snapshot: FilterSnapshot): string[]`

Return a canonical, order-insensitive KEY SET for a snapshot. One entry per active
scalar, one entry per multi-value item. Keys must match the `key` prop of the chips
produced by `activeFilters` on the page.

Used by:
- `dirty` computed: compares `normalize(activeTab.filters)` vs `normalize(serialize())`.
- `decorateActiveFilters`: determines `tab-active` / `extra` / `tab-disabled` per chip.

Rules:
- Empty / null / false values must be omitted (they are not "active").
- Multi-value arrays produce one key per element, not one key for the array. Keys
  use a composite format, e.g. `user_id:uuid-alice`, `labels:uuid-bug`.
- Key names must be identical to the `key` prop on the corresponding chip in
  `activeFilters` — this is how the trit-state is matched.
- Reordering filter selections must NOT produce dirty state: the normalized set is
  compared as a Set, not an ordered array.

Example (TasksView):

```ts
function normalizeSnapshot(snap: FilterSnapshot): string[] {
  const keys: string[] = [];
  if (snap.search) keys.push('search');
  if (snap.priority) keys.push('priority');
  if (Array.isArray(snap.user_id))
    snap.user_id.map(String).sort().forEach((id) => keys.push(`user_id:${id}`));
  if (Array.isArray(snap.labels))
    snap.labels.map(String).sort().forEach((id) => keys.push(`labels:${id}`));
  if (snap.date_preset) keys.push('datePreset');
  if (decodeDate(snap.date_from)) keys.push('date_from');
  if (decodeDate(snap.date_to)) keys.push('date_to');
  if (snap.hide_without_deadline === true) keys.push('hideWithoutDeadline');
  return keys;
}
```

---

### `restoreValue(key: string, snapshot: FilterSnapshot): void`

Restore a SINGLE filter key from the active view's snapshot back into the live refs.

Called when the user clicks the "restore" affordance on a `tab-disabled` chip
(a filter that was in the saved view but has since been removed).

The page pattern-matches on `key` to decide which ref to update:

```ts
function restoreSnapshotValue(key: string, snap: FilterSnapshot): void {
  if (key.startsWith('user_id:')) {
    const id = key.slice('user_id:'.length);
    if (!assignees.value.includes(id)) assignees.value = [...assignees.value, id];
    void resolveSeedNames();
    return;
  }
  // ... etc.
}
```

---

## Return values

| Property / Method         | Type                              | Description                                                           |
|---------------------------|-----------------------------------|-----------------------------------------------------------------------|
| `tabs`                    | `Ref<FilterTab[]>`                | Reactive sorted list for this context.                                |
| `activeTabId`             | `Ref<string \| number \| null>`   | The active view id (`null` = none).                                   |
| `activeTab`               | `Ref<FilterTab \| null>`          | The active view object or `null`.                                     |
| `dirty`                   | `Ref<boolean>`                    | `true` when an active view exists and the live state differs from its snapshot. |
| `loading`                 | `Ref<boolean>`                    | `true` while the initial fetch is in flight.                          |
| `loadError`               | `Ref<boolean>`                    | `true` when the last fetch failed.                                    |
| `load()`                  | `() => Promise<void>`             | Fetch views for this context; sets `loading` / `loadError`.           |
| `applyTab(tab)`           | `(tab: FilterTab) => void`        | Apply the view's snapshot and mark it active.                         |
| `clearActive()`           | `() => void`                      | Deactivate (no active view).                                          |
| `saveActive()`            | `() => Promise<FilterTab>`        | Overwrite the active view with the current snapshot (Save button).    |
| `saveAs(name, icon)`      | `(string, string\|null) => Promise<FilterTab>` | Create a new view from the current snapshot (Save as).   |
| `restoreFilter(key)`      | `(key: string) => void`           | Delegate to `restoreValue(key, activeTab.filters)`.                   |
| `decorateActiveFilters(f)`| `(ActiveFilter[]) => ActiveFilter[]` | Annotate chips with trit-state; inject `tab-disabled` chips for missing snapshot keys. |

---

## How dirty works

```
dirty = activeTab !== null
     && Set(normalize(activeTab.filters)) !== Set(normalize(serialize()))
```

Comparison is Set-based (order-insensitive). Adding the same values in a different
order does not make the view dirty. An empty active view with an empty current state
is not dirty.

---

## How decorateActiveFilters works

1. Annotates each chip in the page's `activeFilters` array with `tabState`:
   - `tab-active` — key is in both the snapshot and the current state.
   - `extra` — key is only in the current state (added on top of the view).
   - `tab-disabled` — key is only in the snapshot (removed from current state).
2. Injects additional chips for snapshot-only keys that have no current chip at
   all (the chip's `label` falls back to the raw key; the page can relabel via
   `disabledChipLabel`).
3. When no view is active, returns the input unchanged — zero behavioral
   regression for filter bars without saved views.

---

## Store dependency

`useFilterTabs` reads from and writes to `useFilterTabsStore` (Pinia store at
`resources/js/next/app/stores/filterTabs.ts`). The store:

- Maintains server state per context in `tabsByContext`.
- Handles optimistic reorder with rollback.
- Normalizes all 422 errors into `FilterTabError { status, fieldErrors, message }`.
- Uses request tokens to discard stale in-flight responses after a refetch.
