<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconcile the CENTRAL `generation_sessions` table to the R2 sub-stage-3 bot-delegation shape for databases
 * that already ran the ORIGINAL (pre-sub-stage-3) create migration. The
 * `..._create_generation_sessions_table` migration was extended IN PLACE during sub-stage 3 (adding
 * `bot_author_id` + `bot_delegation`), so a FRESH `migrate` / `migrate:fresh` builds the new shape directly —
 * but a database that had already recorded the create migration keeps the old columns (Laravel never re-runs
 * a recorded migration). This forward migration closes that drift, mirroring the same fix already applied for
 * `templates` (`2026_08_01_000000_reshape_templates_...`) and `ai_usage_events`
 * (`2026_08_02_000000_add_actor_columns_...`).
 *
 * Guarded by hasColumn, so it is a no-op on a fresh (already-new-shape) database and applies only the missing
 * delta on a stale one. Both columns are nullable, so every existing session row simply reads as un-delegated
 * (human-authored) — exactly what it is.
 *
 * NOTE on ordering: `2026_08_03_000000_add_creative_direction_...` declares `->after('bot_delegation')`. That
 * modifier is MySQL-only and is ignored by Postgres, which is why it applied successfully on a database where
 * `bot_delegation` did not yet exist. Column ORDER is not part of the contract here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('generation_sessions', 'bot_author_id')) {
            Schema::table('generation_sessions', function (Blueprint $table) {
                // PROVENANCE only (nullable, INDEXED, NO cross-module FK — like `template_id`).
                $table->uuid('bot_author_id')->nullable()->index();
            });
        }

        if (!Schema::hasColumn('generation_sessions', 'bot_delegation')) {
            Schema::table('generation_sessions', function (Blueprint $table) {
                // The whole delegation overlay: {author, voice, snapshot_at, slot_values_before}.
                $table->json('bot_delegation')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['bot_delegation', 'bot_author_id'] as $column) {
            if (Schema::hasColumn('generation_sessions', $column)) {
                Schema::table('generation_sessions', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
