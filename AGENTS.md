# AGENTS.md

## Purpose
This repository contains an existing application with an existing forms module.
Your job is to evolve the current implementation so it matches the target business rules,
without breaking the spirit of the application, its architecture, its UX conventions,
or the author's coding style.

You are not building a greenfield system.
You are modifying an already existing product.

---

## Core implementation philosophy

### Preserve the spirit of the app
Before making changes, study the existing application carefully.
You must adapt to the application's current:
- architecture
- naming conventions
- file organization
- coding style
- error handling style
- domain language
- UI patterns
- API conventions
- testing style
- migration style

Do not introduce a foreign architecture unless absolutely necessary.

### Reuse first
Always prefer:
1. reusing existing services
2. reusing existing components
3. extending existing patterns
4. extracting shared logic from current code
5. integrating with existing workflows

Avoid creating brand new abstractions if the same job can be done
by extending something already present in the codebase.

Before adding a new:
- service
- hook
- component
- utility
- validator
- endpoint
- database table
- background job
- state abstraction

you must first verify whether an equivalent or near-equivalent mechanism already exists.

### No duplicate functionality
Do not recreate functionality that is already implemented somewhere else.
If something similar exists:
- reuse it directly, or
- refactor it carefully so it becomes reusable

Never silently build a parallel implementation.

### Respect local style
Match the project's current style, including:
- naming
- formatting
- module boundaries
- DTO conventions
- error messages
- comments style
- test layout
- frontend composition patterns
- backend service patterns

If the codebase is inconsistent, follow the dominant style in the area you are editing.

---

## Mandatory workflow

For any non-trivial task, always work in this order:

1. analyze current implementation
2. identify relevant existing modules and reusable pieces
3. describe what already exists
4. describe what must change
5. propose the smallest safe implementation path
6. implement backend/domain changes first
7. then implement API changes
8. then implement UI changes
9. then implement tests
10. then review for duplication, regressions, and style mismatch

Before writing code, explicitly list:
- files to inspect
- files likely to change
- existing reusable modules/components/services
- risks of duplication or architectural drift

---

## Mandatory reuse checklist
Before implementing anything new, check whether the repository already contains:

- form state handling
- versioning logic
- validation services
- entity assignment logic
- indexing-related services
- reporting/query/filtering infrastructure
- existing migrations patterns
- API response helpers
- permissions/policies/guards
- UI buttons, modals, banners, alerts, status chips, tabs
- tables, filters, search components
- analytics/report builder patterns
- background job / queue patterns
- storage or backup abstractions
- test fixtures/factories/builders

If any of the above exist, prefer reusing/extending them rather than replacing them.

---

## Business rules for forms module

A form has 2 independent state axes:
- activation_status: enabled | disabled
- index_status: indexed | unindexed

The combination:
- disabled + indexed

is valid and must remain supported.

### If form is disabled
- it is always editable
- it works in draft mode
- it can be saved even if it does not satisfy enablement validation
- it cannot be filled
- it cannot be assigned to entities, except when the entity is being created or moved as archived / waiting-for-unarchive
- it can be enabled only if the currently saved draft satisfies all validation rules required for enabled state
- it may preserve a backup of the version from before disabling

### If form is enabled
- it is always editable
- it is not in draft mode
- every saved change must satisfy enablement validation
- it can be filled
- it can be assigned to entities
- it can be indexed
- it can be unindexed, optionally with an internal-only backup of indexes

### If form is unindexed
- only basic submission filtering is available
- only simplified reporting is available

### If form is indexed
- all basic submission filters are available
- advanced filters are available
- filtering by concrete form fields is available
- repeated/array fields store aggregate metadata like:
  - count
  - sum
  - avg
  - min
  - max
- advanced AI-assisted search/listing is available
- higher-quality reports are available through SQL over a dedicated per-form analytical table

### Indexing rules
- only submissions compatible with the current form version may be indexed
- if not all submissions in the selected period are compatible, the user must be informed which periods will not be indexed
- unindexing removes indexes
- unindexing may optionally create an internal-only backup of indexes
- the backup is not downloadable by the user
- restore of index backup is allowed only if the current form is compatible with the indexed submissions

---

## Architecture rules

### Prefer central policies over scattered conditionals
Do not spread state logic across random controllers, components, and utilities.
Prefer introducing or extending central domain policies/services/guards/selectors.

Examples of preferred centralized logic:
- canEdit(form)
- canFill(form)
- canAssign(form, entityState)
- canEnable(form, draft)
- canDisable(form)
- canIndex(form)
- canUnindex(form)
- getAvailableFilters(form)
- getReportingMode(form)

If the repository already has an equivalent policy/guard/service pattern,
extend that pattern instead of creating a new architecture.

### Preserve compatibility where reasonable
Prefer additive migrations and progressive rollout over destructive rewrites.

### Avoid hidden behavior changes
Do not implicitly introduce rules that were not requested.
In particular:
- disabling a form must NOT automatically unindex it
- draft mode should not become an unrelated third lifecycle state if it can remain derived from disabled state
- index backup must not become a user-downloadable file if the current app treats it as internal-only

---

## Database and migration rules

Before changing schema:
- inspect existing migration style
- inspect how enums/statuses are represented
- inspect versioning and audit patterns
- inspect soft-delete/archive conventions
- inspect how large data migrations are handled

Prefer:
- additive columns/tables first
- data backfill second
- code path switch third
- cleanup last

Do not remove old columns or old code paths until:
- new behavior is covered by tests
- data migration is complete
- the old path is clearly obsolete

---

## API rules

Before changing API:
- inspect current route conventions
- inspect response envelope conventions
- inspect error format
- inspect permissions/auth patterns
- inspect validation and serialization layers

Prefer extending existing response shapes where that is natural.

If UI needs state-driven behavior, prefer returning explicit capabilities from backend,
for example:
- availableActions
- availableFilters
- reportingMode
- compatibilityWarnings

Only do this if it fits the current API style of the app.

---

## Frontend/UI rules

Before changing UI:
- inspect current design system and component library
- inspect existing form management screens
- inspect status indicators, banners, confirmations, warning modals
- inspect filter/search/reporting screens
- inspect copywriting style and tone

Always prefer existing:
- buttons
- chips/badges/status pills
- form wrappers
- page layouts
- modals/drawers
- tables
- filters
- alerts/toasts
- report/search widgets

Do not introduce a visually inconsistent UI pattern.

Match current product tone in labels, errors, and helper texts.

---

## Testing rules

Before writing tests:
- inspect current testing stack
- inspect naming patterns
- inspect fixture/factory setup
- inspect test organization by layer

Prefer extending existing:
- factories
- builders
- integration helpers
- e2e scenarios
- mock utilities

Required coverage includes:
- disabled vs enabled behavior
- disabled + indexed legality
- assignment restrictions
- validation differences between draft and enabled
- indexing compatibility behavior
- partial indexing warnings
- backup restore compatibility checks
- reporting/filter differences between indexed and unindexed

---

## Review rules

After implementation, review for:
- duplicated logic
- duplicated components
- duplicated services
- style mismatch
- architectural drift
- hidden behavior changes
- missing edge cases
- inconsistent naming
- UI inconsistency
- incomplete tests

You must explicitly call out places where:
- you reused an existing mechanism
- you extended an existing abstraction
- you deliberately avoided creating a parallel implementation

---

## Output expectations for implementation tasks

When asked to implement, structure your work like this:

1. Current-state analysis
   - what exists already
   - what can be reused
   - what should not be duplicated

2. Change plan
   - domain/backend
   - API
   - UI
   - migrations
   - tests

3. Implementation
   - smallest safe changes first
   - preserve style and conventions

4. Validation
   - tests run
   - regression risks
   - follow-up cleanup opportunities

---

## Strong warnings

Never assume the app is poorly designed just because you found complexity.
First understand why a pattern exists.

Never replace a reusable existing mechanism with a new one just because it is easier.

Never optimize for theoretical purity over consistency with the current application.

Never create “v2” or “new” parallel modules unless there is no safe alternative.

Always behave like a careful maintainer of an existing mature codebase, not like a greenfield framework author.