<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database (db_mode = own) mirror of the central add-image-budget-counters migration. Identical to
 * central — the two counters hold the RUN's `ai_generate` / `ai_edit` provider-call tallies and carry no
 * workspace_id. See the central migration for why the per-run image budget had to leave instance state the
 * moment a storyboard run became many jobs, and why an integer column (a single guarded UPDATE) is the only
 * shape that survives N frame jobs reserving concurrently.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['ai_generate_calls', 'ai_edit_calls'] as $column) {
            if (!Schema::hasColumn('generation_sessions', $column)) {
                Schema::table('generation_sessions', function (Blueprint $table) use ($column) {
                    $table->unsignedInteger($column)->default(0);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['ai_edit_calls', 'ai_generate_calls'] as $column) {
            if (Schema::hasColumn('generation_sessions', $column)) {
                Schema::table('generation_sessions', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
