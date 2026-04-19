---
description: "Use when creating or editing database migrations. Covers schema conventions, column types, naming, and the additive migration approach."
applyTo: "database/migrations/**"
---

# Migration Conventions

## Schema Rules

- UUID primary keys: `$table->uuid('id')->primary()`.
- Foreign keys via `$table->foreignIdFor(Model::class, 'column_name')` — always reference the model class.
- Cascading deletes: `->onDelete('cascade')` on FKs pointing to parent entities.
- Always add `$table->timestamps()`.
- Use `$table->softDeletes()` on entities that support trashing.

## Column Types

- State axes as nullable timestamps: `$table->timestamp('enabled_at')->nullable()->index()` — not booleans.
- JSON columns: `$table->json('content')` — cast as `'array'` in the Model.
- Enum-backed columns: use string column with Enum default value, e.g., `->default(StatusEnum::Draft->value)`.
- Counters/versions: `$table->unsignedInteger('content_version')->default(0)`.

## Naming

- File naming: `YYYY_MM_DD_HHMMSS_description_in_snake_case.php`.
- Column names: `snake_case`.
- Boolean columns: `is_` prefix (e.g., `is_anonymous`).
- Timestamp state columns: `_at` suffix (e.g., `enabled_at`, `indexed_at`, `indexing_started_at`).
- Backup/snapshot columns: descriptive name + `_backup` suffix (e.g., `content_backup`, `index_backup`).

## Indexes

- Add `->index()` on columns used in WHERE, ORDER BY, or JOIN.
- Composite indexes for common query patterns.

## Additive Approach

- Prefer adding new columns/tables over modifying existing ones.
- Sequence: add column → backfill data → switch code path → cleanup old column.
- Don't drop columns in the same release as code removal.
- Only write `up()` method in migrations — no `down()`.

## Model Sync

- When adding columns, immediately update the Model's `$fillable` array.
- Add proper `$casts` entry: datetime, array, boolean, enum, integer.
- Never chain multiple `make:migration` commands with `&&` — timestamps may collide.
