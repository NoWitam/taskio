<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database mirror: makes a knowledge entry able to be a DRAFT. See the central migration for why
 * this is one nullable column on `knowledge_entries` rather than a second table, and why it carries no
 * foreign key to the sessions table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->uuid('draft_session_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->dropColumn('draft_session_id');
        });
    }
};
