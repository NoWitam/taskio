<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database mirror: HOW a shadow draft changes its target, and WHERE an append goes.
 *
 * See the central migration for why an append stores the addition rather than a composed body — the
 * review card and the diff on one side, commutativity with a concurrent human edit on the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->string('amend_mode', 20)->nullable()->after('target_revision_id');
            $table->string('amend_section', 120)->nullable()->after('amend_mode');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->dropColumn(['amend_mode', 'amend_section']);
        });
    }
};
