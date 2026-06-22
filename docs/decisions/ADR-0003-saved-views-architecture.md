# ADR-0003: Saved Views architecture — wrapper pattern (D5/D6)

## Status

Accepted — FilterTabs Stage 2, implemented in the `refactor/claude-init` branch.

## Context

The FilterTabs Stage 2 feature adds "saved views" (persisted filter snapshots) to
list screens. The primary implementation surface is `TasksView.vue`, which already
owns a `FilterBar` and a set of filter refs.

The requirement is that:
- Saved views are presented as a toolbar above the filter bar.
- Filter bar behavior is extended with per-chip trit-state and a restore affordance.
- The bucket (Active/Archive/Trash `Tabs.vue`) is NOT part of a saved view (D3).
- The solution must work without rewriting `FilterBar`.

## Decision

### D5: Option B — wrapper pattern (FilterTabBar + composable + store)

A dedicated `FilterTabBar` component is created as a standalone `role="toolbar"`
above `FilterBar`. `FilterBar` is extended additively.

**Considered alternatives:**

| Option | Description | Rejected reason |
|--------|-------------|-----------------|
| A — full FilterBar rewrite | Absorb saved-view state fully into FilterBar. | Rewriting a stable, well-tested component for a new concern violates "prefer the smallest safe change set". The chip-state extension is additive and does not require ownership of the view list. |
| B — wrapper (chosen) | `FilterTabBar` owns the view toolbar; `FilterBar` receives `tabState` per chip as an optional prop. | Smallest safe change: FilterBar gains three optional chip-state values and one new event; all existing behavior is unchanged when `tabState` is absent. |
| C — composable-only (no new component) | Inject trit-state from the page; skip a dedicated toolbar component. | The toolbar has its own states (loading, error, empty, dirty badge, kebab menus, discard guard) that do not belong in the page template. A component boundary is appropriate. |

**Why not reuse `Tabs.vue`?**

`Tabs.vue` is a `role="tablist"` / `role="tab"` panel switcher (Active/Archive/Trash).
Saved views are not panel switches — they apply a filter snapshot to the CURRENT
panel. Sharing the component would require overloading its ARIA semantics. Per D6
(see below), saved views use `role="toolbar"` and `aria-current="true"` on the
active pill (not `aria-selected`).

### D6: role="toolbar", not role="tablist"

`FilterTabBar` renders with `role="toolbar"` and `aria-label` from
`tasks.savedViews.barLabel`. Each pill is a `<button>` with
`aria-current="true"` on the active one.

This allows screen readers to distinguish:
- The `role="tablist"` (Active/Archive/Trash) which switches panels.
- The `role="toolbar"` (saved views) which applies filter snapshots.

## Consequences

- `FilterBar` gains three optional `ActiveFilter.tabState` values and a
  `restore-filter` event. Absence of `tabState` renders the bar exactly as before —
  zero regression for screens that do not use saved views.
- `FilterTabBar` is a new pattern component, not a primitive. It owns the
  discard-on-switch guard (R2) internally so the page does not need to handle it.
- `useFilterTabs` composable is the domain interface: the page supplies four pure
  adapters; the composable + store own server state.
- `SaveViewModal` is a presentational pattern component that emits `submit`; the
  page owns the API call and error handling.
