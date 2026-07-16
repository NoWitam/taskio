# Integration Guide: Connecting a View to Saved Views

This guide explains step by step how to wire up a new list screen to the Saved
Views feature (FilterTabs Stage 2). `TasksView.vue` is used as the reference
implementation.

---

## Prerequisites

The list screen must already have:
- Its own filter refs (`ref<...>`).
- An `activeFilters` computed that builds `ActiveFilter[]` for `FilterBar`.
- `FilterBar` rendering those chips.

---

## Step 1 — Choose a context string

Pick a short, stable string that identifies the list screen:

```ts
const SAVED_VIEWS_CONTEXT = 'tasks';
```

This string is sent to the backend as `?context=tasks` and scopes all saved views
to this screen. It must not change once views have been saved by users.

---

## Step 2 — Write the four adapters

### serialize

Produce a plain object from the current filter refs. Encode date endpoints per D1.
Omit the bucket (D3). Omit null/empty/false values.

```ts
function serializeFiltersSnapshot(): FilterSnapshot {
  const snap: FilterSnapshot = {};
  if (search.value) snap.search = search.value;
  if (priority.value) snap.priority = priority.value;
  if (assignees.value.length) snap.user_id = [...assignees.value];
  // D1: encode dates (absolute or relative based on the last chosen mode)
  const from = codeDate(deadline.value.from, pendingDateModes.value.from);
  if (from) snap.date_from = from;
  // ...
  return snap;
}
```

### apply

Restore the refs from a snapshot. Expand relative dates. Trigger seed resolution
for multi-value fields.

```ts
function applyFiltersSnapshot(snap: FilterSnapshot): void {
  search.value = typeof snap.search === 'string' ? snap.search : '';
  priority.value = /* validate + cast */ null;
  assignees.value = Array.isArray(snap.user_id) ? snap.user_id.map(String) : [];
  deadline.value = {
    preset: /* validate preset */ '',
    from: decodeDate(snap.date_from),
    to: decodeDate(snap.date_to),
    hide_without_deadline: snap.hide_without_deadline === true,
  };
  void resolveSeedNames();
}
```

### normalize

Return a sorted string array of active keys. Multi-value fields expand to one key
per element. Keys must match the `key` props on the `ActiveFilter` chips.

```ts
function normalizeSnapshot(snap: FilterSnapshot): string[] {
  const keys: string[] = [];
  if (snap.search) keys.push('search');
  if (snap.priority) keys.push('priority');
  if (Array.isArray(snap.user_id))
    snap.user_id.map(String).sort().forEach((id) => keys.push(`user_id:${id}`));
  // etc.
  return keys;
}
```

### restoreValue

Restore one chip key from the snapshot into the live refs. Pattern-match on `key`.

```ts
function restoreSnapshotValue(key: string, snap: FilterSnapshot): void {
  if (key.startsWith('user_id:')) {
    const id = key.slice('user_id:'.length);
    if (!assignees.value.includes(id)) assignees.value = [...assignees.value, id];
    void resolveSeedNames();
    return;
  }
  if (key === 'search') { search.value = String(snap.search ?? ''); return; }
  // etc.
}
```

---

## Step 3 — Call useFilterTabs

```ts
import { useFilterTabs, type FilterSnapshot } from '../../app/composables/useFilterTabs';

const savedViews = useFilterTabs(SAVED_VIEWS_CONTEXT, {
  serialize: serializeFiltersSnapshot,
  apply: applyFiltersSnapshot,
  normalize: normalizeSnapshot,
  restoreValue: restoreSnapshotValue,
});
```

---

## Step 4 — Decorate activeFilters with trit-state

Replace the bare `activeFilters` computed passed to `FilterBar` with a decorated
version:

```ts
const decoratedFilters = computed<ActiveFilter[]>(() => {
  const decorated = savedViews.decorateActiveFilters(activeFilters.value);
  // Optional: relabel injected tab-disabled chips whose label === key
  return decorated.map((f) =>
    f.tabState === 'tab-disabled' && f.label === f.key
      ? { ...f, label: disabledChipLabel(f.key) ?? f.label }
      : f,
  );
});
```

`disabledChipLabel` reads `savedViews.activeTab.value?.filters` and maps the raw
key to a human-readable string (e.g. `user_id:uuid-alice` → `"Assignee: Alice"`).

---

## Step 5 — Mount FilterTabBar above FilterBar

```html
<FilterTabBar
  :tabs="savedViews.tabs.value"
  :active-tab-id="savedViews.activeTabId.value"
  :dirty="savedViews.dirty.value"
  :loading="savedViews.loading.value"
  :error="savedViews.loadError.value"
  :has-active-filters="hasActiveFilters"
  :busy="savingView"
  @activate="onActivateView"
  @save="onSaveActiveView"
  @save-as="onSaveAs"
  @edit="onEditView"
  @delete="onDeleteView"
  @move-up="(tab) => moveView(tab, -1)"
  @move-down="(tab) => moveView(tab, 1)"
  @retry="savedViews.load"
/>

<FilterBar
  v-model:search="search"
  :active-filters="decoratedFilters"
  @remove-filter="removeFilter"
  @clear-all="clearAll"
  @restore-filter="onRestoreFilter"
/>
```

DOM order: `FilterTabBar` is always placed immediately above `FilterBar`, below
`PageHeader`.

---

## Step 6 — Handle restore-filter from FilterBar

```ts
function onRestoreFilter(key: string): void {
  savedViews.restoreFilter(key);
}
```

`FilterBar` emits `restore-filter` when the user clicks the rotate-ccw button on
a `tab-disabled` chip.

---

## Step 7 — Mount SaveViewModal and ConfirmDialogs

The page owns three overlays:

1. `SaveViewModal` — for create (Save as…) and edit (kebab → Edit).
2. `ConfirmDialog` (danger variant) — for delete.
3. `ConfirmDialog` (discard guard) — built into `FilterTabBar`; no page-level
   markup needed.

```html
<SaveViewModal
  v-model:open="saveModalOpen"
  :mode="saveModalMode"
  :initial-name="editingView?.name ?? ''"
  :initial-icon="editingView?.icon ?? null"
  :snapshot-from="deadline.value.from"
  :snapshot-to="deadline.value.to"
  :submitting="savingView"
  :name-error="saveModalNameError"
  @submit="onSaveModalSubmit"
/>

<ConfirmDialog
  v-model:open="deleteConfirmOpen"
  variant="danger"
  :title="t('tasks.savedViews.confirm.deleteTitle')"
  :message="deleteMessage"
  :confirm-label="t('common.delete')"
  :cancel-label="t('common.cancel')"
  :loading="savingView"
  @confirm="onConfirmDeleteView"
/>
```

---

## Step 8 — Handle save modal submit

```ts
async function onSaveModalSubmit(payload: SaveViewSubmit): Promise<void> {
  saveModalNameError.value = null;
  pendingDateModes.value = payload.dateModes; // D1: store mode for next serialize()
  savingView.value = true;
  const iconEnum = toIconEnumValue(payload.icon); // R1: map next glyph → IconEnum
  try {
    if (saveModalMode.value === 'edit' && editingView.value) {
      await filterTabsStore.update(SAVED_VIEWS_CONTEXT, editingView.value.id, {
        name: payload.name,
        icon: iconEnum,
        filters: serializeFiltersSnapshot(), // serialize NOW (date mode is set)
      });
      toast.success(t('tasks.savedViews.toast.updated'));
    } else {
      await savedViews.saveAs(payload.name, iconEnum);
      toast.success(t('tasks.savedViews.toast.created'));
    }
    saveModalOpen.value = false;
  } catch (err) {
    const e = err as { fieldErrors?: Record<string, string> };
    const nameKey = e.fieldErrors?.name ?? null;
    if (nameKey) {
      saveModalNameError.value = nameKey; // keep modal open, show inline error
    } else {
      toast.danger(t('tasks.savedViews.toast.saveError'));
    }
  } finally {
    savingView.value = false;
    pendingDateModes.value = { from: 'absolute', to: 'absolute' }; // reset
  }
}
```

Key points:
- `toIconEnumValue(payload.icon)` maps the chosen next glyph to the `IconEnum`
  value the backend expects (R1).
- `pendingDateModes` must be set BEFORE calling `serializeFiltersSnapshot()`.
- `saveModalNameError` surfaces the raw i18n key from the backend 422; the modal
  passes it to `t()` before displaying.

---

## Step 9 — Load on mount

```ts
onMounted(async () => {
  hydrateFromQuery();
  await savedViews.load();
  refetchVisible();
});
```

`savedViews.load()` fetches views from the backend for the given context and
populates `savedViews.tabs`. Call it once on mount.

---

## Excluding the bucket (D3)

The Active/Archive/Trash `tab` ref must NEVER appear in `serializeFiltersSnapshot`.
It is view-state, not a saved filter. When a saved view is applied, the bucket stays
wherever it was.

---

## Date mode lifecycle (D1)

1. `pendingDateModes` defaults to `{ from: 'absolute', to: 'absolute' }`.
2. The user opens SaveViewModal, picks "relative" for `date_from`.
3. On submit: the page sets `pendingDateModes.value = payload.dateModes`.
4. The page then calls `serializeFiltersSnapshot()` — `codeDate` uses
   `pendingDateModes.value.from` to encode.
5. After the API call (success or failure), `pendingDateModes` is reset to
   `'absolute'/'absolute'` so that dirty-state comparisons use the default.

Live dirty-state comparison ignores the mode (it only compares key presence), so
a default of `'absolute'` is harmless outside of an explicit save.

---

## Reorder via move-up / move-down

```ts
async function moveView(tab: FilterTab, dir: -1 | 1): Promise<void> {
  const list = savedViews.tabs.value;
  const idx = list.findIndex((t) => String(t.id) === String(tab.id));
  const target = idx + dir;
  if (idx < 0 || target < 0 || target >= list.length) return;
  const ids = list.map((t) => t.id);
  [ids[idx], ids[target]] = [ids[target], ids[idx]];
  try {
    await filterTabsStore.reorder(SAVED_VIEWS_CONTEXT, ids);
    toast.success(t('tasks.savedViews.toast.reordered'));
  } catch (err) {
    const key = (err as { fieldErrors?: Record<string, string> }).fieldErrors?.ids ?? null;
    toast.danger(key ? t(key) : t('tasks.savedViews.errors.invalidReorder'));
    void savedViews.load(); // refetch authoritative order on invalid set
  }
}
```

The store performs an optimistic local reorder before the API call and rolls back
on failure.
