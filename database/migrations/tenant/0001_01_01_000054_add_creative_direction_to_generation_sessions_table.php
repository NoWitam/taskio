<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database (db_mode = own) mirror of the central add-creative-direction migration. Identical to
 * central — the column holds the run's derived creative direction (the shared frame every generation in the
 * session is made to) and carries no workspace_id. See the central migration for the rationale, the
 * derive-once/reuse lifecycle, and why it is additive rather than an edit of the create migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_sessions', function (Blueprint $table) {
            $table->json('creative_direction')->nullable()->after('bot_delegation');
        });
    }

    public function down(): void
    {
        Schema::table('generation_sessions', function (Blueprint $table) {
            $table->dropColumn('creative_direction');
        });
    }
};
