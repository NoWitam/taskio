# ADR-0004: Filter snapshot date format (D1)

## Status

Accepted — FilterTabs Stage 2.

## Context

Saved views persist a `filters` JSON snapshot that can include deadline endpoints
(`date_from`, `date_to`). A deadline like "end of this week" is meaningful today
but stale in three months if stored as a fixed `YYYY-MM-DD` string.

The `SaveViewModal` shows a D1 section when the live filter has concrete date
values (from/to). The user chooses per-endpoint whether to lock the date as an
absolute calendar day or store it as a relative offset from today.

## Decision

### D1: two-variant date encoding in the snapshot

Each date endpoint is stored as one of:

```ts
// 1. Fixed calendar day — the date is permanently "that day"
{ mode: 'absolute', date: 'YYYY-MM-DD' }

// 2. Relative offset — recomputed from today on every apply
{ mode: 'relative', offset: <integer, signed> }

// 3. Null — endpoint not set
null
```

`offset` is an integer number of days. A positive offset is in the future; a
negative offset is in the past.

### "Whole-day local" offset convention

`offset` is computed as:

```ts
Math.round((targetDate.getTime() - today().getTime()) / 86_400_000)
```

where `today()` returns midnight in the browser's local timezone. Fractional hours
(due to DST transitions or timezone offsets) are rounded to the nearest whole day.

On `apply`, the offset is expanded back:

```ts
toIsoDate(addDays(today(), offset))
```

This means a view saved with `{ mode: 'relative', offset: 0 }` will always resolve
to "today" regardless of when it is applied.

### Presets are not affected

Date presets (`date_preset: 'this_week'`, `'today'`, etc.) are always relative by
nature and are stored as plain strings. They do not use the `CodedDate` wrapper.

### The D1 section in SaveViewModal

The modal shows the D1 section only when `snapshotFrom` or `snapshotTo` (the live
`deadline.from` / `deadline.to`) is non-null. The user's per-endpoint choice is
returned in `SaveViewSubmit.dateModes`. The page sets `pendingDateModes` from
`payload.dateModes` before calling `serializeFiltersSnapshot()`.

## Alternatives considered

| Option | Rejected reason |
|--------|-----------------|
| Always absolute | Saved views would become stale for any time-relative filter. A "due this week" view saved on Monday would show empty results the following Monday. |
| Always relative | A view explicitly set for a fixed delivery date (e.g. a project deadline on 2026-12-31) would drift with time. The user needs to lock absolute dates. |
| Server-side expansion | Backend expands the offset on fetch. Rejected: the backend stores opaque `filters` JSON and does not know the filter schema. Keeping encoding/decoding in the page preserves the clean separation between the FilterTabs module (agnostic) and the page's filter domain. |

## Consequences

- The page's `serializeFiltersSnapshot` and `applyFiltersSnapshot` own the
  encode/decode logic for dates.
- `normalize()` must decode dates before checking presence:
  `if (decodeDate(snap.date_from)) keys.push('date_from')`.
- `dirty` comparisons work on key presence only — the actual date value is not
  compared, only whether a date endpoint is set. This means changing "this Friday"
  to "next Friday" does not affect the dirty state.
- `disabledChipLabel` must also decode the stored date for display.
