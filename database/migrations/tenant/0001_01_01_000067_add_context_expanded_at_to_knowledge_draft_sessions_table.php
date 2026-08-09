<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database mirror: records that a drafting session's context has already been expanded. See the
 * central migration for why the flag lives on the session rather than in the browser that pressed the
 * button, and why a refinement clears it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_draft_sessions', function (Blueprint $table) {
            $table->timestamp('context_expanded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_draft_sessions', function (Blueprint $table) {
            $table->dropColumn('context_expanded_at');
        });
    }
};
