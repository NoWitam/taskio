# ADR-0005: Hard uniqueness for saved view names (D4)

## Status

Accepted — FilterTabs Stage 2.

## Context

Each saved view has a human-readable name (e.g. "High priority this week"). When
a user creates or renames a view, the backend must decide how to handle duplicate
names.

## Decision

### D4: Hard uniqueness per user + workspace + context

A view name is unique across the triple (user_id, workspace_id, context). The
backend enforces this with a database-level unique constraint and a validation rule
in both `StoreFilterTabRequest` and `UpdateFilterTabRequest`.

On conflict, the backend returns HTTP 422 with:

```json
{
  "errors": {
    "name": ["filter_tabs.errors.name_taken"]
  }
}
```

The frontend surfaces this as an inline error on the name field inside
`SaveViewModal` (the modal stays open; the error is cleared on next submit).

**Implementation:**

```php
Rule::unique('filter_tabs', 'name')
    ->where('user_id', $this->user()->id)
    ->where(WorkspaceScope::COLUMN, $workspaceId)
    ->where('context', $this->string('context')->toString()),
```

On update, the current tab's own id is excluded:
```php
->ignore($tab->id)
```

## Alternatives considered

| Option | Rejected reason |
|--------|-----------------|
| Soft uniqueness (warn, allow duplicate) | Two views named "This week" in the same list screen would be confusing in the toolbar. The user has no visual way to distinguish them. |
| Auto-suffix (append " (2)") | Generates surprising names the user did not choose. The user should pick a unique name explicitly. |
| Uniqueness across all contexts | Too strict. A user may legitimately want a view called "Mine" in both Tasks and another future list screen. The `context` column scopes uniqueness correctly. |

## Consequences

- `SaveViewModal` must forward the raw 422 key (`filter_tabs.errors.name_taken`)
  to its `nameError` prop. The modal translates it via `t()` before displaying.
- The page's error-handling code extracts `fieldErrors.name` and sets
  `saveModalNameError.value` to keep the modal open.
- Non-name 422 errors (context limit, payload size) are surfaced as toasts because
  they cannot be attributed to a single form field.
