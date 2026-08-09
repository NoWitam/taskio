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
- **The term is a literal, not a pattern.** `%` and `_` are escaped before the term is
  wrapped in `%…%`, so a search matches only itself — previously an unescaped `%` matched
  every row and `_` was a live single-char wildcard. Escaping order matters: the backslash
  is escaped first, or `\_` becomes `\\_` and the search silently returns zero rows instead
  of over-matching. The scope's unused fourth parameter, `bool $allowWildcards = false`, is
  a deliberate seam for a future advanced-search feature that wants live wildcards — not
  dead code to be swept up.
- **A JSON column is not prose.** A column cast to `array` is stored `json_encode`d with no
  flags, so `Łódź` is on disk as `Łódź` and a `::text` LIKE never sees the
  letter the user typed. The one caller that searches such a column
  (`FormSubmissionService::indexByForm`) encodes the term the same way *before* handing it
  to the scope — encode first, escape second, or the backslashes eat each other. Keep that
  encoding at the call site: moving it into the trait would corrupt every plain-text search
  in the app. Note the cost this variant accepts: `Ł` and `ł` are different bytes,
  so search stops folding case for non-ASCII.
- Prefer `Model::query()` and Eloquent over `DB::`; eager-load to avoid N+1. Known
  exception: the per-form analytical layer (`FormAnalyticalTableService`) uses raw
  SQL / `Schema` by design.

## Code style
- Formatting is enforced by `pint.json` (preset `laravel` + two overrides). Run
  `vendor/bin/pint --dirty` before finalizing. Two deliberate deviations from the stock
  preset: spaced concatenation (`'%' . $x . '%'`) and no space after `!` (`!$value`).

- Use Laravel Boost MCP when uncertain about Laravel, package versions, schema, logs, or docs.
- Do not edit frontend files during backend refactors.
