---
description: "Use when writing, editing, reviewing, or refactoring PHP or Laravel code. Covers coding style, service patterns, DTOs, controllers, models, enums, and helpers."
applyTo: "**/*.php"
---

# PHP & Laravel Conventions

## PHP Style

- PHP 8.2+ syntax: typed properties, union types, named arguments, `match` expressions.
- Always declare return types (including `void`) and parameter types.
- Use constructor property promotion with `readonly` for value objects and DTOs.
- Use `match` over `switch` whenever possible.
- Use string interpolation over concatenation: `"Hello {$name}"`.
- Use early returns — happy path last. Handle error conditions first.
- Use short nullable syntax: `?string` instead of `string|null`.
- Don't create empty constructors without parameters.
- Don't create temporary variables used only once.
- Don't add docblock comments when full type hints already exist — only add `/** @var */` when needed for static analysis, or comments explaining *why* (not *what*).

## Laravel Helpers

- Use helpers over Facades: `auth()->id()` not `Auth::id()`, `str()->slug()` not `Str::slug()`, `redirect()->route()` not `Redirect::route()`.
- Use `config()` helper. Never use `env()` outside config files.
- Don't use `whereKey()` or `whereKeyNot()` — use explicit `->where('id', ...)`.
- Don't add `::query()` on `create()` calls. Use `User::create()`, not `User::query()->create()`.

## Enums

- Place enums in `app/Enums/` (global) or `app/modules/{Module}/Enums/` (module-scoped).
- Always use Enum cases (or `->value`) instead of raw strings — in migrations, seeds, tests, configs, and views.
- Cast enum columns in Model `$casts` array.
- Use PascalCase for enum case names.

## Models

- Extend `App\Models\AbstractModel` (provides shared scopes like `filterByDate`).
- Use `HasUuids` trait — all PKs are UUIDs.
- Use `SoftDeletes` where applicable.
- Always keep `$fillable` up to date when adding migration columns.
- Cast columns appropriately: `'array'` for JSON, datetime class for timestamps, enum class for enums, `'boolean'` for bools.
- State axes use nullable timestamps (e.g., `enabled_at`, `indexed_at`), not boolean columns.
- Capability checks belong on the model as methods (`canBeEnabled()`, `canBeFilled()`, etc.) — not scattered across controllers.
- Scopes follow naming: `scopeOnlyEnabled`, `scopeOnlyDisabled`, etc.

## Controllers

- Slim controllers — delegate business logic to Service classes.
- If a Service is used in multiple methods, inject via constructor. If used in one method only, inject via method type-hint.
- Use `$this->authorize('ability', $model)` for authorization.
- Return JSON Resources (`FormResource::make(...)`, `FormListResource::collection(...)`).
- Single-action controllers use `__invoke()`. Multi-action RESTful controllers use standard methods (`index`, `show`, `store`, `update`, `destroy`).

## Services

- One service per aggregate root (e.g., `FormService`, `FormSubmissionService`).
- Constructor-inject dependencies (other services, etc.).
- Throw `ValidationException::withMessages(...)` for domain validation errors.
- Keep methods focused — one responsibility per method.

## DTOs

- Use `readonly` constructor-promoted properties.
- Static `fromRequest(Request $request): self` factory method.
- Use `$request->string()`, `$request->enum()`, `$request->array()`, `$request->boolean()` for typed extraction.

## Form Requests

- Validation rules in `rules()` method, using array notation (not pipe notation).
- Custom validation rules in `app/modules/{Module}/Rules/`.
- Authorization in `authorize()` method or via Policy in controller.

## Observers

- Register via PHP attribute on the Model: `#[ObservedBy([MyObserver::class])]`.
- Don't register in `AppServiceProvider`.

## Policies

- Register via `Gate::policy()` in module ServiceProvider.
- Methods return `bool` or `Response`.

## Eloquent Queries

- Use cursor pagination for list endpoints: `->cursorPaginate()`.
- Use `->loadMissing()` for eager loading after retrieval.
- Use `foreignIdFor(Model::class, 'column')` in migrations for FK references.
