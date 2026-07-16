# Saved Views — Filter Snapshot Format

The `filters` field of a saved view is an opaque JSON object. Its schema is owned
by the page, not the backend. The backend validates only the serialized byte size
(<= 16 384 bytes). This document records the conventions followed by `TasksView`.

---

## Snapshot keys (TasksView context: "tasks")

| Key               | Type                  | Convention |
|-------------------|-----------------------|------------|
| `search`          | `string`              | D2: plain scalar, never array. |
| `priority`        | `string`              | TaskPriority enum value. |
| `user_id`         | `string[]`            | Array of user UUIDs. |
| `labels`          | `string[]`            | Array of label IDs. |
| `labelOperator`   | `'AND' \| 'OR'`       | Present only when `labels` is set. |
| `date_preset`     | `string`              | One of the preset IDs; no `CodedDate` wrapper. |
| `date_from`       | `CodedDate \| null`   | D1: absolute or relative (see below). |
| `date_to`         | `CodedDate \| null`   | D1: absolute or relative (see below). |
| `hide_without_deadline` | `true`         | Present only when the toggle is on. |

**D3 — bucket excluded.** The Active/Archive/Trash tab value is NOT included in the
snapshot. It is view-state, not a saved filter. Applying a view does not change which
tab is active.

---

## D1 — Date encoding (CodedDate)

```ts
type CodedDate =
  | null
  | { mode: 'absolute'; date: string }   // fixed YYYY-MM-DD
  | { mode: 'relative'; offset: number } // integer days from today
```

### Absolute

`{ mode: 'absolute', date: '2026-12-31' }` — the date is permanently that calendar
day. The offset is not recomputed.

### Relative

`{ mode: 'relative', offset: 0 }` — "today". `offset: -7` — "7 days ago".
`offset: 14` — "14 days from now".

**Encoding (serialize):**
```ts
const offset = Math.round((targetDate.getTime() - today().getTime()) / 86_400_000);
```

`today()` is midnight in the browser's local timezone. Fractional hours (DST
transitions) are rounded to the nearest whole day.

**Decoding (apply):**
```ts
toIsoDate(addDays(today(), offset))
```

Each time the view is applied, the offset is re-expanded from today's date.

### Presets

Date presets (`date_preset: 'this_week'`) are always relative by nature and are
stored as plain strings — not as `CodedDate` objects. The backend and FilterBar
handle them independently.

---

## D2 — Search as scalar

`search` is stored as a plain string (`"refactor"`), not as an array. This is
consistent with how `FilterBar` and the task list API consume it.

---

## Normalize conventions

`normalizeSnapshot(snap)` returns one string key per active value. The key format
must match the `key` prop on the corresponding `ActiveFilter` chip:

| Condition           | Key produced                          |
|---------------------|---------------------------------------|
| `snap.search`       | `'search'`                            |
| `snap.priority`     | `'priority'`                          |
| `snap.user_id[n]`   | `` `user_id:${id}` `` (one per item, sorted) |
| `snap.labels[n]`    | `` `labels:${id}` `` (one per item, sorted) |
| `snap.date_preset`  | `'datePreset'`                        |
| `decodeDate(snap.date_from)` non-null | `'date_from'`          |
| `decodeDate(snap.date_to)` non-null   | `'date_to'`            |
| `snap.hide_without_deadline === true` | `'hideWithoutDeadline'`|

Empty / null / false values are omitted. Reordering array values does not change
the key set (`.sort()` is applied before pushing).

---

## Example snapshot

```json
{
  "priority": "high",
  "user_id": ["uuid-alice"],
  "date_from": { "mode": "relative", "offset": 0 },
  "date_to":   { "mode": "absolute", "date": "2026-12-31" }
}
```

Normalized keys: `["date_from", "date_to", "priority", "user_id:uuid-alice"]`
(sorted alphabetically by the Set comparison; order does not matter for dirty check).
