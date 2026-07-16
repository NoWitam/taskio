# Component Reference: Saved Views (FilterTabs Stage 2)

---

## FilterTabBar

File: `resources/js/next/ui/patterns/FilterTabBar.vue`

A `role="toolbar"` strip of saved-view pills placed above `FilterBar`. Distinct
from `Tabs.vue` which is a `role="tablist"` for Active/Archive/Trash. Active
pills use `aria-current="true"`, not `aria-selected`.

### Props

| Prop              | Type                          | Default | Description                                            |
|-------------------|-------------------------------|---------|--------------------------------------------------------|
| `tabs`            | `FilterTab[]`                 | —       | Ordered list of saved views for this context.          |
| `activeTabId`     | `string \| number \| null`    | —       | ID of the currently active view, or `null`.            |
| `dirty`           | `boolean`                     | `false` | Shows the warning badge on the active pill.            |
| `loading`         | `boolean`                     | `false` | Shows pill-shaped skeletons.                           |
| `error`           | `boolean`                     | `false` | Shows inline error + retry button.                     |
| `hasActiveFilters`| `boolean`                     | `false` | Enables the "Save as" button.                          |
| `busy`            | `boolean`                     | `false` | Disables Save/Save-as while a mutation is in flight.   |

### Events

| Event       | Payload       | Description                                            |
|-------------|---------------|--------------------------------------------------------|
| `activate`  | `FilterTab`   | User clicks a pill to activate a view.                 |
| `save`      | —             | User clicks Save (overwrite active dirty view).        |
| `save-as`   | —             | User clicks "Save as…".                                |
| `edit`      | `FilterTab`   | User selects "Edit" from the kebab menu.               |
| `delete`    | `FilterTab`   | User selects "Delete" from the kebab menu.             |
| `move-up`   | `FilterTab`   | User selects "Move up" from the kebab menu.            |
| `move-down` | `FilterTab`   | User selects "Move down" from the kebab menu.          |
| `retry`     | —             | User clicks "Retry" on the error state.                |

### States

| State           | Trigger                         | Rendering                                      |
|-----------------|---------------------------------|------------------------------------------------|
| Empty           | `!loading && !error && !tabs.length` | Short prompt; Save-as disabled.           |
| Loading         | `loading`                       | Four pill-shaped skeletons of varied widths.   |
| Error           | `error`                         | Danger text + retry button.                    |
| Normal          | `tabs.length > 0`               | Pill list with Save/Save-as trailing actions.  |
| Active + dirty  | `activeTabId && dirty`          | Warning badge on the active pill.              |

### Discard-on-switch guard (R2)

Clicking a different pill while `dirty` is `true` opens an internal `ConfirmDialog`
before emitting `activate`. The page does not need to handle this guard.

### Pill anatomy

Each pill contains (left to right):
1. Optional icon (from the view's `icon` field).
2. Name (truncated at 14ch).
3. Warning badge — conditional, only on the active dirty pill (before the kebab).
4. Kebab menu button — permanent (always present).

Trailing affordance order follows the global rule: conditional controls before
permanent controls.

---

## SaveViewModal

File: `resources/js/next/ui/patterns/SaveViewModal.vue`

A `Modal` for creating or editing a saved view. Presentational: emits `submit`
with `SaveViewSubmit`; the page owns the API call.

### Props

| Prop            | Type                  | Default    | Description                                             |
|-----------------|-----------------------|------------|---------------------------------------------------------|
| `open` (v-model)| `boolean`             | `false`    | Controls modal visibility.                              |
| `mode`          | `'create' \| 'edit'`  | `'create'` | Determines title and submit button label.               |
| `initialName`   | `string`              | `''`       | Pre-fills the name field (edit mode).                   |
| `initialIcon`   | `string \| null`      | `null`     | Pre-fills the icon (next name or `IconEnum` value).     |
| `snapshotFrom`  | `string \| null`      | `null`     | ISO date from the live filter state (shows D1 section). |
| `snapshotTo`    | `string \| null`      | `null`     | ISO date from the live filter state (shows D1 section). |
| `submitting`    | `boolean`             | `false`    | Disables the submit button; shows spinner.              |
| `nameError`     | `string \| null`      | `null`     | i18n key for an inline name-field error (from 422).     |

### Emitted events

| Event    | Payload type      | Description                              |
|----------|-------------------|------------------------------------------|
| `submit` | `SaveViewSubmit`  | Emitted when the user confirms the form. |

```ts
interface SaveViewSubmit {
  name: string;         // trimmed, non-empty, <= 60 chars
  icon: IconName | null;// chosen next glyph, or null
  dateModes: {          // D1: per-endpoint date mode (only meaningful when
    from: DateMode;     //     snapshotFrom/snapshotTo are set)
    to: DateMode;
  };
}
type DateMode = 'absolute' | 'relative';
```

### Sections

1. **Name** — text input, max 60 chars, required. Inline error displays when
   `nameError` is set (the page passes the raw i18n key; the modal calls `t()`).
2. **Icon picker** — searchable grid of `PICKABLE_ICONS`. Optional. "Clear" button
   appears when an icon is selected. See R1 below.
3. **Date mode (D1)** — shown only when `snapshotFrom` or `snapshotTo` is non-null.
   One `SegmentedControl` per endpoint (absolute / relative). Previews the
   formatted date or the offset text below each control.

### Icon set (R1)

The picker renders only the icons in `PICKABLE_ICONS` from
`resources/js/next/ui/forms/filterTabIcon.ts`. These are next glyphs that have a
verified `IconEnum` counterpart. The `toIconEnumValue(name)` function maps the
chosen next glyph to the `IconEnum` value before the API call.

Round-trip: `resolveLabelIcon(iconEnumValue)` (from `labelIcon.ts`) maps the stored
`IconEnum` value back to the same next glyph on read. The same function is used by
Labels.

---

## FilterBar (tabState / restore-filter additions)

File: `resources/js/next/ui/patterns/FilterBar.vue`

The existing FilterBar is extended additively to support saved-view chip states.
All changes are opt-in: when `activeFilters` chips carry no `tabState`, the bar
renders exactly as before.

### FilterTabState (per chip)

```ts
type FilterTabState = 'tab-active' | 'extra' | 'tab-disabled';
```

| Value          | Meaning                                                   | Visual                                          |
|----------------|-----------------------------------------------------------|-------------------------------------------------|
| `tab-active`   | In both snapshot and current state. Baseline.             | Neutral subtle chip, removable. Same as before. |
| `extra`        | Only in current state (added on top of the view).         | Primary-subtle chip, `plus` icon, removable, `ring-1`. |
| `tab-disabled` | Only in snapshot (removed from current state).            | Neutral struck-through + dashed border, NOT removable; trailing rotate-ccw. |

### restore-filter event

Emitted by `FilterBar` when the user clicks the trailing restore button on a
`tab-disabled` chip:

```ts
emit('restore-filter', chip.key);
```

The page forwards this to `savedViews.restoreFilter(key)`.

### ActiveFilter / ActiveFilterValue

`tabState` is an optional field on both `ActiveFilter` (single-value) and
`ActiveFilterValue` (multi-value). Its absence is equivalent to `tab-active` for
rendering purposes.

---

## Badge.trailingAction (R5)

File: `resources/js/next/ui/primitives/Badge.vue`

An optional trailing action button was added to `Badge`:

```ts
trailingAction?: { icon: IconName; label: string }
```

When set, a focusable button is rendered after the label content (alongside or
instead of the `removable` ✕). It emits `action` on click.

Used by the `tab-disabled` chip to render the rotate-ccw restore affordance:

```html
<Badge
  variant="neutral"
  tone="subtle"
  class="border border-dashed border-next-border line-through opacity-70"
  :trailing-action="{
    icon: 'rotate-ccw',
    label: t('tasks.filters.chip.restore', 'Restore filter: {label}', { label: chip.label }),
  }"
  @action="restoreFilter(chip.key)"
>
  {{ chip.label }}
</Badge>
```

`trailingAction` and `removable` can coexist (both buttons render), but by
convention they are mutually exclusive per use case.

---

## Chip trit-state — a11y details

| State          | aria-label                                                       | Keyboard           |
|----------------|------------------------------------------------------------------|--------------------|
| `tab-active`   | standard remove-label                                            | Enter/Space removes|
| `extra`        | `'{label} (added on top of the view)'`                           | Enter/Space removes|
| `tab-disabled` | `'{label} (removed from the view — activate to restore)'`        | Tab to button; Enter/Space restores |

`tab-disabled` chips are intentionally non-removable (no ✕). They are visually
distinguished by:
- `line-through` text.
- `border-dashed` border.
- `opacity-70`.
- The trailing rotate-ccw button (distinct from ✕).

State is never conveyed by color alone (shape + icon + typography all differ).
