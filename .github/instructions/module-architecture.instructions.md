---
description: "Use when creating new modules, adding module features, or modifying module structure under app/modules/. Covers service providers, directory layout, routing, and registration."
---

# Module Architecture

## Directory Layout

Each module lives in `app/modules/{ModuleName}/` with this structure:

```
app/modules/{ModuleName}/
├── {ModuleName}ModuleServiceProvider.php
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   └── Resources/
├── Models/
├── Services/
├── Policies/
├── DTOs/
├── Enums/
├── Jobs/
├── Traits/
├── Rules/
├── Observers/
└── routes/
    └── api.php
```

Only create directories/files as needed — don't scaffold empty folders.

## Service Provider

Each module has `{ModuleName}ModuleServiceProvider extends ServiceProvider`:

```php
public function boot(): void
{
    // Routes
    Route::middleware('api')
        ->prefix('api')
        ->group(__DIR__ . '/routes/api.php');

    // Morph map for polymorphic relations
    Relation::enforceMorphMap([
        'model_name' => Model::class,
    ]);

    // Policies
    Gate::policy(Model::class, ModelPolicy::class);
}
```

Register the provider in `config/modules.php` and `bootstrap/providers.php`.

## Registration in config/modules.php

```php
'modules' => [
    'module_name' => [
        'name' => 'ModuleName',
        'icon' => 'icon-name',
        'permissions' => [],
    ],
],
```

## API Routes

- RESTful routes in `routes/api.php` under `auth:sanctum` middleware.
- Standard CRUD: `Route::resource('entities', EntitiesController::class)->only([...])`.
- Custom actions as named routes: `Route::post('entities/{id}/action', [Controller::class, 'action'])`.
- Soft-delete operations: `POST restore`, `DELETE force`.

## API Response Conventions

- Single resources: `ModelResource::make($model)`.
- Collections: `ModelListResource::collection($paginator)` with `->additional(['meta' => ...])`.
- Two resource classes per model: `ModelResource` (full) and `ModelListResource` (summary for lists).
- Cursor pagination via `->cursorPaginate()`.
- Capability flags in resources: `'can_be_edited' => $this->canBeEdited()`.

## Layer Responsibilities

| Layer | Role |
|-------|------|
| Controller | HTTP handling, authorization, delegation to Service, resource wrapping |
| Service | Business logic, domain validation, model mutations |
| DTO | Type-safe data transfer from Request to Service |
| Policy | Authorization rules (who can do what) |
| Model | State, relationships, capability checks, scopes |
| Resource | API response shape, computed flags |
| Job | Async/heavy operations |
| FormRequest | Input validation rules |

## Naming Conventions

- Controllers: `{Plural}Controller` — `FormsController`, `FormSubmissionsController`.
- Services: `{Singular}Service` — `FormService`, `FormSubmissionService`.
- DTOs: `{Singular}DTO` — `FormDTO`.
- Policies: `{Singular}Policy` — `FormPolicy`.
- Resources: `{Singular}Resource` / `{Singular}ListResource`.
- Requests: `{Action}{Singular}Request` — `StoreFormRequest`, `EnableFormRequest`.
- Jobs: `{Action}{Noun}` — `IndexFormJob`, `CreateFormReport`.
- Namespace: `App\Modules\{ModuleName}\...`.
