---
description: "Use when working on the Forms module — models, services, controllers, jobs, or frontend components related to forms, submissions, reports, indexing, or analytical tables."
applyTo: "app/modules/Forms/**"
---

# Forms Module Domain Knowledge

## Two Independent State Axes

The Form model has two orthogonal states, each represented as a nullable timestamp:

| Axis | Column | Set | Unset |
|------|--------|-----|-------|
| Activation | `enabled_at` | enabled | disabled (draft) |
| Indexing | `indexed_at` | indexed | unindexed |

All four combinations are valid, including **disabled + indexed**.

## Capability Methods (on `Form` model)

Centralized state logic — never scatter these checks across controllers:

- `isEnabled()` / `isDisabled()` — checks `enabled_at`.
- `isIndexed()` / `isUnindexed()` — checks `indexed_at`.
- `isDraft()` — alias for `isDisabled()`.
- `canBeEdited()` — always `true` (both enabled and disabled forms are editable).
- `canBeFilled()` — only when enabled.
- `canBeAssigned()` — only when enabled (exception: entity being created or archived).
- `canBeEnabled()` — when disabled AND `hasMinimumRequiredFields()`.
- `canBeDisabled()` — when enabled AND not anonymous.
- `canBeIndexed()` — when enabled AND unindexed AND not currently indexing.
- `canBeUnindexed()` — when indexed.
- `isIndexing()` — checks `indexing_started_at` (async job in progress).

## Content Versioning

- `content_version` — incremented on every content save when form is enabled.
- `content_updated_at` — timestamp of last content change.
- `FormContentVersion` — snapshot model linking submissions to their form version.
- `isSubmissionCompatible(submission)` — checks if submission matches current version.

## Backups

- `content_backup` — preserved when disabling a form (restores draft state).
- `index_backup` — internal-only backup when unindexing. **Not downloadable by users.**
- Restore of index backup only allowed if current form is compatible with indexed submissions.

## Analytical Tables (PostgreSQL)

- Per-form table: `form_analytical_{uuid_with_underscores}`.
- Created by `FormAnalyticalTableService::createTable()`.
- Populated via `Bus::batch()` with cursor-paginated `BootstrapFormSubmissionsIndexJob` dispatching `IndexFormSubmissionJob` per submission.
- Single submission indexed via `indexSubmission()`.
- `InteractsWithFormSchema` trait: extracts field paths, builds SQL columns, maps JSON Schema types.
- Repeater fields stored as JSONB.

## Form Elements

- `FormElementType` enum: SECTION, GRID, REPEATER, HEADING, TEXT_BLOCK, DIVIDER, SHORT_TEXT, LONG_TEXT, SELECT, IMAGE, CHECKBOX, NUMBER, DATE, TIME, URL, CHECKLIST.
- Categories via `FormElementCategory`: LAYOUT, CONTENT, INPUT.
- Only INPUT elements count for `hasMinimumRequiredFields()`.

## Jobs

- `IndexFormJob` — async: creates analytical table + bootstraps data.
- `IndexFormSubmissionJob` — indexes single submission.
- `CreateFormReport` — generates report exports.

## Key Rules

- Disabling a form does NOT automatically unindex it.
- Draft mode is derived from disabled state — not a separate lifecycle state.
- Anonymous forms are auto-enabled on creation and cannot be disabled.
- API resources expose all capability flags (`can_be_enabled`, `can_be_indexed`, etc.) for UI-driven behavior.
