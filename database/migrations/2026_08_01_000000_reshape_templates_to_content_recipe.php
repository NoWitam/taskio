<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconcile the CENTRAL `templates` table to the R2 content-recipe shape for databases that already ran
 * the ORIGINAL (pre-rework) create migration. The `..._create_templates_table` migration was reshaped
 * IN PLACE during the sub-stage-1 rework (dropping `type`/`prompt_body`/`parameters`, adding
 * `content_type`/`content`), so a FRESH `migrate` / `migrate:fresh` builds the new shape directly — but a
 * database that had already recorded the old create migration keeps the old columns (Laravel never re-runs
 * a recorded migration). This forward migration closes that drift.
 *
 * Every step is GUARDED by hasColumn, so it is a no-op on a fresh (already-new-shape) database and applies
 * only the missing delta on a stale one. The new columns are added NULLABLE for robustness (the create
 * migration makes them NOT NULL, but the app always supplies both on write, so DB-level nullability is
 * immaterial and this avoids a NOT-NULL failure should any legacy row exist).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            if (!Schema::hasColumn('templates', 'content_type')) {
                $table->string('content_type')->nullable();
            }
            if (!Schema::hasColumn('templates', 'content')) {
                $table->json('content')->nullable();
            }
        });

        // Drop the pre-rework columns the new model no longer uses (their NOT-NULL would also reject the
        // reworked insert, which omits them). Each drop is guarded + in its own closure for Postgres.
        foreach (['type', 'prompt_body', 'parameters'] as $column) {
            if (Schema::hasColumn('templates', $column)) {
                Schema::table('templates', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            if (!Schema::hasColumn('templates', 'type')) {
                $table->string('type')->nullable();
            }
            if (!Schema::hasColumn('templates', 'prompt_body')) {
                $table->text('prompt_body')->nullable();
            }
            if (!Schema::hasColumn('templates', 'parameters')) {
                $table->json('parameters')->nullable();
            }
        });

        foreach (['content_type', 'content'] as $column) {
            if (Schema::hasColumn('templates', $column)) {
                Schema::table('templates', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
