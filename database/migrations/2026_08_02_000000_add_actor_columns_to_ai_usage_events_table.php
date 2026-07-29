<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconcile the CENTRAL `ai_usage_events` table to the R2 sub-stage-4 actor-attribution shape for
 * databases that already ran the original (pre-sub-stage-4) create migration. The
 * `..._create_ai_usage_events_table` migration was extended IN PLACE during sub-stage 4 (adding
 * `actor_type`/`actor_id`), so a FRESH `migrate` / `migrate:fresh` builds the new shape directly — but a
 * database that had already recorded the old create migration keeps the old columns (Laravel never
 * re-runs a recorded migration). This forward migration closes that drift, mirroring the same fix
 * applied for the `templates` content-recipe rework (`2026_08_01_000000_reshape_templates_...`).
 *
 * Guarded by hasColumn, so it is a no-op on a fresh (already-new-shape) database and applies only the
 * missing delta on a stale one. No existing `ai_usage_events` row is touched — `actor_type`/`actor_id`
 * are nullable, so pre-sub-stage-4 rows simply carry no actor (an unattributable legacy spend), exactly
 * as a fresh install's meter-off rows would.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('ai_usage_events', 'actor_type')) {
            Schema::table('ai_usage_events', function (Blueprint $table) {
                $table->string('actor_type')->nullable()->after('session_id');
            });
        }

        if (!Schema::hasColumn('ai_usage_events', 'actor_id')) {
            Schema::table('ai_usage_events', function (Blueprint $table) {
                $table->uuid('actor_id')->nullable()->after('actor_type');
            });
        }

        if (!$this->hasIndex('ai_usage_events', ['actor_type', 'actor_id'])) {
            Schema::table('ai_usage_events', function (Blueprint $table) {
                $table->index(['actor_type', 'actor_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('ai_usage_events', function (Blueprint $table) {
            if ($this->hasIndex('ai_usage_events', ['actor_type', 'actor_id'])) {
                $table->dropIndex(['actor_type', 'actor_id']);
            }
        });

        foreach (['actor_id', 'actor_type'] as $column) {
            if (Schema::hasColumn('ai_usage_events', $column)) {
                Schema::table('ai_usage_events', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    /** Whether a composite index over exactly these columns already exists (order-insensitive). */
    private function hasIndex(string $table, array $columns): bool
    {
        $indexes = Schema::getIndexes($table);
        sort($columns);

        foreach ($indexes as $index) {
            $indexColumns = $index['columns'];
            sort($indexColumns);

            if ($indexColumns === $columns) {
                return true;
            }
        }

        return false;
    }
};
