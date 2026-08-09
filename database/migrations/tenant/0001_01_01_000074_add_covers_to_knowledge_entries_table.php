<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The TENANT mirror of `knowledge_entries.covers` — see the central migration for what the column is
 * for and why it holds identifiers and nothing else.
 *
 * Mirrored because an own-database workspace runs this tree instead of the central one, and a column
 * that existed in only one of them would make the composer work for shared workspaces and fail for
 * own-database ones — the failure mode this pair of trees exists to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->jsonb('covers')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->dropColumn('covers');
        });
    }
};
