<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database mirror: the NOTES a drafting run leaves about what the server did to the model's
 * answer. See the central migration for why a successful run still needs a place to say "this
 * proposal was degraded to an append, and here is why".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_draft_sessions', function (Blueprint $table) {
            $table->jsonb('notes')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_draft_sessions', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
