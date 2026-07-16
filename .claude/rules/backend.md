---
paths:
  - "app/**/*.php"
  - "routes/**/*.php"
  - "database/**/*.php"
  - "config/**/*.php"
---

# Backend Rules

Use the modular monolith architecture. Modules live in `app/modules/<Name>` with a
`<Name>ModuleServiceProvider` registered in `bootstrap/providers.php` (not in
`config/modules.php`, which only lists UI modules).

Actual layering in this codebase:
Route -> Controller -> FormRequest (+ Policy) -> DTO -> Service -> Model -> Resource.

Rules:
- FormRequest validates and authorizes (delegates to the Policy). Controllers stay thin.
- Services hold business logic and own their Eloquent queries. There is **no Action
  layer** in this codebase — do not introduce one without approval.
- Managers handle stateful/complex workflows; today this exists **only in Changelog**.
  Use it for that kind of logic, not as a default layer.
- Repositories are the exception, not the rule — **only `Users` has one**. Query
  Eloquent directly from Services elsewhere; add a Repository only on real need.
- DTOs move validated data into application layers. Policies centralize authorization.
  API Resources shape responses, including explicit capability flags (e.g. Form's
  `can_be_*`, `available_filters`).
- Models extend `App\Models\AbstractModel` (except `User`, which extends
  `Authenticatable`). Shared query scopes live on `AbstractModel` (`scopeFilterByDate`)
  and in `App\Models\Concerns`.

## Querying & search
- For text search use the `Searchable` trait scope: `->search($columns, $term)`.
  **Pass the term as an argument — the scope must never read from `request()`.** It is
  case-insensitive and no-ops on a blank term. Do not hand-roll `whereLike` /
  `where(..., 'like', ...)` in services or repositories.
- Prefer `Model::query()` and Eloquent over `DB::`; eager-load to avoid N+1. Known
  exception: the per-form analytical layer (`FormAnalyticalTableService`) uses raw
  SQL / `Schema` by design.

## Code style
- Formatting is enforced by `pint.json` (preset `laravel` + two overrides). Run
  `vendor/bin/pint --dirty` before finalizing. Two deliberate deviations from the stock
  preset: spaced concatenation (`'%' . $x . '%'`) and no space after `!` (`!$value`).

- Use Laravel Boost MCP when uncertain about Laravel, package versions, schema, logs, or docs.
- Do not edit frontend files during backend refactors.
